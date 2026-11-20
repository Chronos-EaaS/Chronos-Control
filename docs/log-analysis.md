# Job log analysis

Open a job and select **Scan log** below its log. Results show the matched rule,
severity, explanation, excerpt, and byte range. Use **Next page** to scan more of
the stored log, **Previous page** to revisit an earlier range, and **Check for new
output** to resume when the scan reaches the available end. The byte ranges refer
to the original **Download log** file. A matching signature is a cue for review.
The scan leaves job state, log contents, and stored results intact.

## Detector signatures-v1

The detector classifies one complete line at a time. It strips ANSI SGR styling,
outer whitespace, a UTF-8 BOM if present, and an optional leading ISO-style
timestamp for matching. It recognizes an explicit `ERROR`, `FATAL`, or `CRITICAL`
level in brackets, before a colon, or before a spaced dash. It tests the remaining
message against the following rules in order, emitting at most one finding per
line. An explicit `FATAL` or `CRITICAL` level always gives critical severity.

| Rule | Severity | What matches |
| --- | --- | --- |
| `native-crash` | Critical | A standalone segmentation fault, bus error, or illegal instruction diagnostic, with an optional shell script/line/PID prefix and core dump suffix. |
| `runtime-panic` | Critical | A nonempty message led by `panic:`, `fatal error:`, or `PHP Fatal error:`. |
| `timeout` | Error | An initial timeout reached/expired, GNU timeout signal message, operation/request/connection/command/job/process timed out, context deadline exceeded, or a `TimeoutError`/`TimeoutException` diagnostic. |
| `python-traceback` | Error | The Python `Traceback (most recent call last):` header. |
| `log-level` | Error or critical | A record with an explicit recognized log level and message. |

Examples include `./run-fixture.sh: line 8: 723 Segmentation fault (core dumped)`,
`[ERROR] Connection timed out after 30 seconds`, and
`panic: runtime error: index out of range`. Informational lines such as
`INFO timeout configured to 30 seconds` and `error margin 0.04` produce no finding.

An intentional fault, caught exception, quoted diagnostic at the beginning of a
line, or recovered failure can still produce a finding. Non-English or custom
formats, failures without a recognized diagnostic, multiline context, and
unterminated final lines can escape detection. Severity describes the matched
signature. No accuracy or false-positive rate has been measured.

## Read API and limits

Use `GET /api/ui/jobs/{id}/log/analysis` with an authenticated browser session and
the same project read access as the job log. Omit query parameters for the first
page. Pass the returned `cursor` as the sole query parameter to continue. A
`startCursor` lets clients repeat a range starting at its original offset against
the current stored log. Treat both cursors as opaque. `reset` signals that the
source restarted at byte zero after generation change or shrinkage. `available`
distinguishes a missing log from an existing empty log. Retry a
`409 log_read_conflict` with the same cursor.

Each finding contains the detector version, stable rule ID, reason, severity,
log epoch, excerpt, and zero-based `byteStart` and exclusive `byteEnd` for the raw
line, including its newline. `lineSha256` hashes exactly those original bytes.
The excerpt is valid UTF-8 up to 240 bytes of text plus a truncation ellipsis.
The page reports the byte range traversed and the file size observed for that
read. `hasMore` signals additional work in the observed file. `pendingLine`
indicates an unterminated final line awaiting a newline. A short line across a
page boundary is read from its start on the next scan.

A request reads at most 128 KiB of log data plus one lookbehind byte, emits up to
100 findings, and matches lines of at most 8 KiB including their newline.
Oversized lines are counted when first encountered and skipped through their
next newline. Regexes are fixed and run only on bounded records. LF and CRLF
are supported. Invalid UTF-8 is replaced in excerpts while byte offsets and
digests remain tied to the original file.

Active appends can be scanned with the same epoch. The source detects Chronos
clear markers, file replacement, changes between missing and present, and an
observed drop in size. A same-inode external rewrite that regrows to the prior size
between reads can escape reset detection. As the file changes, prior ranges may
become unavailable. The digest can be used to validate a retained copy of a line.
The analysis reads the current job log through the authorized JobService and
takes a `LogSource` so a future attempt-scoped adapter can supply its own
authorized source and generation identity.
