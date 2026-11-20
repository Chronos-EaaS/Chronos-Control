# Benchmark bindings and the command worker

Open **Benchmarks and comparisons** from a project. Publish a benchmark version with a parameter schema, a measurement boundary, a warm-up policy, and a result schema. Publish a binding for each supported system. The project default system still supplies the original experiment form. A bound experiment takes its system from the selected binding.

The authenticated UI API offers the same operations.

| Operation | Request |
| --- | --- |
| List benchmark revisions, visible bindings, and available systems | `GET /api/ui/benchmarks` |
| Publish a benchmark revision | `POST /api/ui/benchmarks` with `key`, `version`, `name`, and `contract` |
| Publish a binding revision | `POST /api/ui/benchmarks/{revisionId}/bindings` with `systemId`, `version`, and `definition` |
| Create a bound experiment | `POST /api/ui/projects/{id}/experiments` with `name`, `bindingId`, and `planning` set to `explicit` or `generated` |
| Schedule explicit repetitions | `POST /api/ui/experiments/{id}/evaluations` with `environment` and an ordered `jobs` array |
| Save or list comparison groups | `POST /api/ui/projects/{id}/comparisons` with `name` and `evaluationIds`, or `GET` on that path |
| Inspect the frozen plan and execution history | `GET /api/ui/jobs/{id}/execution` |

All versions are append-only. A system maintainer publishes bindings for their systems. An explicit plan contains one object per repetition, for example `[{"parameters":{"workers":2}},{"parameters":{"workers":2}}]`. Identical entries have the same resolved specification digest and different repetition IDs. A generated bound experiment uses the selected system's parameter schema to produce benchmark inputs. The binding maps each declared input to a unique adapter parameter and may add separate constants.

A benchmark contract for a synthetic driver can use this shape.

```json
{
  "parameters": { "workers": { "type": "integer", "default": 2 } },
  "measurement": "Record the duration of the measured operation.",
  "lifecycle": { "warmUp": "disabled" },
  "results": { "durationMs": { "type": "number", "unit": "ms" } }
}
```

A binding definition provides `systemVersion`, `adapter: "chronos-command@1"`, `parameterMap: {"workers":"threads"}`, optional `constants`, and an `artifacts` manifest. Its schema version 1 lists each artifact by `role`, `uri`, `sha256`, and `sizeBytes`. The `driver` role is required. The manifest may also carry `sourceRevision` and `buildId`.

## Execute one repetition

Stage each trusted artifact in a local cache file named by its SHA-256 digest. The worker verifies size and digest while it makes a private copy. It executes the staged driver directly as a POSIX executable. The process receives a JSON object on stdin with these fields.

| Field | Meaning |
| --- | --- |
| `repetitionId` | Stable job ID |
| `attemptId` | Current execution attempt ID |
| `specification` | Resolved settings, phase mask, requested environment, version identities, and artifact manifest |
| `artifactPaths` | Verified local files keyed by manifest role |

The driver controls benchmark preparation, warm-up, measurement, and cleanup using the frozen inputs. The phase mask marks excluded phases. The driver exits zero with a JSON result object on stdout when the required measurement and validation complete. It writes diagnostics to stderr and uses a nonzero exit status for failure. The worker reports host platform information, retains stdout and stderr in an archive, and sends stderr to the attempt log. Drivers can write additional raw files under `evidence/` in their working directory. Workers collect up to 256 regular files and 16 MiB across 512 directory entries, preserve their relative paths in the ZIP, and flag collection failures on the attempt. Set the PHP upload limit to accommodate these archives. The local cache supplies the bytes. The manifest URI records their origin.

```sh
python3 tools/workers/chronos-command.py https://chronos.example.test \
  --worker test-node --environment local --cache /path/to/artifact-cache
```

The worker returns after one claim. Operators can abort a stranded claim and reschedule the same repetition. A fresh claim generates a new attempt ID. The browser's **Execution evidence** panel lists prior attempts, their evidence, and the attempt selected for standard results. A managed repetition's first completed attempt with a valid measurement is selected once. Failed attempts remain inspectable. Versioned archives use attempt-scoped HTTP uploads. The legacy FTP target continues to use its original numeric filename.

## Agent and CEM protocol

The versioned routes retain the v1 response envelope. A compatible worker polls `/api/v1/job/id=next/environment=local/protocol=attempt-v1/adapter=chronos-command%401/worker=test-node` with a random 32-byte credential encoded as lowercase hex in `X-Chronos-Attempt-Token`. An optional `supports` parameter lists frozen system identifiers separated by commas. A retry of an active claim can reuse the same credential and worker identity. Send a fresh credential for a new attempt. The server stores a hash of the credential. Send it over the configured HTTPS endpoint.

For a claimed repetition, use `/api/v1/job/id={repetitionId}/protocol=attempt-v1` and the same header. Submit URL-encoded `PATCH` fields. A single accepted `status=2` starts the driver. Updates can include `currentPhase` and `progress`, a JSON `environment` object, and a JSON `artifacts` object mapping roles to observed `sha256` and `sizeBytes`. Submit `status=3` with a result object that matches the benchmark schema after reporting every verified artifact. Submit `status=-2` to record failure and optionally include a `reason` and any raw result received.

Append text with `POST .../action=appendLog` and the URL-encoded `log` field while the attempt is active. Submit a ZIP with `POST .../action=upload` and the multipart `result` field before completion. Repeating an upload with the same bytes is accepted while the attempt is active. After an ambiguous completion response, use a credentialed `GET` to check the committed status.

CEM bootstrap clients can opt in on their existing `/api/v1/cem/uniqueId=…/version=…/environment=…` endpoint using the same protocol, adapter and token. The claim returns the resolved setup, CDL, and attempt identity. The bootstrapper sends its normal `jobStarted`, `jobLog` and `jobTerminated` actions with that header. It can pass the claim to the reference worker using `--job {repetitionId}` and `CHRONOS_ATTEMPT_TOKEN`.

The token provides ownership of a claimed attempt. Worker identity, environment, and reported input digests are worker observations. Access to the agent API uses the deployment's existing network boundary. Older jobs keep their original result semantics and missing attempt history. New unbound generated jobs get a frozen CDL and setup and remain on the legacy protocol.

[Python subprocess I/O and timeout](https://docs.python.org/3/library/subprocess.html#subprocess.run) · [Python HTTP requests](https://docs.python.org/3/library/urllib.request.html#urllib.request.Request) · [MySQL locking reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html)
