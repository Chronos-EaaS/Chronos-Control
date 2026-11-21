#!/usr/bin/env python3
"""Plan and execute a standalone gzip/Zstandard batch smoke study."""

import argparse
import fcntl
import hashlib
import json
import os
import platform
import shutil
import subprocess
import sys
import tempfile
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path

from adapters import ADAPTERS

SCHEMA_VERSION = 1
PROTOCOL = "compression-batch-v1"
CORPUS = "pattern-and-shake256-v1"
CHILD_ENV = {"LC_ALL": "C", "TZ": "UTC", "PATH": os.defpath}
IO_BLOCK = 64 * 1024
TIMING = {
    "clock": "time.perf_counter_ns",
    "start": "immediately before subprocess.Popen",
    "end": "after wait returns or spawn fails, before closing stdout/stderr files",
}


class StudyError(Exception):
    """An invalid study, execution request, or codec result."""


def now():
    return datetime.now(timezone.utc).isoformat()


def digest(data):
    return hashlib.sha256(data).hexdigest()


def identity(path):
    if not path.is_file():
        raise StudyError(f"Expected a regular file: {path}")
    sha = hashlib.sha256()
    size = 0
    with path.open("rb") as file:
        for block in iter(lambda: file.read(IO_BLOCK), b""):
            sha.update(block)
            size += len(block)
    return {"sha256": sha.hexdigest(), "sizeBytes": size}


def write_once(path, data):
    # Publish a complete file atomically. No concurrent writer can replace it.
    with tempfile.TemporaryDirectory(prefix=".write-", dir=path.parent) as temporary:
        staged = Path(temporary) / path.name
        staged.write_bytes(data)
        os.link(staged, path)


def write_json(path, data):
    write_once(path, (json.dumps(data, indent=2) + "\n").encode("utf-8"))


def generate_input(path, size, seed):
    pattern = b"synthetic|batch|chronos|2027\n"
    repeated = (pattern * (IO_BLOCK // len(pattern) + 1))[:IO_BLOCK // 2]
    with path.open("xb") as file:
        for offset in range(0, size, IO_BLOCK):
            block_size = min(IO_BLOCK, size - offset)
            # Seed and block ordinal use ASCII decimal for a portable byte recipe.
            key = f"{CORPUS}:{seed}:{offset // IO_BLOCK}".encode("ascii")
            noise = hashlib.shake_256(key).digest(IO_BLOCK // 2)
            file.write((repeated + noise)[:block_size])


def create_plan(args, *, expected_executable_sha256=None):
    root = args.study
    root.mkdir()
    input_path = root / "input.bin"
    generate_input(input_path, args.size_bytes, args.seed)
    codecs = {}
    repetitions = []
    for name in (ADAPTERS if args.codec == "both" else [args.codec]):
        adapter = ADAPTERS[name](getattr(args, f"{name}_level"))
        selector = getattr(args, f"{name}_executable")
        codecs[name] = {
            "adapterVersion": adapter.adapter_version,
            "parameters": adapter.parameters(),
            "executableSelector": str(Path(selector).absolute()) if "/" in selector else selector,
        }
    if expected_executable_sha256 is not None:
        codecs[args.codec]["executableSha256"] = expected_executable_sha256
    # Alternate codecs within each repetition index. The complete order is frozen here.
    for index in range(1, args.repetitions + 1):
        for name in codecs:
            repetitions.append({"repetitionId": f"{name}-{index:03d}", "codec": name})
    plan = {
        "schemaVersion": SCHEMA_VERSION,
        "protocol": PROTOCOL,
        "dataClass": "local-smoke",
        "studyId": str(uuid.uuid4()),
        "createdAt": now(),
        "input": {"generator": CORPUS, "seed": args.seed, **identity(input_path)},
        "codecs": codecs,
        "warmupsPerAttempt": args.warmups,
        "timeoutSecondsPerCommand": args.timeout_seconds,
        "childEnvironment": CHILD_ENV,
        "timing": TIMING,
        "repetitions": repetitions,
    }
    write_json(root / "plan.json", plan)
    write_once(root / "plan.sha256", (digest((root / "plan.json").read_bytes()) + "\n").encode())
    print(root / "plan.json")
    return 0


def read_plan(root):
    data = (root / "plan.json").read_bytes()
    sha = digest(data)
    if (root / "plan.sha256").read_text().strip() != sha:
        raise StudyError("Plan digest mismatch. Use a new study directory for a changed plan.")
    plan = json.loads(data)
    if plan.get("schemaVersion") != SCHEMA_VERSION or plan.get("protocol") != PROTOCOL:
        raise StudyError("Unsupported batch plan version.")
    return plan, sha


def run_process(argv, stdout, stderr, directory, plan, record):
    record.update({
        "argv": argv,
        "cwd": str(directory),
        "stdout": str(stdout.relative_to(directory)),
        "stderr": str(stderr.relative_to(directory)),
        "exitCode": None,
    })
    with stdout.open("xb") as output, stderr.open("xb") as log:
        started = time.perf_counter_ns()
        try:
            process = subprocess.Popen(
                argv, stdin=subprocess.DEVNULL, stdout=output, stderr=log,
                cwd=directory, env=plan["childEnvironment"],
            )
        except OSError as error:
            finished = time.perf_counter_ns()
            record.update(status="spawn-error", error=str(error))
        else:
            try:
                exit_code = process.wait(timeout=plan["timeoutSecondsPerCommand"])
                finished = time.perf_counter_ns()
                record.update(status="exited", exitCode=exit_code)
            except (subprocess.TimeoutExpired, KeyboardInterrupt) as error:
                process.kill()
                exit_code = process.wait()
                finished = time.perf_counter_ns()
                status = "timed-out" if isinstance(error, subprocess.TimeoutExpired) else "interrupted"
                record.update(status=status, exitCode=exit_code)
        record["elapsedNs"] = finished - started
    if record["status"] == "interrupted":
        raise KeyboardInterrupt
    if record["status"] != "exited" or record["exitCode"] != 0:
        detail = record.get("error") or record["status"]
        exit_code = record["exitCode"]
        raise StudyError(f"{Path(argv[0]).name}: {detail} (exit code {exit_code}). See {stderr.name}.")


def resolve_executable(selector, directory, plan, evidence, expected_sha256=None):
    evidence["selector"] = selector
    candidate = shutil.which(selector)
    if candidate is None:
        raise StudyError(f"Executable missing or not runnable: {selector}. Install it or plan with an executable path.")
    # Preserve the basename used by the caller, since some tools dispatch on argv[0].
    executable = Path(candidate).absolute()
    evidence["identity"] = executable_identity(executable)
    if expected_sha256 is not None and evidence["identity"]["sha256"] != expected_sha256:
        raise StudyError("Codec executable digest differs from the plan.")
    version_stdout = directory / "version.stdout.log"
    version_stderr = directory / "version.stderr.log"
    probe = evidence["versionProbe"] = {}
    try:
        run_process([str(executable), "--version"], version_stdout, version_stderr, directory, plan, probe)
    finally:
        evidence["versionBanner"] = version_banner(version_stdout, version_stderr)
    if not evidence["versionBanner"]:
        raise StudyError(f"{selector} returned no version banner.")
    return executable


def executable_identity(path):
    resolved = path.resolve(strict=True)
    return {"invokedPath": str(path), "resolvedPath": str(resolved), **identity(resolved)}


def version_banner(*paths):
    for path in paths:
        if path.exists():
            lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
            for line in lines:
                if line.strip():
                    return line.strip()
    return None


def bytes_match(left, right):
    with left.open("rb") as original, right.open("rb") as restored:
        for block in iter(lambda: original.read(IO_BLOCK), b""):
            if restored.read(IO_BLOCK) != block:
                return False
        return not restored.read(1)


def run_sample(adapter, executable, input_path, expected, directory, plan, sample, inject=False):
    label = sample["label"]
    archive = directory / f"{label}{adapter.suffix}"
    sample["compression"] = {}
    try:
        run_process(
            adapter.compress(executable, input_path), archive,
            directory / f"{label}.compression.stderr.log", directory, plan, sample["compression"],
        )
    finally:
        if archive.is_file():
            sample["output"] = {"path": archive.name, **identity(archive)}
    if inject:
        sample["failureInjection"] = {"kind": "flip-first-byte", "before": sample["output"]}
        with archive.open("r+b") as file:
            first = file.read(1)
            if not first:
                raise StudyError("Cannot inject a byte flip into an empty archive.")
            file.seek(0)
            file.write(bytes([first[0] ^ 0xff]))
        sample["output"] = {"path": archive.name, **identity(archive)}
    started = time.perf_counter_ns()
    validation = sample["validation"] = {"status": "failed", "decompression": {}}
    try:
        with tempfile.TemporaryDirectory(prefix="restore-", dir=directory) as temporary:
            restored = Path(temporary) / "restored.bin"
            try:
                run_process(
                    adapter.decompress(executable, archive), restored,
                    directory / f"{label}.decompression.stderr.log", directory, plan, validation["decompression"],
                )
            finally:
                if restored.is_file():
                    validation["restored"] = identity(restored)
            validation["byteMatch"] = bytes_match(input_path, restored)
            validation["identityMatch"] = validation["restored"] == expected
            with archive.open("rb") as file:
                validation["formatMagicMatch"] = file.read(len(adapter.magic)) == adapter.magic
            if not all(validation[key] for key in ("byteMatch", "identityMatch", "formatMagicMatch")):
                raise StudyError(f"{label}: archive format or decompressed bytes differ from the plan.")
        validation["status"] = "succeeded"
    finally:
        validation["elapsedNs"] = time.perf_counter_ns() - started


def execute_attempt(root, directory, plan, plan_sha, repetition, retry_of, inject):
    name = repetition["codec"]
    configuration = plan["codecs"][name]
    adapter = ADAPTERS[name](configuration["parameters"]["level"])
    attempt = {
        "schemaVersion": SCHEMA_VERSION,
        "protocol": PROTOCOL,
        "dataClass": plan["dataClass"],
        "studyId": plan["studyId"],
        "planSha256": plan_sha,
        **repetition,
        "attemptId": directory.name,
        "retryOf": retry_of,
        "adapterVersion": adapter.adapter_version,
        "parameters": adapter.parameters(),
        "requestedFailureInjection": "flip-first-byte" if inject else None,
        "startedAt": now(),
    }
    write_json(directory / "attempt.json", attempt)
    result = {
        **attempt,
        "environment": {
            "system": platform.system(),
            "release": platform.release(),
            "architecture": platform.machine(),
            "python": platform.python_version(),
            "logicalCpuCount": os.cpu_count(),
        },
        "setup": {"status": "failed", "executable": {}},
        "warmups": [],
        "measurement": None,
        "status": "failed",
    }
    setup = result["setup"]
    input_path = root / "input.bin"
    expected = {key: plan["input"][key] for key in ("sha256", "sizeBytes")}
    try:
        started = time.perf_counter_ns()
        try:
            if (
                adapter.adapter_version != configuration["adapterVersion"]
                or adapter.parameters() != configuration["parameters"]
            ):
                raise StudyError("The installed driver does not implement the planned adapter parameters.")
            setup["input"] = {"path": str(input_path), **identity(input_path)}
            if any(setup["input"][key] != value for key, value in expected.items()):
                raise StudyError("Input identity differs from the plan.")
            executable = resolve_executable(
                configuration["executableSelector"], directory, plan, setup["executable"],
                configuration.get("executableSha256"),
            )
            setup["status"] = "succeeded"
        finally:
            setup["elapsedNs"] = time.perf_counter_ns() - started
        for index in range(1, plan["warmupsPerAttempt"] + 1):
            sample = {"label": f"warmup-{index:03d}"}
            result["warmups"].append(sample)
            run_sample(adapter, executable, input_path, expected, directory, plan, sample)
        sample = result["measurement"] = {"label": "measurement"}
        run_sample(adapter, executable, input_path, expected, directory, plan, sample, inject)
        result["observedAfter"] = {
            "input": identity(input_path), "executable": executable_identity(executable),
        }
        if result["observedAfter"] != {"input": expected, "executable": setup["executable"]["identity"]}:
            raise StudyError("Input or executable identity changed during the attempt.")
        result["status"] = "succeeded"
    except (OSError, StudyError) as error:
        result["error"] = str(error)
    except KeyboardInterrupt:
        result.update(status="interrupted", error="Interrupted by operator.")
    result["finishedAt"] = now()
    write_json(directory / "result.json", result)
    return result


def run_study(args):
    root = args.study.resolve()
    plan, sha = read_plan(root)
    selected = [
        item for item in plan["repetitions"]
        if args.repetition is None or item["repetitionId"] == args.repetition
    ]
    if not selected:
        raise StudyError(f"Unknown repetition: {args.repetition}")
    if (args.retry or args.inject_corruption) and not args.repetition:
        raise StudyError("--retry and --inject-corruption each require --repetition.")

    failed = False
    with (root / ".run.lock").open("a") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise StudyError("Another runner holds the study lock.") from error
        for repetition in selected:
            parent = root / "attempts" / repetition["repetitionId"]
            parent.mkdir(parents=True, exist_ok=True)
            attempts = {
                int(path.name.removeprefix("attempt-")): path
                for path in parent.glob("attempt-*")
            }
            last_number = max(attempts, default=0)
            previous = attempts.get(last_number)
            if previous:
                status = "incomplete"
                for evidence_path in (previous / "attempt.json", previous / "result.json"):
                    if evidence_path.is_file():
                        evidence = json.loads(evidence_path.read_text())
                        if evidence["planSha256"] != sha:
                            raise StudyError(f"Existing attempt refers to a different plan: {previous}")
                        status = evidence.get("status", status)
                if not args.retry:
                    print(f"{repetition['repetitionId']} {previous.name}: already attempted ({status})")
                    failed |= status != "succeeded"
                    continue
                if status == "succeeded":
                    raise StudyError("The latest attempt succeeded. Plan a new repetition for another measurement.")
            elif args.retry:
                raise StudyError("Retry requires an existing attempt.")
            directory = parent / f"attempt-{last_number + 1:06d}"
            directory.mkdir()
            result = execute_attempt(
                root, directory, plan, sha, repetition, previous.name if previous else None, args.inject_corruption,
            )
            print(f"{repetition['repetitionId']} {directory.name}: {result['status']} ({directory / 'result.json'})")
            if "error" in result:
                print(result["error"], file=sys.stderr)
            if result["status"] == "interrupted":
                return 130
            failed |= result["status"] != "succeeded"
    return int(failed)


def integer_at_least(minimum, maximum=None):
    def parse(value):
        number = int(value)
        if number < minimum or (maximum is not None and number > maximum):
            bounds = f"{minimum}..{maximum}" if maximum is not None else f">= {minimum}"
            raise argparse.ArgumentTypeError(f"expected an integer in {bounds}")
        return number
    return parse


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    plan = commands.add_parser("plan", help="create a new immutable local smoke plan and synthetic input")
    plan.add_argument("study", type=Path, help="new directory with an existing parent")
    plan.add_argument("--codec", choices=("both", *ADAPTERS), default="both")
    plan.add_argument("--size-bytes", type=integer_at_least(1), default=1024 * 1024)
    plan.add_argument("--seed", type=int, default=2027)
    plan.add_argument("--warmups", type=integer_at_least(0), default=1, help="per attempt")
    plan.add_argument("--repetitions", type=integer_at_least(1), default=2, help="per codec")
    plan.add_argument("--timeout-seconds", type=integer_at_least(1), default=30, help="per subprocess")
    for name, adapter in ADAPTERS.items():
        plan.add_argument(f"--{name}-level", type=integer_at_least(1, adapter.max_level),
                          default={"gzip": 6, "zstd": 3}[name])
        plan.add_argument(f"--{name}-executable", default=name, help="executable on PATH or explicit local path")
    plan.set_defaults(handler=create_plan)

    run = commands.add_parser("run", help="execute planned repetitions and retain each attempt")
    run.add_argument("study", type=Path)
    run.add_argument("--repetition", help="run just this planned repetition")
    run.add_argument("--retry", action="store_true", help="append an attempt after a failure or interruption")
    run.add_argument("--inject-corruption", action="store_true", help="flip the first byte of the measured archive")
    run.set_defaults(handler=run_study)
    args = parser.parse_args(argv)
    try:
        return args.handler(args)
    except (OSError, StudyError, ValueError) as error:
        print(f"batch: {error}", file=sys.stderr)
        return 2
    except KeyboardInterrupt:
        return 130


if __name__ == "__main__":
    sys.exit(main())
