# Chronos MCP server

This stdio integration drives the existing Chronos UI API using the configured account's permissions. Use a Chronos account with membership in the benchmark projects it needs. System discovery follows the catalog's owner and maintainer scope. Project records include `systemId` for projects whose system is outside that catalog.

## Install and connect

Install from the repository root with Node.js 22 or later.

```sh
npm --prefix integrations/mcp ci --ignore-scripts
```

Configure your MCP host to launch the server. In hosts that use an `mcpServers` map, add this entry with your checkout's absolute path.

```json
{
  "mcpServers": {
    "chronos": {
      "command": "node",
      "args": ["/absolute/path/to/checkout/integrations/mcp/src/index.js"]
    }
  }
}
```

Provide the following environment variables to the child process using your MCP host's environment or secret configuration. For a loopback fixture, an example origin is `http://127.0.0.1:8080`.

| Variable | Required | Value |
| --- | --- | --- |
| `CHRONOS_URL` | Yes | Chronos origin without a path. HTTPS or loopback HTTP. |
| `CHRONOS_USERNAME` | Yes | Chronos username. |
| `CHRONOS_PASSWORD` | Yes | Chronos password from the host's secret configuration. |
| `CHRONOS_SESSION_COOKIE` | No | PHP session cookie name. Defaults to `PHPSESSID`. |
| `CHRONOS_REQUEST_TIMEOUT_MS` | No | Deadline in milliseconds per HTTP attempt, including response transfer. Defaults to 15000 and accepts up to 300000. |
| `CHRONOS_EXPORT_DIR` | For evidence export | Existing directory on the MCP host. Use an absolute path for consistent placement. |
| `CHRONOS_MAX_EXPORT_BYTES` | No | Maximum bytes per export. Defaults to 33554432 and accepts up to 1073741824. |

The server bootstraps a session on its first API call, then signs in with the anonymous session cookie, CSRF token, and same-origin header. It uses the rotated cookie and token for subsequent requests and disables persistent login. Cookies and CSRF tokens stay in the server process. A read rejected with 401 can renew the session and try again. Redirects are surfaced as errors.

When launching outside an MCP host, use `node integrations/mcp/src/index.js` with the same environment. The process waits for MCP requests on stdin and reserves stdout for protocol messages.

This integration uses the official MCP TypeScript SDK 2.4.0. The SDK handles stdio framing, protocol negotiation, tool validation, and request cancellation. [SDK stdio guide](https://ts.sdk.modelcontextprotocol.io/v2/serving/stdio.html) · [MCP stdio specification](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/stdio)

## Run a versioned benchmark

1. Call `chronos_get_capabilities` and look for `benchmarkWorkflows: true`. `chronos_list_benchmarks` returns `benchmarks`, `bindings`, and the `systems` that the current account can bind. The catalog is a single response.
2. Call `chronos_publish_benchmark` with `key`, `version`, `name`, and a `contract` containing the typed `parameters`, `measurement`, `lifecycle.warmUp`, and typed `results`. Call `chronos_publish_binding` with the returned `benchmarkRevisionId`, a `systemId` you maintain, a binding `version`, and `definition`. The binding definition includes `systemVersion`, `adapter: "chronos-command@1"`, a `parameterMap` covering each contract parameter, optional `constants`, and an `artifacts` manifest. The manifest uses `schemaVersion: 1` and an `artifacts` array containing a `driver` role with `uri`, `sha256`, and `sizeBytes`. Each publication adds an immutable revision. See the [benchmark contract and worker guide](../../tools/workers/README.md) for complete examples.
3. Call `chronos_get_experiment_options` with `projectId`, `bindingId`, and `planning`, then `chronos_create_experiment` with the same three arguments and a `name`. The binding determines the experiment system. For `planning: "generated"`, supply the saved configuration values and use `chronos_start_evaluation` to run it. For `planning: "explicit"`, supply the parameter sets at scheduling time.
4. For an explicit plan, call `chronos_get_run_options` for the experiment ID. Pick an environment key and call `chronos_start_explicit_evaluation` with `experimentId`, `environment`, and the ordered `jobs` array. For example, `{"jobs":[{"parameters":{"workers":2}},{"parameters":{"workers":2}}]}` schedules two distinct jobs with the same resolved specification. Values follow the benchmark contract. A `parameters` object can omit fields that have a contract default. The job limit from the experiment options applies.
5. Use `chronos_list_jobs` for repetition IDs and `chronos_get_job_execution` for each frozen `specification`, `attempts`, and `selectedAttemptId`. The attempt projection includes result and status, worker identity, observed environment and artifact digests, and available file links. Call `chronos_export_attempt_evidence` with `jobId`, `attemptId`, and `format: "log"` or `format: "zip"` to save that attempt's log or archive. The export reports the path, byte count, and SHA-256 of the saved bytes.
6. Call `chronos_create_comparison` with `projectId`, `name`, and two to fifty distinct `evaluationIds` from the project. `chronos_list_comparisons` returns `items` with the group members and their planned and finished job counts.

`chronos_get_capabilities` detects the versioned workflow through the authenticated benchmark catalog route. An older instance that lacks that route returns `benchmarkWorkflows: false` and versioned tools return `unsupported_capability`. The older generated workflow remains available.

## Run a project-generated experiment

The IDs, parameters, and version in this sequence are examples. Use values returned by your Chronos instance.

1. Discover the project with `chronos_list_projects` and its experiments with `chronos_list_experiments` using `{"projectId":6}`. Call `chronos_get_experiment_options` for project 6 to read parameter groups, defaults, and the current job limit.
2. Call `chronos_create_experiment` with `{"projectId":6,"name":"Fixture sweep","runs":1,"distribution":"alter","warmUp":false,"parameters":{"workers":2}}` to save a definition. Pass the execution defaults you want from the options response. Parameter values use the UI's flat field map. For example, a software list uses fields like `version-numberOfVersions` and `version-version-0`. To copy a definition, request options with `sourceExperimentId`, then send that ID and the source's editable parameter values with your changes.
3. Call `chronos_get_run_options` with the returned experiment ID. Use an environment key from that response in `chronos_start_evaluation`, for example `{"experimentId":21,"name":"Pilot","environment":"system-local","versions":{"version":["2.0"]}}`. Chronos creates the evaluation and schedules its jobs in a single write.
4. Poll `chronos_get_evaluation` with `{"evaluationId":31}` until `counts.active` is zero. Inspect `failed` and `aborted` alongside `finished`. Use `chronos_list_jobs` for individual job IDs and `chronos_get_job` for status and recorded configuration.
5. Read `chronos_get_job_log` with `{"jobId":42}`. Continue with the returned `nextOffset` and `epoch`, and replace accumulated text when Chronos signals `reset`. `complete` means the chunk reaches the current end of the log.
6. Call `chronos_get_results` with `{"scope":"evaluation","id":31}` for raw result rows. Call `chronos_export_evidence` with `{"scope":"evaluation","id":31,"format":"csv"}` for the original CSV bytes. ZIP exports require finished jobs with stored archives. Use `{"scope":"job","id":42,"format":"log"}` for the original log file. Each export streams to a new private directory under `CHRONOS_EXPORT_DIR` and returns the host-local path, byte count, and SHA-256 of the saved bytes.

Follow `nextPage` for lists, and `jobPage.nextPage` for raw results. A null value marks the end. Keep the page size and filters consistent between calls. Listings use stable ID order and return the effective page if Chronos clamps the requested page.

## Errors and cancellation

Tool results include structured data and a JSON text representation. Chronos API rejections include the HTTP status and, when available, the application code and field errors. The integration caps JSON responses at 2 MiB. Use smaller pages or log chunks for large records. Ordinary GET requests retry once after a transport failure, timeout, or HTTP 408, 502, 503, or 504. Rate-limit responses return to the caller.

The server sends each API write once. After a `write_outcome_unknown` result or cancellation during a write, inspect the relevant Chronos records before repeating the operation. Cancelling an MCP call stops local I/O. To abort scheduled or running jobs, call `chronos_abort_evaluation` explicitly. The underlying Chronos action also moves failed jobs to the aborted state.

## Test

```sh
npm --prefix integrations/mcp test
```

The suite launches the official MCP client and server over stdio and uses a synthetic HTTP server on an ephemeral loopback port. It exercises both workflows, attempt evidence export, session and CSRF handling, pagination, error mapping, cancellation, and write replay prevention. It needs permission to bind loopback ports.

With a fresh disposable V3 fixture from `tools/test/test-stack-v3` and Python 3 installed, run the live smoke test. It publishes synthetic revisions, schedules two repetitions, runs the reference command worker, and asserts selected results and exported attempt evidence through the stdio client. If the fixture uses a different loopback port, set `CHRONOS_URL` to its origin.

```sh
npm --prefix integrations/mcp run test:live
```

With the standalone Go and Python agents installed, the cross-system smoke test creates one project with a distinct Chronos System for each pilot. It creates gzip and Zstandard systems by default and adds PostgreSQL and MariaDB with `--database`. Both agents must leave a queued repetition untouched when they advertise a different system identifier. The test checks the bound system in each frozen specification and saved comparison, then verifies the failed attempt, retry, selected results, and downloaded evidence. The database pair requires Docker and locally provisioned images. See [the staging instructions](../../examples/benchmarks/README.md) and the runner options.

```sh
node integrations/mcp/scripts/study-smoke.js --help
```
