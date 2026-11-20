# Build artifacts for Chronos CEM

The build tool records the SHA-256 and byte count of each output. The CEM bootstrapper can use those identities to prepare verified local files before setup. The node operator chooses the build source and cache directory.

## Build and register

From the repository root, with PHP 8.3 or newer and a C compiler.

```sh
staging=$(mktemp -d)
tools/artifacts/example/build.sh "$staging/build"
tools/artifacts/artifacts.php validate "$staging/build/manifest.json"
tools/artifacts/artifacts.php register "$staging/build/manifest.json" "$staging/registry" "$staging/build"
```

The sample builds a small executable and a synthetic workload file. For other builds, run `artifacts.php generate BUILD_DIR ROLE=FILE [ROLE=FILE ...]` once the files are stable. Each `FILE` is relative to `BUILD_DIR`. Set `CHRONOS_SOURCE_REVISION` to the revision actually built and `CHRONOS_BUILD_ID` to the identity of that build attempt when available. The generator omits unknown provenance.

The [GitHub Actions example](example/github-actions.yml) uses the checked-out commit and the repository, run and run attempt for provenance. It retains the build outputs and manifest together as a run artifact if enabled. To serve the raw bytes, retrieve the bundle by its run and artifact ID through your artifact system, then place its files under an operator-approved build directory. CEM does not use the GitHub download URL as an artifact URI. [GitHub Actions variables](https://docs.github.com/en/actions/reference/workflows-and-actions/variables) · [Artifact upload and download contract](https://github.com/actions/upload-artifact)

Registration verifies all file identities and stores the normalized manifest under its own SHA-256 in the same local content-addressed store. The JSON result contains `manifestSha256`, `manifestPath` and the artifacts with their local paths. Retain the manifest alongside the original build outputs. A failed registration may leave individually verified blobs available for a retry. The complete manifest is stored only after all roles pass. The command returns a nonzero exit and no success JSON on failure.

## Manifest v1

The document is a JSON object with integer `schemaVersion: 1` and an ordered `artifacts` array of 1 to 64 entries. Each entry has a unique `role`, a `uri`, a lowercase hexadecimal `sha256` and integer `sizeBytes`. Optional `sourceRevision` and `buildId` are nonempty strings of up to 256 bytes. The limits are 60 KiB for the manifest, 1 GiB per artifact and 2 GiB in total. A normalized manifest digest covers the UTF-8 JSON written by `validate`, including its final newline and the declared artifact order.

Relative URIs name files within the configured source directory. Use slash-separated, case-sensitive path segments made of ASCII letters, digits, underscores, hyphens and periods. Segments may not start with a period. Absolute URIs must use a canonical HTTPS URL under the single configured base, with a lowercase hostname. The local test transport also supports `http://127.0.0.1`. URI credentials, query strings, fragments, percent escapes and redirects are rejected. Local files must resolve under the selected source directory. The resolver verifies archives as opaque byte streams. Any later extraction is the responsibility of the consuming adapter.

## CEM setup

1. In the system's **Setup** settings, save the manifest JSON as the text value of `artifactManifestV1`.
2. A bootstrapper that supports this version includes `/artifactManifest=1` on the existing `GET /api/v1/cem/uniqueId=…/version=…/environment=…` request. For artifact-enabled jobs, the successful response carries `response.artifactManifest` alongside `setup` and `cdl`. On a configured job with an invalid manifest or missing capability, the API returns application status `600` with a diagnostic. Jobs without the setting use the legacy response.
3. Save the complete JSON response to a file and run `artifacts.php setup CEM_RESPONSE CACHE SOURCE` on the CEM node. `SOURCE` is an absolute local directory or the approved HTTPS directory. To use HTTP(S) downloads, install PHP cURL with libcurl 7.85 or newer. The resolver uses a 5-second connect limit, a 30-second total transfer limit per download, TLS certificate verification and streaming size enforcement. [libcurl protocol restriction](https://curl.se/libcurl/c/CURLOPT_PROTOCOLS_STR.html) · [libcurl write callback](https://curl.se/libcurl/c/CURLOPT_WRITEFUNCTION.html)
4. Feed the returned `artifacts[].path` values to the setup adapter by role. The cache is read-only to the consumer by convention and publishes each verified file atomically under its digest. Keep it under a trusted operator-owned parent on a filesystem that supports hard links. When setup needs to modify or execute a verified artifact, stage it into a private job directory and set the necessary mode there.
5. Treat a nonzero setup exit as a setup failure. If the bootstrapper has already started the job, it can report the diagnostic using the existing CEM `jobTerminated` action with a `reason`. To recover from a corrupt cache entry, remove that digest from the cache and retry against the approved source. An intact cache can serve the same identities with the source offline.

The manifest must come from a trusted build producer or system maintainer. The digest checks that the bytes match the declared identity. The URI allowlist comes from node configuration and should name a dedicated, trusted source. The setup command prepares paths and does not launch artifact code or alter job state.

## Verify locally

Run with Python 3.11 or newer.

```sh
python3 tools/artifacts/tests/test_artifacts.py OfflineTests -v
python3 tools/artifacts/tests/test_artifacts.py -v
```

The full suite also needs installed backend Composer dependencies and the PHP cURL, SQLite PDO and DOM extensions. It starts servers on OS-assigned loopback ports. The CEM server dispatches through the real v1 router and controller against an in-memory SQLite fixture. The test runs the verified synthetic binary, then uses the same cache with the build server stopped.

## Planned and observed artifacts

New evaluations freeze the setup settings when each job is scheduled. A CEM node that requests both `protocol=attempt-v1` and `artifactManifest=1` receives the manifest from that job’s benchmark binding. The binding must include every configured setup artifact with the matching digest and size. Use relative paths under the build output directory or a permitted HTTPS origin for bootstrap downloads. A pre-provisioned worker can execute directly from its digest-addressed cache.

The worker records the digests and sizes that it verified on the attempt. A retry keeps the expected identities and records its own observations. Existing jobs without a saved plan use their original setup settings.
