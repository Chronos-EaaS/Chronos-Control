# PostgreSQL and MariaDB sysbench study

This standalone study exercises the same sysbench read-only OLTP procedure through a PostgreSQL adapter and a MariaDB adapter. It needs Python 3.10 or newer, a local Linux Docker engine or Docker Desktop with Linux containers, and three native-architecture images. From this directory, prepare the images once, outside any benchmark run.

```sh
docker pull postgres:17-bookworm
docker pull mariadb:11.4
docker build -t chronos-study-sysbench:1.0.20-bookworm .
```

Use the same local Docker engine for those commands and the runner. The runner uses the Docker Desktop socket on macOS when present, and `/var/run/docker.sock` otherwise. Pass `--docker-socket /absolute/local/socket` for a different **local** engine. Remote Docker contexts, TCP database endpoints and existing database credentials are outside this protocol.

```sh
python3 benchmark.py --dry-run --repetitions 2 --tables 2 --rows 1000
python3 benchmark.py --repetitions 2 --tables 2 --rows 1000 --threads 2 \
  --warmup 1 --duration 2 --setup-timeout 60 --output results/pilot
python3 -m unittest discover -s . -v
```

The pilot exercises four small attempts. `--rows` sets rows per table. `--duration` and `--warmup` are seconds. `--system postgresql` or `--system mariadb` selects one engine. See `--help` for limits, the seed and retry options. A run requires a new output directory and defaults to a unique directory under `results/`.

The image tags above are convenient defaults. The driver resolves all references before any attempt and then runs the resolved local image IDs using `--pull=never`. Use `--postgresql-image`, `--mariadb-image` and `--client-image` with already provisioned digests or image IDs to replay the same artifacts. The client Dockerfile pins Debian's sysbench package at `1.0.20+ds-5` and accepts a digest-pinned Debian base via `--build-arg BASE_IMAGE=...`. The installed dependency list, client image ID and Lua file hashes are recorded. Archive and hash a client image export if the exact built client must travel to another machine. A rebuild may resolve different Debian dependencies. [Debian sysbench package source](https://sources.debian.org/src/sysbench/1.0.20%2Bds-5/) · [Docker image inspection](https://docs.docker.com/reference/cli/docker/image/inspect/)

## Procedure

1. Resolve the plan, image identities and Docker environment. Run repetitions serially. With both systems selected, the order alternates by round, starting with PostgreSQL. All repetitions and retries use the same nonzero seed for a given plan.
2. For each actual attempt, start a fresh database and a dedicated client in a UUID-labelled internal Docker bridge. The commands publish no host ports and specify no host directories or shared volumes. The only database hostname the client uses is the attempt network's `study-db` alias. Each container has a two-CPU quota and a 1 GiB memory limit. Each DB image initializes its own data directory using fixed synthetic credentials. Wait for a successful authenticated TCP query, then capture server version and selected settings. Confirm the sysbench executable exposes both compiled drivers. [Docker internal networks](https://docs.docker.com/reference/cli/docker/network/create/#network-internal-mode---internal) · [PostgreSQL image](https://github.com/docker-library/docs/blob/master/postgres/content.md) · [MariaDB image variables](https://mariadb.com/kb/en/mariadb-server-docker-official-image-environment-variables/)
3. Use one sysbench prepare thread with the fixed seed, client-generated primary keys and a secondary index. Validate row counts and primary key bounds and hash every table's logical contents. Hash inputs are UTF-8-compatible ASCII TSV rows ordered by ID with columns `table_number, id, k, RTRIM(c), RTRIM(pad)`, LF line endings and no header. Each query streams into SHA-256 inside the DB container. The hashes identify the loaded logical data independently of the physical storage layout.
4. Run an optional, separately logged sysbench warmup using the same workload, workers and seed. Start a new sysbench process and fresh sessions for the measured run. sysbench reports its own elapsed time and event statistics. The host-side `measure` command wall time also includes Docker exec, client startup, connection setup and exit. Preparation, validation, warmup and teardown have their own wall times in the command records.
5. Require successful process exits, a complete final summary and a positive event count. The procedure expects 14 SELECTs and two transaction control statements per event, no writes, ignored errors or reconnects, plausible latency fields and elapsed time within 98% of the requested duration through two seconds over. Validate the original logical fingerprints again. Capture server logs and remove the attempt containers, anonymous volumes and network. Query by the exact ownership label to check teardown. [sysbench 1.0.20 options](https://github.com/akopytov/sysbench/blob/1.0.20/README.md) · [Read-only Lua workload](https://github.com/akopytov/sysbench/blob/1.0.20/src/lua/oltp_read_only.lua) · [Common Lua setup and queries](https://github.com/akopytov/sysbench/blob/1.0.20/src/lua/oltp_common.lua) · [Docker anonymous volume removal](https://docs.docker.com/reference/cli/docker/container/rm/#remove-a-container-and-its-volumes--v---volumes)

`--retries N` permits up to N new attempts after a failed attempt with verified teardown. A retry starts with a fresh database. The first eligible attempt ends the repetition. Any teardown error stops scheduling new attempts. Signals INT and TERM preserve completed evidence, stop the active command and allow teardown. In-flight Docker create commands get time to settle within their command timeout before teardown. If a daemon failure or forced process kill interrupts cleanup, use the recorded `dockerOwnershipLabel` and resource IDs to locate only that attempt. Check labelled containers with `docker ps -a --filter label=LABEL` and networks with `docker network ls --filter label=LABEL` on the same local engine. Confirm ownership before removing a remaining container with `docker rm -fv ID` and then its network with `docker network rm ID`. Interrupted state can remain marked `running` after an uncatchable kill. The runner returns 0 when all repetitions succeed, 1 on execution or evidence failure, 2 for invalid arguments, and 130 or 143 when interrupted by INT or TERM.

## Workload and inference limits

- PostgreSQL uses the `pgsql` driver and libpq. MariaDB uses the `mysql` driver and MariaDB client library with InnoDB. MariaDB's MySQL driver's ignored-error list is overridden for this workload. sysbench's engine-specific table DDL and prepared statement paths remain active, and the engines use their own isolation, collation, buffer and storage defaults. The read workload consists of ten indexed point lookups and four range queries (plain, aggregate, sorted and distinct/sorted) bounded by a configured range of 100 key values, inside an explicit transaction. Driver, engine and physical layout differences can all affect the observed work. [MySQL driver options](https://github.com/akopytov/sysbench/blob/1.0.20/src/drivers/mysql/drv_mysql.c) · [PostgreSQL driver](https://github.com/akopytov/sysbench/blob/1.0.20/src/drivers/pgsql/drv_pgsql.c)
- The common warmup uses a normal `run` phase. sysbench's Lua prewarm command is specific to MySQL/InnoDB. Fresh containers reset the DB state each attempt, and the pre-check reads the whole dataset, so even `--warmup 0` has pre-measurement reads. Host and VM caches can persist. Over a time-limited multithreaded run, different completion rates and scheduling can produce different realized request sequences even with a shared seed.
- Logical input equivalence can be tested by comparing the recorded fingerprints for matching plans. The validation checks workload counters and unchanged input data. Per-query returned values and physical database files are outside the validation contract.
- Pilot runs verify execution and evidence capture. A later performance or orchestration-overhead study should fix image identities, workload, machine and resource assignment, record host load and CPU placement, define the samples and baselines in advance, and report uncertainty. Treat sysbench elapsed time and host-command wall time as separate observables. Estimating Chronos overhead requires a paired Chronos execution with the same measurement boundary and separately timestamped orchestration events.

## Evidence and Chronos integration boundary

```text
results/pilot/
  run.json
  preflight/
    NNN-phase.json
    NNN-phase.stdout.log
    NNN-phase.stderr.log
  001-postgresql/
    attempt-001/
      attempt.json
      NNN-phase.json
      NNN-phase.stdout.log
      NNN-phase.stderr.log
  001-mariadb/
    ...
```

`run.json` records schema version 1, the resolved plan and order, source SHA-256 hashes, Docker/host environment, the requested and observed artifact identities, and links to attempted repetitions. Planned repetitions without attempts are retained. Each `attempt.json` records a local attempt's identity within its repetition, versions, outcome, data fingerprints, structured measurement and teardown status as available. The before/after table checks store their individual command records and raw outputs in `before-table-checks.zip` and `after-table-checks.zip` under the attempt directory. The phase files preserve exact argv, UTC timestamps, monotonic wall duration, exit status and raw stdout/stderr, including warmup, measurement and failure output. An attempt is `eligible` when it passes local checks. Select samples for an analysis explicitly and retain the evidence for all attempts. Evidence includes local filesystem/socket paths, and should be reviewed before external sharing.

The [Chronos command driver](../README.md) runs this same procedure for one
repetition with the resolved workload settings and pinned image IDs. The worker
retains the study evidence with the authoritative Chronos repetition and
attempt IDs. The standalone commands also remain available for local runs.
