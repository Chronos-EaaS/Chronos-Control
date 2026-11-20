#!/usr/bin/env python3
"""Run an isolated local PostgreSQL/MariaDB sysbench study."""

import argparse
from dataclasses import dataclass
from datetime import datetime, timezone
import hashlib
import json
import math
import os
from pathlib import Path
import platform
import re
import signal
import subprocess
import sys
import time
from uuid import uuid4

from adapters import ADAPTERS, HOST

HERE = Path(__file__).resolve().parent
PROTOCOL = "sysbench-oltp-read-only-v1"
CLIENT_IMAGE = "chronos-study-sysbench:1.0.20-bookworm"
RESOURCE_LABEL = "org.chronos.studies.database.attempt"
CPU_LIMIT = "2"
MEMORY_LIMIT = "1g"
WORKLOAD = "/usr/share/sysbench/oltp_read_only.lua"
WORKLOAD_ARGS = [
    "--rand-type=uniform", "--db-ps-mode=auto", "--auto-inc=off", "--create-secondary=on",
    "--point-selects=10", "--range-selects=on", "--range-size=100", "--simple-ranges=1",
    "--sum-ranges=1", "--order-ranges=1", "--distinct-ranges=1", "--skip-trx=off",
]
IMAGE_REF = re.compile(r"[A-Za-z0-9][A-Za-z0-9._/:@-]{0,254}")
RESOURCE_ID = re.compile(r"[a-f0-9]{64}")


class StudyError(Exception):
    pass


class Cancelled(StudyError):
    pass


def utc_now():
    return datetime.now(timezone.utc).isoformat()


def write_json(path, value):
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(value, indent=2, allow_nan=False) + "\n", encoding="utf-8")
    temporary.replace(path)


@dataclass
class Cancellation:
    signal_number: int | None = None

    def receive(self, signum, _frame):
        if self.signal_number is None:
            self.signal_number = signum

    def check(self):
        if self.signal_number is not None:
            raise Cancelled(signal.Signals(self.signal_number).name)


@dataclass(frozen=True)
class Command:
    directory: Path
    record: dict

    @property
    def ok(self):
        return self.record["status"] == "exited" and self.record["exitCode"] == 0

    def output(self):
        return (self.directory / self.record["stdout"]).read_text(
            encoding="utf-8", errors="replace"
        )

    def require_success(self):
        if not self.ok:
            raise StudyError(
                f"{self.record['phase']}: {self.record['status']} "
                f"(exit {self.record['exitCode']}, see {self.record['stderr']})"
            )
        return self


class Recorder:
    def __init__(self, directory, cancellation):
        self.directory = directory
        self.cancellation = cancellation
        self.sequence = 0

    def call(self, phase, argv, *, timeout=30, settle_on_cancel=False, teardown=False):
        if not teardown:
            self.cancellation.check()
        self.sequence += 1
        stem = f"{self.sequence:03d}-{phase}"
        stdout = f"{stem}.stdout.log"
        stderr = f"{stem}.stderr.log"
        started = time.monotonic()
        record = {
            "phase": phase, "argv": argv, "startedAt": utc_now(), "timeoutSeconds": timeout,
            "stdout": stdout, "stderr": stderr, "exitCode": None,
        }
        env = {key: value for key, value in os.environ.items() if key not in (
            "DOCKER_CONTEXT", "DOCKER_HOST", "DOCKER_TLS", "DOCKER_TLS_VERIFY", "DOCKER_CERT_PATH",
        )}
        env["LC_ALL"] = "C"
        with (self.directory / stdout).open("wb") as out, (self.directory / stderr).open("wb") as err:
            try:
                process = subprocess.Popen(
                    argv, stdin=subprocess.DEVNULL, stdout=out, stderr=err,
                    env=env, start_new_session=True,
                )
            except OSError as error:
                record.update(status="spawn-error", error=str(error))
            else:
                deadline = started + timeout
                while True:
                    remaining = deadline - time.monotonic()
                    cancelled = not teardown and not settle_on_cancel and self.cancellation.signal_number is not None
                    if cancelled or remaining <= 0:
                        record["status"] = "cancelled" if cancelled else "timed-out"
                        process.kill()
                        record["exitCode"] = process.wait()
                        break
                    try:
                        record.update(status="exited", exitCode=process.wait(timeout=min(0.25, remaining)))
                        break
                    except subprocess.TimeoutExpired:
                        continue
        record["wallSeconds"] = time.monotonic() - started
        record["finishedAt"] = utc_now()
        write_json(self.directory / f"{stem}.json", record)
        if not teardown:
            self.cancellation.check()
        return Command(self.directory, record)


def image_ref(value):
    if not IMAGE_REF.fullmatch(value):
        raise argparse.ArgumentTypeError("expected a Docker tag, digest reference, or image ID")
    return value


def bounded(low, high):
    def parse(text):
        try:
            value = int(text)
        except ValueError:
            raise argparse.ArgumentTypeError("expected an integer") from None
        if not low <= value <= high:
            raise argparse.ArgumentTypeError(f"expected a value between {low} and {high}")
        return value
    return parse


def parse_args(argv=None):
    desktop_socket = Path.home() / ".docker/run/docker.sock"
    default_socket = desktop_socket if sys.platform == "darwin" and desktop_socket.is_socket() else Path(
        "/var/run/docker.sock"
    )
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--system", choices=["both", *ADAPTERS], default="both")
    parser.add_argument("--tables", type=bounded(1, 32), default=1)
    parser.add_argument("--rows", type=bounded(1, 10_000_000), default=10_000, help="rows per table")
    parser.add_argument("--threads", type=bounded(1, 64), default=2)
    parser.add_argument("--duration", type=bounded(1, 3600), default=10, help="seconds per measurement")
    parser.add_argument("--warmup", type=bounded(0, 3600), default=3, help="seconds; 0 skips warmup")
    parser.add_argument("--repetitions", type=bounded(1, 100), default=1, help="planned runs per system")
    parser.add_argument("--retries", type=bounded(0, 5), default=0, help="additional clean attempts per repetition")
    parser.add_argument("--seed", type=bounded(1, 2**31 - 1), default=20271120)
    parser.add_argument("--setup-timeout", type=bounded(5, 3600), default=300, help="per setup phase, in seconds")
    parser.add_argument("--docker-socket", type=Path, default=default_socket, help="local Unix socket path")
    parser.add_argument("--client-image", type=image_ref, default=CLIENT_IMAGE)
    for system, adapter in ADAPTERS.items():
        parser.add_argument(f"--{system}-image", type=image_ref, default=adapter.default_image)
    parser.add_argument("--output", type=Path, help="new directory for result evidence")
    parser.add_argument("--dry-run", action="store_true", help="print plan and workload commands without invoking Docker")
    args = parser.parse_args(argv)
    if not args.docker_socket.is_absolute():
        parser.error("--docker-socket must be an absolute local Unix socket path")
    return args


def workload_command(adapter, args, phase):
    command = ["sysbench", WORKLOAD, *adapter.connection_args(), *WORKLOAD_ARGS,
               f"--tables={args.tables}", f"--table-size={args.rows}", f"--rand-seed={args.seed}"]
    if phase == "prepare":
        return command + ["--threads=1", "prepare"]
    seconds = args.duration if phase == "measure" else args.warmup
    return command + [f"--threads={args.threads}", f"--time={seconds}", "--events=0",
                      "--percentile=95", "--report-interval=1", "run"]


def plan(args):
    systems = list(ADAPTERS) if args.system == "both" else [args.system]
    phases = ["prepare", "warmup", "measure"] if args.warmup else ["prepare", "measure"]
    return {
        "schemaVersion": 1,
        "protocol": PROTOCOL,
        "parameters": {
            "tables": args.tables, "rowsPerTable": args.rows, "threads": args.threads,
            "measureSeconds": args.duration, "warmupSeconds": args.warmup, "seed": args.seed,
            "attemptLimit": 1 + args.retries, "setupTimeoutSeconds": args.setup_timeout,
        },
        "resourcesPerContainer": {"cpus": CPU_LIMIT, "memory": MEMORY_LIMIT},
        "images": {"client": args.client_image, **{system: getattr(args, f"{system}_image") for system in systems}},
        "repetitions": [
            {"id": f"{index:03d}-{system}", "round": index, "system": system}
            for index in range(1, args.repetitions + 1)
            for system in (systems if index % 2 else reversed(systems))
        ],
        "workloadCommands": {
            system: {phase: workload_command(ADAPTERS[system], args, phase) for phase in phases}
            for system in systems
        },
    }


def digest_file(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def resolve_images(recorder, docker, refs):
    images = {}
    fields = ('{"id":{{json .Id}},"repoDigests":{{json .RepoDigests}},'
              '"os":{{json .Os}},"architecture":{{json .Architecture}}}')
    for role, ref in refs.items():
        inspected = recorder.call(
            f"image-{role}", docker + ["image", "inspect", "--format", fields, "--", ref],
        ).require_success()
        observed = json.loads(inspected.output())
        if not re.fullmatch(r"sha256:[a-f0-9]{64}", observed["id"]) or observed["os"] != "linux":
            raise StudyError(f"expected an inspectable Linux image for {role}")
        if re.fullmatch(r"sha256:[a-f0-9]{64}", ref) and observed["id"] != ref:
            raise StudyError(f"resolved image ID differs from the plan for {role}")
        images[role] = {"requested": ref, **observed}
    return images


def parse_summary(raw, duration):
    if re.search(r"^\s*(?:FATAL|ERROR):", raw, re.MULTILINE):
        raise StudyError("sysbench reported a fatal error")
    if raw.count("SQL statistics:") != 1 or raw.count("Threads fairness:") != 1:
        raise StudyError("missing or repeated sysbench summary")
    summary = raw.split("SQL statistics:")[1].split("Threads fairness:")[0]

    def number(label, pattern, convert):
        matches = re.findall(
            rf"^[ \t]*{re.escape(label)}:[ \t]+({pattern})(?:s|[ \t]+\([^\n]*\))?[ \t]*$",
            summary, re.MULTILINE,
        )
        if len(matches) != 1:
            raise StudyError(f"missing or ambiguous sysbench statistic: {label}")
        value = convert(matches[0])
        if isinstance(value, float) and not math.isfinite(value):
            raise StudyError(f"invalid sysbench statistic: {label}")
        return value

    counts = {
        "transactions": number("transactions", r"\d+", int),
        "events": number("total number of events", r"\d+", int),
        "readQueries": number("read", r"\d+", int),
        "writeQueries": number("write", r"\d+", int),
        "otherQueries": number("other", r"\d+", int),
        "queries": number("queries", r"\d+", int),
        "ignoredErrors": number("ignored errors", r"\d+", int),
        "reconnects": number("reconnects", r"\d+", int),
    }
    elapsed = number("total time", r"\d+\.\d+", float)
    latency = {name: number(label, r"\d+\.\d+", float) for name, label in
               (("min", "min"), ("mean", "avg"), ("max", "max"), ("p95", "95th percentile"))}
    transactions = counts["transactions"]
    if (transactions < 1 or counts["events"] != transactions or counts["ignoredErrors"]
            or counts["reconnects"] or counts["writeQueries"]
            or counts["readQueries"] != 14 * transactions or counts["otherQueries"] != 2 * transactions
            or counts["queries"] != 16 * transactions
            or number("total", r"\d+", int) != counts["queries"]):
        raise StudyError(f"unexpected read-only workload counters: {counts}")
    if not duration * 0.98 <= elapsed <= duration + 2:
        raise StudyError(f"unexpected elapsed time: {elapsed}s for {duration}s requested")
    if not 0 <= latency["min"] <= latency["mean"] <= latency["max"] or latency["p95"] <= 0:
        raise StudyError(f"invalid latency summary: {latency}")
    return {**counts, "sysbenchSeconds": elapsed, "transactionsPerSecond": transactions / elapsed,
            "latencyMilliseconds": latency}


def wait_ready(sql, timeout):
    deadline = time.monotonic() + timeout
    remaining = deadline - time.monotonic()
    while remaining > 0:
        result = sql("readiness", "SELECT 1;", timeout=min(5, remaining))
        if result.ok and result.output().strip() == "1":
            return
        time.sleep(max(0, min(1, deadline - time.monotonic())))
        remaining = deadline - time.monotonic()
    raise StudyError("database did not accept an authenticated TCP query before the readiness deadline")


def check_dataset(recorder, docker, database, adapter, args, stage):
    tables = []
    prefix = docker + ["exec", database]
    for index in range(1, args.tables + 1):
        table = f"sbtest{index}"
        stats_query = f"SELECT {index}, COUNT(*), MIN(id), MAX(id) FROM {table};"
        observed = recorder.call(
            f"{stage}-count-{index}", prefix + adapter.sql(stats_query), timeout=args.setup_timeout,
        ).require_success().output().strip()
        if observed != f"{index}\t{args.rows}\t1\t{args.rows}":
            raise StudyError(f"{stage}: invalid row count or key bounds for {table}: {observed!r}")
        digest_query = f"SELECT {index}, id, k, RTRIM(c), RTRIM(pad) FROM {table} ORDER BY id;"
        fingerprint = recorder.call(
            f"{stage}-sha256-{index}",
            prefix + ["bash", "-o", "pipefail", "-c", '"$@" | sha256sum', "digest", *adapter.sql(digest_query)],
            timeout=args.setup_timeout,
        ).require_success().output()
        if not re.fullmatch(r"[a-f0-9]{64}  -\n", fingerprint):
            raise StudyError(f"{stage}: invalid SHA-256 output for {table}")
        tables.append({"table": table, "rows": args.rows, "sha256": fingerprint[:64]})
    return tables


def teardown_resources(recorder, docker, label, database):
    errors = []

    def call(phase, command):
        result = recorder.call(phase, docker + command, teardown=True)
        if not result.ok:
            errors.append(f"{phase}: {result.record['status']} (exit {result.record['exitCode']})")
        return result

    def list_owned(kind, stage):
        operation = ["ps", "-a"] if kind == "containers" else ["network", "ls"]
        result = call(f"{stage}-{kind}", [*operation, "-q", "--no-trunc", "--filter", f"label={label}"])
        if not result.ok:
            return []
        ids = result.output().split()
        if any(not RESOURCE_ID.fullmatch(item) for item in ids):
            errors.append(f"{stage}-{kind}: unexpected Docker resource ID")
            return []
        return ids

    containers = list_owned("containers", "find")
    if containers:
        # The DB name is unique, but verify it matches an owned ID before reading its logs.
        identity = recorder.call(
            "find-database", docker + ["container", "inspect", "--format", "{{.Id}}", database], teardown=True,
        )
        if identity.ok and identity.output().strip() in containers:
            call("database-logs", ["logs", "--timestamps", identity.output().strip()])
        call("remove-containers", ["rm", "-fv", *containers])
    networks = list_owned("networks", "find")
    if networks:
        call("remove-networks", ["network", "rm", *networks])
    remaining_containers = list_owned("containers", "verify")
    remaining_networks = list_owned("networks", "verify")
    if remaining_containers or remaining_networks:
        errors.append("owned Docker resources remain after teardown")
    return errors


def run_attempt(directory, docker, info, repetition, attempt_number, args, cancellation):
    directory.mkdir()
    recorder = Recorder(directory, cancellation)
    system = repetition["system"]
    adapter = ADAPTERS[system]
    token = uuid4().hex
    name = f"chronos-study-db-{token}"
    label = f"{RESOURCE_LABEL}={token}"
    database = f"{name}-db"
    client = f"{name}-client"
    network = f"{name}-net"
    attempt = {
        "schemaVersion": 1, "studyId": info["id"], "repetitionId": repetition["id"],
        "attemptId": directory.name, "attemptNumber": attempt_number, "system": system,
        "startedAt": utc_now(), "status": "running", "eligible": False,
        "dockerOwnershipLabel": label,
        "images": {role: info["images"][role] for role in ("client", system)},
    }
    write_json(directory / "attempt.json", attempt)

    def call(phase, command, timeout=30, *, provisions=False):
        return recorder.call(
            phase, docker + command, timeout=timeout, settle_on_cancel=provisions,
        ).require_success()

    def sql(phase, query, *, timeout=30):
        return recorder.call(phase, docker + ["exec", database, *adapter.sql(query)], timeout=timeout)

    container_options = [
        "run", "-d", "--pull=never", "--network", network, "--label", label,
        "--cpus", CPU_LIMIT, "--memory", MEMORY_LIMIT, "--restart=no", "--env", "TZ=UTC",
    ]
    cleanup_eligible = False
    try:
        cancellation.check()
        cleanup_eligible = True
        call("create-network", [
            "network", "create", "--driver", "bridge", "--internal", "--label", label, network,
        ], provisions=True)
        database_env = [argument for value in adapter.environment for argument in ("--env", value)]
        call("start-database", container_options + [
            "--name", database, "--network-alias", HOST, *database_env, info["images"][system]["id"],
        ], provisions=True)
        call("start-client", container_options + [
            "--name", client, "--entrypoint", "sleep", info["images"]["client"]["id"], "infinity",
        ], provisions=True)
        inspection = ('{"id":{{json .Id}},"image":{{json .Image}},"mounts":{{json .Mounts}},'
                      '"portBindings":{{json .HostConfig.PortBindings}}}')
        for role, container in (("database", database), ("client", client)):
            call(f"{role}-container", ["container", "inspect", "--format", inspection, container])
        wait_ready(sql, args.setup_timeout)
        attempt["serverVersion"] = sql("server-version", adapter.version_query).require_success().output().strip()
        if not attempt["serverVersion"]:
            raise StudyError("database reported an empty server version")
        sql("server-settings", adapter.settings_query).require_success()

        client_exec = ["exec", "--env", "LC_ALL=C", client]
        version = call("sysbench-version", client_exec + ["sysbench", "--version"]).output().strip()
        attempt["sysbenchVersion"] = version
        if not re.match(r"^sysbench 1\.0\.20(?:\s|$)", version):
            raise StudyError(f"unexpected sysbench version: {version}")
        help_output = call("sysbench-help", client_exec + ["sysbench", "--help"]).output()
        drivers = help_output.partition("Compiled-in database drivers:")[2].split("\n\n")[0]
        if not all(re.search(rf"^\s*{driver}\s+-", drivers, re.MULTILINE) for driver in ("mysql", "pgsql")):
            raise StudyError("sysbench must provide both mysql and pgsql drivers (see sysbench-help)")
        call("client-packages", client_exec + ["dpkg-query", "-W"])
        call("workload-sha256", client_exec + ["sha256sum", WORKLOAD, "/usr/share/sysbench/oltp_common.lua"])
        commands = info["plan"]["workloadCommands"][system]
        call("prepare", client_exec + commands["prepare"], timeout=args.setup_timeout)
        attempt["datasetBefore"] = check_dataset(recorder, docker, database, adapter, args, "before")
        write_json(directory / "attempt.json", attempt)
        if "warmup" in commands:
            warmup = call("warmup", client_exec + commands["warmup"], timeout=args.warmup + 60)
            parse_result(warmup, args.warmup)
        measurement = call("measure", client_exec + commands["measure"], timeout=args.duration + 60)
        attempt["measurement"] = {
            "summary": parse_result(measurement, args.duration),
            "processWallSeconds": measurement.record["wallSeconds"],
            "stdout": measurement.record["stdout"], "stderr": measurement.record["stderr"],
        }
        write_json(directory / "attempt.json", attempt)
        attempt["datasetAfter"] = check_dataset(recorder, docker, database, adapter, args, "after")
        if attempt["datasetBefore"] != attempt["datasetAfter"]:
            raise StudyError("dataset fingerprint changed during the read-only workload")
        attempt["status"] = "succeeded"
    except Cancelled as error:
        attempt.update(status="cancelled", error=str(error))
    except (StudyError, OSError) as error:
        attempt.update(status="failed", error=str(error))
    finally:
        attempt["teardownErrors"] = teardown_resources(recorder, docker, label, database) if cleanup_eligible else []
        if cancellation.signal_number is not None and attempt["status"] != "cancelled":
            attempt.update(status="cancelled", error=signal.Signals(cancellation.signal_number).name)
        if attempt["status"] == "succeeded" and attempt["teardownErrors"]:
            attempt.update(status="failed", error="teardown or teardown evidence collection failed")
        attempt["eligible"] = attempt["status"] == "succeeded"
        attempt["finishedAt"] = utc_now()
        write_json(directory / "attempt.json", attempt)
    return attempt


def parse_result(command, duration):
    stdout = command.output()
    stderr = (command.directory / command.record["stderr"]).read_text(encoding="utf-8", errors="replace")
    if re.search(r"^\s*(?:FATAL|ERROR):", stderr, re.MULTILINE):
        raise StudyError("sysbench reported a fatal error on stderr")
    return parse_summary(stdout, duration)


def run_study(directory, study_plan, args, cancellation, *, source_paths=None, docker_executable="docker"):
    if source_paths is None:
        source_paths = {name: HERE / name for name in ("benchmark.py", "adapters.py", "Dockerfile")}
    directory.mkdir(parents=True)
    preflight = directory / "preflight"
    preflight.mkdir()
    recorder = Recorder(preflight, cancellation)
    repetitions = [{**item, "status": "planned", "attempts": []} for item in study_plan["repetitions"]]
    info = {
        "schemaVersion": 1, "id": uuid4().hex, "status": "running", "startedAt": utc_now(),
        "plan": study_plan, "repetitions": repetitions,
        "sourceSha256": {name: digest_file(path) for name, path in source_paths.items()},
        "host": {"os": platform.system(), "release": platform.release(), "architecture": platform.machine(),
                 "logicalCpus": os.cpu_count(), "python": platform.python_version()},
    }
    manifest = directory / "run.json"
    write_json(manifest, info)
    try:
        if not args.docker_socket.is_socket():
            raise StudyError(f"local Docker Unix socket not found: {args.docker_socket}")
        docker = [docker_executable, "--host", f"unix://{args.docker_socket.resolve()}"]
        version = recorder.call("docker-version", docker + ["version", "--format", "{{json .}}"]).require_success()
        info["dockerVersion"] = json.loads(version.output())
        fields = ('{"os":{{json .OSType}},"architecture":{{json .Architecture}},"cpus":{{json .NCPU}},'
                  '"memoryBytes":{{json .MemTotal}},"storageDriver":{{json .Driver}},'
                  '"kernel":{{json .KernelVersion}},"operatingSystem":{{json .OperatingSystem}}}')
        engine = recorder.call("docker-info", docker + ["info", "--format", fields]).require_success()
        info["dockerEngine"] = json.loads(engine.output())
        if info["dockerEngine"]["os"] != "linux":
            raise StudyError("this study needs a local Docker CLI connected to a Linux Docker daemon")
        info["images"] = resolve_images(recorder, docker, study_plan["images"])
        architecture = info["dockerEngine"]["architecture"]
        architecture = {"aarch64": "arm64", "x86_64": "amd64"}.get(architecture, architecture)
        if any(image["architecture"] != architecture for image in info["images"].values()):
            raise StudyError(f"use native {architecture} images for all study containers")
        write_json(manifest, info)
        for repetition in repetitions:
            cancellation.check()
            repetition_dir = directory / repetition["id"]
            repetition_dir.mkdir()
            for number in range(1, study_plan["parameters"]["attemptLimit"] + 1):
                attempt_dir = repetition_dir / f"attempt-{number:03d}"
                evidence = str((attempt_dir / "attempt.json").relative_to(directory))
                entry = {"id": attempt_dir.name, "evidence": evidence, "status": "running", "eligible": False}
                repetition["attempts"].append(entry)
                repetition["status"] = "running"
                write_json(manifest, info)
                print(f"{repetition['id']} / {attempt_dir.name}", file=sys.stderr)
                attempt = run_attempt(attempt_dir, docker, info, repetition, number, args, cancellation)
                entry.update(status=attempt["status"], eligible=attempt["eligible"])
                repetition["status"] = attempt["status"]
                write_json(manifest, info)
                cancellation.check()
                if attempt["teardownErrors"]:
                    raise StudyError(f"teardown failed for {evidence}; inspect its recorded ownership label")
                if attempt["eligible"]:
                    break
        info["status"] = "succeeded" if all(rep["status"] == "succeeded" for rep in repetitions) else "failed"
    except Cancelled as error:
        info.update(status="cancelled", error=str(error))
    except (StudyError, OSError, ValueError, KeyError) as error:
        info.update(status="failed", error=str(error))
    finally:
        info["finishedAt"] = utc_now()
        write_json(manifest, info)
    return info


def main(argv=None):
    args = parse_args(argv)
    study_plan = plan(args)
    if args.dry_run:
        print(json.dumps(study_plan, indent=2))
        return 0
    directory = args.output or HERE / "results" / f"study-{uuid4().hex}"
    cancellation = Cancellation()
    signals = (signal.SIGINT, signal.SIGTERM)
    previous = {signum: signal.signal(signum, cancellation.receive) for signum in signals}
    try:
        info = run_study(directory, study_plan, args, cancellation)
    except OSError as error:
        print(f"Cannot create or write study evidence in {directory}: {error}", file=sys.stderr)
        return 1
    finally:
        for signum, handler in previous.items():
            signal.signal(signum, handler)
    print(f"{info['status']}: {directory.absolute() / 'run.json'}", file=sys.stderr)
    if info.get("error"):
        print(info["error"], file=sys.stderr)
    if cancellation.signal_number is not None:
        return 128 + cancellation.signal_number
    return 0 if info["status"] == "succeeded" else 1


if __name__ == "__main__":
    sys.exit(main())
