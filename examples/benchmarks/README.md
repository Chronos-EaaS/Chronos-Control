# Run the benchmark examples through Chronos

The command worker runs the same procedures as the standalone
[batch study](batch/README.md) and [database study](database/README.md). Each
driver runs one planned repetition per Chronos attempt. Use another Chronos
attempt to retry a failure.

## Stage the drivers

Run the following from the repository root on the worker host. Set
`batch_system` and `database_system` to numeric IDs for existing Chronos systems
you maintain. Each binding uses the agent identifier in its Chronos system's
`config.json`. The same system can host more than one pilot binding.

Install Python 3.10 or newer for the database pilots. Python 3.9 or newer works
for the batch pilots. The batch worker needs gzip and Zstandard. The database
worker needs the local Docker socket and all three native Linux images described
in the [database requirements](database/README.md). The worker runs the
prepared images by content-addressed image ID and never pulls during an attempt.

```sh
pilot_dir=$(mktemp -d)
cache="$pilot_dir/cache"
python3 examples/benchmarks/stage.py gzip --cache "$cache" \
  --output "$pilot_dir/gzip" --system-id "$batch_system"
python3 examples/benchmarks/stage.py zstd --cache "$cache" \
  --output "$pilot_dir/zstd" --system-id "$batch_system"
python3 examples/benchmarks/stage.py postgresql --cache "$cache" \
  --output "$pilot_dir/postgresql" --system-id "$database_system"
python3 examples/benchmarks/stage.py mariadb --cache "$cache" \
  --output "$pilot_dir/mariadb" --system-id "$database_system"
```

The `stage.py` command writes the driver, procedure and codec or database
adapter to the worker's cache under their SHA-256 digests. The database binding
also stages the client Dockerfile for source provenance. The staging command
resolves the Python and tool paths on the staging host. Use `--python` and
`--executable` to choose explicit paths. For another worker host, use a
compatible runtime at those same paths and provision the same Docker image
IDs. Use `--docker-socket` if the worker's socket differs from
`/var/run/docker.sock`. A database binding records the Docker CLI digest and
both image IDs. A batch binding records the codec executable digest and
algorithm-specific compression level. Batch input bytes are reproduced by the
staged generator and the frozen input length and seed.

## Publish and run

The staging output contains the actual JSON request bodies. The [command
worker guide](../../tools/workers/README.md) explains authentication,
artifact verification and attempt handling.

| Step | Authenticated request | Body |
| --- | --- | --- |
| Publish each shared benchmark once | `POST /api/ui/benchmarks` | `benchmark.json` |
| Publish one binding per pilot | `POST /api/ui/benchmarks/{benchmarkRevisionId}/bindings` | `binding.json` |
| Make an explicit experiment | `POST /api/ui/projects/{projectId}/experiments` | `experiment.json` with `bindingId` set to the returned binding ID |
| Schedule an evaluation | `POST /api/ui/experiments/{experimentId}/evaluations` | `evaluation.json` |

The two batch drivers share one benchmark revision and the two database drivers
share another. To schedule more than one repetition, add objects to the
evaluation's ordered `jobs` array. To disable warm-up, stage the example
requests with `--skip-warmup`. The experiment must enable warm-up for a
positive warm-up count or duration. The pilot requires prepare, execute,
analyze and clean phases. The batch driver accepts inputs up to 1 MiB and up
to three warm-ups to keep its raw attempt archive small. Explicitly version changed bindings with
`--binding-version`.

Start a worker on the prepared host. Set `chronos_url` and use the frozen system
identifier or a comma-separated list of supported identifiers.

```sh
python3 tools/workers/chronos-command.py "$chronos_url" \
  --worker study-pilot --environment local --supports "$system_identifier" --cache "$cache"
```

Each invocation processes at most one repetition. Increase `--timeout` when
the requested preparation and measurement require a longer worker deadline.
If the process is forcibly killed during a database attempt, use the recorded
Docker ownership label and the [database recovery procedure](database/README.md#procedure).
Inspect the selected result and every attempt through
`GET /api/ui/jobs/{repetitionId}/execution` or the project's execution evidence
panel.

The batch result reports compression wall time in nanoseconds, input and
compressed sizes, and restored SHA-256. The database result reports event
count, events per second, sysbench elapsed seconds, Docker exec wall seconds,
p95 latency in milliseconds and the SHA-256 of the canonical JSON array of
table fingerprints. The result is available only after the driver validates
the operation and completes its attempt procedure.

The attempt ZIP retains the driver's JSON result, diagnostics, and the raw
`evidence/` tree. `evidence/chronos.json` links the server's repetition ID,
attempt ID and frozen specification to the native study evidence under
`evidence/study/`. That tree contains the measured and warm-up outputs, logs
and validation evidence documented by each study. A driver failure can leave
partial evidence in the archive and does not select a result. Retain the
archives for both successful and failed attempts.

## Driver smoke tests

```sh
python3 examples/benchmarks/test_command_driver.py -v
CHRONOS_STUDY_DOCKER=1 python3 examples/benchmarks/test_command_driver.py -v
```

The tests use the worker's digest-verified artifact layout and restricted
process environment. The Docker tests are opt-in and exercise the already
provisioned client, PostgreSQL and MariaDB images.
