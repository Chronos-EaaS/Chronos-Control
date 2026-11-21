# gzip and Zstandard batch smoke study

This standalone example runs the same lossless-compression procedure with two CLI
adapters on one generated input. It records local smoke data for replay and
failure recovery. A performance study would need a controlled host and cache
policy, a representative corpus, a sampling plan, and uncertainty estimates.

## Requirements

Use Python 3.9 or newer on macOS or Linux, and a local filesystem that supports
advisory file locking and hard links. The driver uses only the Python standard
library. Install GNU gzip or use the system gzip on macOS. Install the Zstandard
CLI with `--single-thread` support, for example through your system package manager.
Check the tools with `gzip --version` and `zstd --version`. Select one codec with
`plan --codec gzip` or `plan --codec zstd` to run with a single dependency.

## Pilot protocol

Run the commands from the repository root. Keep the printed directory to retain
the raw evidence. `plan` requires a new path with an existing parent.

```sh
pilot_parent=$(mktemp -d)
python3 examples/benchmarks/batch/batch.py plan "$pilot_parent/pilot" \
  --seed 2027 --size-bytes 1048576 --warmups 1 --repetitions 2 \
  --gzip-level 6 --zstd-level 3
python3 examples/benchmarks/batch/batch.py run "$pilot_parent/pilot"
find "$pilot_parent/pilot" -name result.json
```

The generator emits 64 KiB blocks, each with a 32 KiB repeated text prefix and
32 KiB of deterministic SHAKE-256 bytes keyed by seed and block index. It
truncates the final block at the requested length. Size and seed reproduce the
input across new studies, while each study gets a fresh ID. The pilot executes
gzip-001, zstd-001, gzip-002, zstd-002 in that order. Each attempt performs the
following steps:

1. Check the input SHA-256 and size against the plan. Resolve and hash the
   executable and record a separate `--version` invocation with both log streams.
2. Run the configured number of warmups with the same arguments and validation
   as the measured sample. A failed warmup stops that attempt.
3. Run one measured compression with an explicitly configured level. Its
   `compression.elapsedNs` is monotonic parent wall time from immediately before
   process creation until the parent observes process exit. It covers startup,
   input reads, buffered stdout writes and waiting for exit, including parent
   scheduling and the Python wait implementation. File setup, archive hashing,
   durable storage flushing, warmups, and validation are outside that interval.
4. Decompress the archive using the same executable, check format magic, and
   verify both byte-for-byte equality and the restored SHA-256 and size. A zero
   exit code alone is insufficient. Remove the restored scratch file after
   validation and keep its identity along with the logs. Recheck the input and
   executable identities before recording overall success.

The input checks and warmups may populate the file cache. The driver makes no
cache-state guarantee. A small, separate process runs for each compression and
decompression.

| Adapter | Compression arguments before the input operand | Settings |
| --- | --- | --- |
| `gzip` v1 | `-n -LEVEL -c --` | Ordinary serial gzip CLI. Levels 1–9. Omit original filename and modification time with `-n`. |
| `zstd` v1 | `--single-thread --check --no-progress -LEVEL -c --` | One thread for compression and I/O. Levels 1–19. Emit a frame checksum. |

Both adapters decode with `-d -c -- ARCHIVE`. Zstandard's `-T1` uses one
compression worker alongside I/O and can produce different compressed output.
Level numbers are codec-specific settings. Identical numbers give no guarantee
of comparable compression effort, throughput, or output size. See the
[GNU gzip manual](https://www.gnu.org/software/gzip/manual/gzip.html),
[Apple gzip manual](https://github.com/apple-oss-distributions/file_cmds/blob/main/gzip/gzip.1),
and [Zstandard 1.5.7 CLI manual](https://github.com/meta/zstd/blob/v1.5.7/programs/zstd.1).

Use `--gzip-executable` and `--zstd-executable` to select trusted local
executables when planning. Selectors with a slash are converted to absolute
paths at planning time. Bare names are resolved on the runner's `PATH` at each
attempt. The driver passes an argument vector directly to the child with a
fixed environment recorded in the plan, excluding inherited codec defaults
such as `GZIP`, `ZSTD_CLEVEL`, and `ZSTD_NBTHREADS`. The observed SHA-256 and
version identify the launched file and its banner. Binary digests do not capture
linked libraries or the machine configuration. A retry can observe a different
executable at the same selector, for example after installing a missing codec.

## Failure and recovery

```sh
recovery_parent=$(mktemp -d)
study="$recovery_parent/recovery"
python3 examples/benchmarks/batch/batch.py plan "$study" --codec gzip --repetitions 1

# Expected runner status 1. The compressor exits 0 and the decoder rejects the archive.
failure_status=0
python3 examples/benchmarks/batch/batch.py run "$study" --repetition gzip-001 \
  --inject-corruption || failure_status=$?
test "$failure_status" -eq 1

python3 examples/benchmarks/batch/batch.py run "$study" --repetition gzip-001 --retry
find "$study/attempts/gzip-001" -name result.json
```

Injection flips the first byte of the measured archive after compression and
records both digests. The first attempt retains the corrupt archive, timings
and decoder diagnostics. The second attempt refers to the first via `retryOf`
and uses the same plan and repetition ID. The runner never overwrites a previous
attempt. A normal `run` skips existing attempts and can run the remaining
repetitions. An explicit retry requires a single failed, interrupted, or
incomplete repetition.

## Raw evidence and integration

- `plan.json` contains the procedure, input recipe and identity, adapter versions
  and parameters, environment policy, timing boundaries, and ordered repetitions.
  `plan.sha256` fingerprints its exact bytes. Treat both files as read-only.
- `attempts/<repetitionId>/<attemptId>/attempt.json` records the allocated attempt
  before codec execution. The tuple `(studyId, repetitionId, attemptId)` identifies
  the attempt. A reserved directory without a `result.json` represents an
  incomplete attempt and can contain partial evidence after abrupt termination.
- `result.json` records setup, warmup executions, the measured compression and its
  validation, and the final status. Each command records its argv, working
  directory, relative stream paths, exit code, and elapsed nanoseconds. A null
  exit code means the process could not launch. Negative codes represent POSIX
  signals, including when the driver kills a process at the configured timeout.
  Compressed files (even partial or corrupt ones), stderr, and version output are
  retained. The decompression stdout path names a temporary file whose bytes are
  normally removed after recording its identity. A hard kill can leave scratch
  files in the incomplete attempt directory.
- CLI exit codes are `0` when the selected repetitions have succeeded, `1` if
  any selected repetition is failed or incomplete, `2` for a usage or filesystem
  error at the study level, and `130` for a handled interruption. New executions
  of a study are serialized by a local lock.
- The codec adapter contract consists of `name`, `adapter_version`, `max_level`,
  `suffix`, `magic`, `parameters()`, and the `compress()` and `decompress()` argv
  builders. The driver owns logging, timing, version probing, and validation.
  The [Chronos command driver](../README.md) uses that same procedure for one
  repetition and retains the native study evidence with the Chronos IDs.

## Tests

```sh
python3 -m unittest discover -s examples/benchmarks/batch -v
```

The suite uses isolated temporary directories, synthetic executables for error
paths, and both real codecs for smoke tests. When a codec is missing, tests
that require it report a skip.
