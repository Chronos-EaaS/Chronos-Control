#!/usr/bin/env python3
"""Stage a benchmark example and write Chronos benchmark, binding and evaluation requests."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile


HERE = Path(__file__).resolve().parent
BATCH = {"gzip": 6, "zstd": 3}
DATABASE = {"postgresql": "postgres:17-bookworm", "mariadb": "mariadb:11.4"}
BENCHMARKS = {
    "batch": {
        "key": "lossless-compression",
        "name": "Lossless compression",
        "parameters": {"inputBytes": 131072, "seed": 2027, "warmups": 1},
        "measurement": (
            "Parent monotonic wall time for one compression subprocess from spawn to exit, "
            "including startup and buffered output. Setup, warm-up and lossless round-trip "
            "validation occur outside the measured interval."
        ),
        "results": {
            "compressionElapsedNs": {"type": "integer", "unit": "ns"},
            "inputBytes": {"type": "integer", "unit": "bytes"},
            "compressedBytes": {"type": "integer", "unit": "bytes"},
            "restoredSha256": {"type": "string"},
        },
    },
    "database": {
        "key": "sysbench-oltp-read-only",
        "name": "Sysbench read-only OLTP",
        "parameters": {"tables": 1, "rowsPerTable": 1000, "threads": 2,
                       "measureSeconds": 2, "warmupSeconds": 1, "seed": 20271120},
        "measurement": (
            "One time-limited sysbench read-only OLTP run against a fresh, validated "
            "database. Record sysbench elapsed time, events, throughput and p95 latency, "
            "plus the separate Docker exec wall interval. Preparation, warm-up, dataset "
            "verification and teardown occur outside both measured intervals."
        ),
        "results": {
            "events": {"type": "integer", "unit": "events"},
            "eventsPerSecond": {"type": "number", "unit": "events/s"},
            "sysbenchElapsedSeconds": {"type": "number", "unit": "s"},
            "processWallSeconds": {"type": "number", "unit": "s"},
            "latencyP95Ms": {"type": "number", "unit": "ms"},
            "datasetSha256": {"type": "string"},
        },
    },
}


def executable(selector):
    path = shutil.which(selector)
    if path is None:
        raise ValueError(f"Executable is unavailable: {selector}")
    return str(Path(path).absolute())


def digest_file(path):
    digest = hashlib.sha256()
    with Path(path).open("rb") as source:
        for block in iter(lambda: source.read(65536), b""):
            digest.update(block)
    return digest.hexdigest()


def cache_artifact(cache, role, data):
    digest = hashlib.sha256(data).hexdigest()
    target = cache / digest
    with tempfile.TemporaryDirectory(prefix=".stage-", dir=cache) as directory:
        temporary = Path(directory) / digest
        temporary.write_bytes(data)
        try:
            os.link(temporary, target)
        except FileExistsError:
            if target.is_symlink() or not target.is_file() or digest_file(target) != digest:
                raise ValueError(f"Cache entry has unexpected contents: {target}") from None
    return {"role": role, "sha256": digest, "sizeBytes": len(data),
            "uri": digest}


def image_id(docker, socket, requested):
    result = subprocess.run(
        [docker, "--host", f"unix://{socket}", "image", "inspect", "--format", "{{.Id}}", "--", requested],
        check=True, text=True, stdout=subprocess.PIPE, timeout=30,
    )
    image = result.stdout.strip()
    if not re.fullmatch(r"sha256:[a-f0-9]{64}", image):
        raise ValueError(f"Docker did not report a content-addressed image ID for {requested}.")
    return image


def payloads(args):
    study = "batch" if args.pilot in BATCH else "database"
    definition = BENCHMARKS[study]
    defaults = dict(definition["parameters"])
    if study == "batch":
        binary = executable(args.executable or args.pilot)
        system_version = digest_file(binary)
        constants = {"codec": args.pilot, "level": BATCH[args.pilot], "codecExecutable": binary}
        procedure = "batch.py"
        warmup = "warmups"
    else:
        binary = executable(args.executable or "docker")
        socket = args.docker_socket
        if not socket.is_absolute() or not socket.is_socket():
            raise ValueError(f"Provide an existing absolute Unix Docker socket: {socket}")
        system_version = image_id(binary, socket, args.system_image or DATABASE[args.pilot])
        client = image_id(binary, socket, args.client_image)
        constants = {
            "engine": args.pilot, "clientImage": client, "dockerSocket": str(socket),
            "dockerExecutable": binary, "dockerExecutableSha256": digest_file(binary),
        }
        procedure = "benchmark.py"
        warmup = "warmupSeconds"

    python = executable(args.python)
    if len(os.fsencode(python)) > 110 or any(char.isspace() for char in python):
        raise ValueError("The driver needs a short Python executable path without whitespace.")
    minimum = (3, 9) if study == "batch" else (3, 10)
    subprocess.run([python, "-c", f"import sys; sys.exit(sys.version_info < {minimum})"], check=True, timeout=15)

    args.cache.mkdir(parents=True, exist_ok=True)
    driver = f"#!{python}\n".encode() + (HERE / "command_driver.py").read_bytes()
    artifacts = [cache_artifact(args.cache, "driver", driver)]
    sources = {"procedure": procedure, "adapters": "adapters.py"}
    if study == "database":
        sources["dockerfile"] = "Dockerfile"
    for role, name in sources.items():
        origin = HERE / study / name
        artifacts.append(cache_artifact(args.cache, role, origin.read_bytes()))

    benchmark = {
        "key": definition["key"],
        "version": args.benchmark_version,
        "name": definition["name"],
        "contract": {
            "parameters": {key: {"type": "integer", "default": value} for key, value in defaults.items()},
            "measurement": definition["measurement"],
            "lifecycle": {"warmUp": "optional"},
            "results": definition["results"],
        },
    }
    binding = {
        "systemId": args.system_id,
        "version": args.binding_version or f"{args.pilot}-v1",
        "definition": {
            "adapter": "chronos-command@1",
            "systemVersion": system_version,
            "parameterMap": {name: name for name in defaults},
            "constants": constants,
            "artifacts": {"schemaVersion": 1, "artifacts": artifacts},
        },
    }
    if args.source_revision:
        binding["definition"]["artifacts"]["sourceRevision"] = args.source_revision

    experiment = {"name": f"{args.pilot} smoke", "planning": "explicit", "warmUp": not args.skip_warmup}
    if args.skip_warmup:
        defaults[warmup] = 0
    evaluation = {"environment": args.environment, "jobs": [{"parameters": defaults}]}
    return {"benchmark": benchmark, "binding": binding, "experiment": experiment, "evaluation": evaluation}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("pilot", choices=[*BATCH, *DATABASE])
    parser.add_argument("--cache", type=Path, required=True, help="worker's SHA-256 artifact cache")
    parser.add_argument("--output", type=Path, required=True, help="new directory for request JSON")
    parser.add_argument("--system-id", type=int, required=True, help="numeric Chronos system ID")
    parser.add_argument("--benchmark-version", default="1")
    parser.add_argument("--binding-version", help="defaults to the pilot name followed by -v1")
    parser.add_argument("--source-revision", help="source revision to record when it identifies the staged files")
    parser.add_argument("--environment", default="system-default", help="configured scheduling key, e.g. system-default or cem-cluster")
    parser.add_argument("--skip-warmup", action="store_true")
    parser.add_argument("--python", default=sys.executable, help="Python executable at the same path on the worker")
    parser.add_argument("--executable", help="gzip, zstd or Docker CLI at the same path on the worker")
    parser.add_argument("--docker-socket", type=Path, default=Path("/var/run/docker.sock"))
    parser.add_argument("--system-image", help="locally installed database image to pin")
    parser.add_argument("--client-image", default="chronos-study-sysbench:1.0.20-bookworm")
    args = parser.parse_args()
    if args.system_id < 1:
        parser.error("--system-id must be positive")
    args.cache = args.cache.resolve()
    try:
        requests = payloads(args)
        args.output.mkdir(parents=True)
        for name, request in requests.items():
            (args.output / f"{name}.json").write_text(json.dumps(request, indent=2) + "\n", encoding="utf-8")
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        parser.exit(1, f"stage: {error}\n")


if __name__ == "__main__":
    main()
