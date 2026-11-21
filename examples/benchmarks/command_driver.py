"""Executable body for the staged chronos-command@1 driver."""

from argparse import Namespace
from contextlib import redirect_stdout
from importlib.machinery import SourceFileLoader
from importlib.util import module_from_spec, spec_from_loader
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import sys


BATCH = "lossless-compression"
DATABASE = "sysbench-oltp-read-only"
WARMUP_EXCLUDED = 2
MAX_REQUEST_BYTES = 2 * 1024 * 1024


class DriverError(Exception):
    pass


def integer(values, name, minimum, maximum):
    value = values.get(name)
    if type(value) is not int or not minimum <= value <= maximum:
        raise DriverError(f"{name} must be an integer between {minimum} and {maximum}.")
    return value


def sha256(value):
    if not isinstance(value, str) or not re.fullmatch(r"[a-f0-9]{64}", value):
        raise DriverError("Expected a frozen SHA-256 digest.")
    return value


def image_id(value):
    if not isinstance(value, str) or not value.startswith("sha256:"):
        raise DriverError("Expected a frozen Docker image ID.")
    sha256(value.removeprefix("sha256:"))
    return value


def absolute_path(value):
    if not isinstance(value, str) or not Path(value).is_absolute() or "\x00" in value:
        raise DriverError("Expected an absolute worker path.")
    return value


def warmup(values, name, maximum, phases):
    value = integer(values, name, 0, maximum)
    if bool(value) != (phases == 0):
        raise DriverError(f"{name} must match the experiment's warm-up setting.")
    return value


def load_module(name, path):
    loader = SourceFileLoader(name, str(path))
    spec = spec_from_loader(name, loader)
    module = module_from_spec(spec)
    sys.modules[name] = module
    loader.exec_module(module)
    return module


def frozen_inputs(request):
    for field in ("repetitionId", "attemptId"):
        integer(request, field, 1, 2**63 - 1)
    spec = request["specification"]
    if (spec["schemaVersion"], spec["protocol"], spec["adapter"]) != (1, "attempt-v1", "chronos-command@1"):
        raise DriverError("Unsupported Chronos execution specification.")
    sha256(spec["sha256"])
    phases = spec["phases"]
    if type(phases) is not int or phases not in (0, WARMUP_EXCLUDED):
        raise DriverError("The pilot requires prepare, execute, analyze and clean phases.")
    benchmark = spec["benchmark"]["key"]
    if benchmark not in (BATCH, DATABASE):
        raise DriverError("Unsupported benchmark.")
    expected_roles = {"driver", "procedure", "adapters"}
    if benchmark == DATABASE:
        expected_roles.add("dockerfile")
    manifest = spec["artifacts"]
    roles = [item["role"] for item in manifest["artifacts"]]
    paths = request["artifactPaths"]
    if manifest["schemaVersion"] != 1 or len(roles) != len(expected_roles) or set(roles) != expected_roles:
        raise DriverError("Unexpected artifact manifest for the benchmark.")
    if set(paths) != expected_roles:
        raise DriverError("Expected verified artifact paths for the complete manifest.")
    paths = {role: Path(absolute_path(path)) for role, path in paths.items()}
    if any(not path.is_file() for path in paths.values()):
        raise DriverError("A staged artifact is missing.")
    values = spec["effectiveParameters"]
    if any(values.get(name) != value for name, value in spec["parameters"].items()):
        raise DriverError("Effective pilot parameters differ from the submitted benchmark parameters.")
    return spec, benchmark, phases, paths, values


def run_batch(procedure, spec, values, phases, root):
    codec = values["codec"]
    if codec not in procedure.ADAPTERS:
        raise DriverError("Unsupported batch codec.")
    level = integer(values, "level", 1, procedure.ADAPTERS[codec].max_level)
    binary = absolute_path(values["codecExecutable"])
    expected_binary = sha256(spec["system"]["version"])

    args = Namespace(
        study=root, codec=codec, size_bytes=integer(values, "inputBytes", 1, 1024 * 1024),
        seed=integer(values, "seed", -(2**63), 2**63 - 1),
        warmups=warmup(values, "warmups", 3, phases), repetitions=1, timeout_seconds=30,
        **{f"{codec}_level": level, f"{codec}_executable": binary},
    )
    procedure.create_plan(args, expected_executable_sha256=expected_binary)
    plan, plan_sha = procedure.read_plan(root)
    repetition = plan["repetitions"][0]
    directory = root / "attempts" / repetition["repetitionId"] / "attempt-000001"
    directory.mkdir(parents=True)
    attempt = procedure.execute_attempt(root, directory, plan, plan_sha, repetition, None, False)
    if attempt["status"] != "succeeded":
        raise DriverError(attempt["error"])
    sample = attempt["measurement"]
    return {
        "compressionElapsedNs": sample["compression"]["elapsedNs"],
        "inputBytes": plan["input"]["sizeBytes"],
        "compressedBytes": sample["output"]["sizeBytes"],
        "restoredSha256": sample["validation"]["restored"]["sha256"],
    }


def run_database(procedure, spec, values, phases, paths, root):
    engine = values["engine"]
    if engine not in procedure.ADAPTERS:
        raise DriverError("Unsupported database engine.")
    docker = absolute_path(values["dockerExecutable"])
    socket = absolute_path(values["dockerSocket"])
    if not os.access(docker, os.X_OK):
        raise DriverError(f"Docker CLI is unavailable: {docker}")
    expected = sha256(values["dockerExecutableSha256"])
    if procedure.digest_file(Path(docker)) != expected:
        raise DriverError("Docker CLI digest differs from the plan.")

    database_image = image_id(spec["system"]["version"])
    client_image = image_id(values["clientImage"])
    seconds = warmup(values, "warmupSeconds", 3600, phases)
    args = procedure.parse_args([
        "--system", engine, "--docker-socket", socket,
        f"--{engine}-image", database_image, "--client-image", client_image,
        "--tables", str(integer(values, "tables", 1, 32)),
        "--rows", str(integer(values, "rowsPerTable", 1, 10000000)),
        "--threads", str(integer(values, "threads", 1, 64)),
        "--duration", str(integer(values, "measureSeconds", 1, 3600)),
        "--warmup", str(seconds), "--seed", str(integer(values, "seed", 1, 2**31 - 1)),
        "--repetitions", "1", "--retries", "0",
    ])
    sources = {"benchmark.py": paths["procedure"], "adapters.py": paths["adapters"], "Dockerfile": paths["dockerfile"]}
    # The enclosing worker owns the process group and enforces the outer timeout.
    cancellation = procedure.Cancellation(isolate_commands=False)
    previous = {signum: signal.signal(signum, cancellation.receive) for signum in (signal.SIGINT, signal.SIGTERM)}
    try:
        study = procedure.run_study(root, procedure.plan(args), args, cancellation,
                                    source_paths=sources, docker_executable=docker)
    finally:
        for signum, handler in previous.items():
            signal.signal(signum, handler)
    attempts = study["repetitions"][0]["attempts"]
    attempt = json.loads((root / attempts[0]["evidence"]).read_text()) if attempts else {}
    if study["status"] != "succeeded" or not attempt.get("eligible"):
        raise DriverError(study.get("error") or attempt.get("error") or study["status"])
    if procedure.digest_file(Path(docker)) != expected:
        raise DriverError("Docker CLI digest changed during the attempt.")
    measurement = attempt["measurement"]
    summary = measurement["summary"]
    dataset = json.dumps(attempt["datasetBefore"], sort_keys=True, separators=(",", ":")).encode()
    return {
        "events": summary["events"],
        "eventsPerSecond": summary["transactionsPerSecond"],
        "sysbenchElapsedSeconds": summary["sysbenchSeconds"],
        "processWallSeconds": measurement["processWallSeconds"],
        "latencyP95Ms": summary["latencyMilliseconds"]["p95"],
        "datasetSha256": hashlib.sha256(dataset).hexdigest(),
    }


def main():
    evidence = Path.cwd() / "evidence"
    try:
        raw = sys.stdin.buffer.read(MAX_REQUEST_BYTES + 1)
        if len(raw) > MAX_REQUEST_BYTES:
            raise DriverError("Chronos execution request exceeds 2 MiB.")
        request = json.loads(raw)
        evidence.mkdir()
        (evidence / "chronos.json").write_bytes(raw)
        spec, benchmark, phases, paths, values = frozen_inputs(request)
        sys.dont_write_bytecode = True
        load_module("adapters", paths["adapters"])
        procedure = load_module("study_procedure", paths["procedure"])
        with redirect_stdout(sys.stderr):
            if benchmark == BATCH:
                result = run_batch(procedure, spec, values, phases, evidence / "study")
            else:
                result = run_database(procedure, spec, values, phases, paths, evidence / "study")
        print(json.dumps(result, allow_nan=False))
        return 0
    except (DriverError, OSError, ValueError, KeyError, TypeError, AttributeError) as error:
        print(f"Benchmark driver: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
