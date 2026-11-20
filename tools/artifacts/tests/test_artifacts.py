#!/usr/bin/env python3
"""Synthetic build, registry, and CEM contract tests using disposable state."""

import contextlib
import hashlib
import http.server
import json
import os
from pathlib import Path
import re
import select
import shutil
import subprocess
import tempfile
import threading
import unittest
import urllib.request
import zipfile
from concurrent.futures import ThreadPoolExecutor

TOOLS = Path(__file__).resolve().parents[1]
REPO = TOOLS.parents[1]
CLI = TOOLS / "artifacts.php"
PAYLOAD = b"synthetic artifact\x00\n"


def identity(role, uri, data):
    return {
        "role": role,
        "uri": uri,
        "sha256": hashlib.sha256(data).hexdigest(),
        "sizeBytes": len(data),
    }


@contextlib.contextmanager
def build_server(files):
    requests = []

    class Handler(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            requests.append(self.path)
            if self.path == "/build/redirect":
                self.send_response(302)
                self.send_header("Location", "/build/payload")
                self.end_headers()
                return
            body = files.get(self.path)
            if body is None:
                self.send_error(404)
                return
            self.send_response(200)
            # Exercise the streaming cap without depending on Content-Length.
            self.end_headers()
            with contextlib.suppress(BrokenPipeError, ConnectionResetError):
                self.wfile.write(body)

        def log_message(self, *_args):
            pass

    with http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler) as server:
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            yield f"http://127.0.0.1:{server.server_port}/build", requests
        finally:
            server.shutdown()
            thread.join()


@contextlib.contextmanager
def cem_server(manifest):
    tests = TOOLS / "tests"
    with subprocess.Popen(
        [
            "php", "-d", "allow_url_fopen=0", "-S", "127.0.0.1:0",
            "-t", str(tests), str(tests / "cem-router.php"),
        ],
        cwd=REPO,
        env={**os.environ, "CHRONOS_TEST_MANIFEST": str(manifest)},
        stderr=subprocess.PIPE,
        text=True,
    ) as server:
        try:
            if not select.select([server.stderr], [], [], 5)[0]:
                raise RuntimeError("Timed out starting the synthetic CEM server")
            startup = server.stderr.readline()
            address = re.search(r"http://127\.0\.0\.1:\d+", startup)
            if not address:
                raise RuntimeError(startup)
            yield address.group()
        finally:
            server.terminate()
            server.wait(timeout=5)


class ArtifactTestCase(unittest.TestCase):
    def setUp(self):
        temporary = self.enterContext(tempfile.TemporaryDirectory(prefix="chronos-artifacts-"))
        self.root = Path(temporary).resolve()
        self.source = self.root / "source"
        self.source.mkdir()
        self.cache = self.root / "cache"
        (self.source / "payload").write_bytes(PAYLOAD)
        self.entry = identity("sut", "payload", PAYLOAD)
        self.manifest = self.write_manifest(self.entry)

    def write_manifest(self, *entries, version=1):
        path = self.root / "manifest.json"
        path.write_text(json.dumps({"schemaVersion": version, "artifacts": list(entries)}))
        return path

    def cli(self, command, *args, error=None, provenance=None):
        env = dict(os.environ)
        for key in ["CHRONOS_SOURCE_REVISION", "CHRONOS_BUILD_ID"]:
            env.pop(key, None)
        if provenance:
            env.update(provenance)
        result = subprocess.run(
            ["php", "-d", "allow_url_fopen=0", CLI, command, *args],
            cwd=REPO, env=env, text=True, capture_output=True, timeout=15,
        )
        if error is not None:
            self.assertEqual(result.returncode, 1, result.stderr)
            self.assertEqual(result.stdout, "")
            self.assertIn(error, result.stderr)
            return
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def register(self, *, source=None, error=None):
        return self.cli("register", self.manifest, self.cache, source or self.source, error=error)


class OfflineTests(ArtifactTestCase):
    def test_generate_measurements_and_optional_provenance(self):
        (self.source / "empty").touch()
        result = self.cli(
            "generate", self.source, "sut=payload", "empty=empty",
            provenance={"CHRONOS_SOURCE_REVISION": "synthetic-revision"},
        )
        self.assertEqual(result["artifacts"], [
            {**self.entry, "sourceRevision": "synthetic-revision"},
            {**identity("empty", "empty", b""), "sourceRevision": "synthetic-revision"},
        ])
        result = self.cli("generate", self.source, "sut=payload")
        self.assertEqual(result["artifacts"], [self.entry])

    def test_reject_invalid_manifests_and_limits(self):
        cases = [
            ([self.entry], 2, "schemaVersion 1"),
            ([], 1, "1 to 64"),
            ([self.entry] * 65, 1, "1 to 64"),
            ([self.entry] * 2, 1, "Duplicate artifact role"),
            ([{**self.entry, "sha256": "a" * 63}], 1, "SHA-256"),
            ([{**self.entry, "sizeBytes": "22"}], 1, "sizeBytes must be"),
            ([{**self.entry, "sizeBytes": 2**30 + 1}], 1, "sizeBytes must be"),
            ([{**self.entry, "sizeBytes": 2**30, "role": f"role-{i}"} for i in range(3)], 1, "2 GiB"),
            ([{**self.entry, "buildId": None}], 1, "buildId must be"),
        ]
        for entries, version, error in cases:
            with self.subTest(error=error):
                self.cli("validate", self.write_manifest(*entries, version=version), error=error)
        self.manifest.write_text(" " * (60 * 1024 + 1))
        self.cli("validate", self.manifest, error="at most 61440 bytes")

    def test_reject_disallowed_uris_and_symlink_escape(self):
        for uri in (
            "/etc/passwd", "file:///etc/passwd", "ftp://example.test/payload",
            "http://example.test/payload", "//example.test/payload", "../payload", "a/../payload", "a//payload",
            "a\\payload", "https://example.test/%2e%2e/payload", "https://example.test/a/../payload",
            "https://user:secret@example.test/payload", "https://example.test/payload?token=secret",
        ):
            with self.subTest(uri=uri):
                self.cli("validate", self.write_manifest({**self.entry, "uri": uri}), error="Artifact entry 1")
        outside = self.root / "outside"
        outside.write_bytes(PAYLOAD)
        (self.source / "link").symlink_to(outside)
        self.write_manifest({**self.entry, "uri": "link"})
        self.register(error="outside the local source")
        self.assertEqual(list(self.cache.iterdir()), [])

    def test_registration_and_offline_cache_revalidate_content(self):
        self.write_manifest({**self.entry, "buildId": "synthetic-build-7"})
        registration = self.register()
        manifest_path = Path(registration["manifestPath"])
        self.assertEqual(manifest_path.name, registration["manifestSha256"])
        self.assertEqual(manifest_path.name, hashlib.sha256(manifest_path.read_bytes()).hexdigest())
        self.assertEqual(json.loads(manifest_path.read_text())["artifacts"][0]["buildId"], "synthetic-build-7")
        shutil.rmtree(self.source)
        self.assertEqual(self.register(), registration)
        artifact = Path(registration["artifacts"][0]["path"])
        self.assertEqual(artifact.read_bytes(), PAYLOAD)
        artifact.chmod(0o600)
        artifact.write_bytes(b"x" * len(PAYLOAD))
        self.register(error="could not be used: SHA-256 mismatch")

    def test_cache_refuses_symlinks_and_untrusted_directory(self):
        self.cache.mkdir()
        entry = self.cache / self.entry["sha256"]
        entry.symlink_to(self.source / "payload")
        self.register(error="Expected an existing regular file")
        self.cache.chmod(0o777)
        self.register(error="without group or public write access")

    def test_missing_corrupt_and_wrong_sized_source_leaves_no_publication(self):
        for data, error in [
            (None, "Artifact file is missing from the local source"),
            (b"x" * len(PAYLOAD), "SHA-256 mismatch"),
            (PAYLOAD[:-1], "Size mismatch"),
            (PAYLOAD + b"x", "Source exceeds the declared sizeBytes"),
        ]:
            with self.subTest(error=error):
                file = self.source / "payload"
                file.unlink(missing_ok=True)
                if data is not None:
                    file.write_bytes(data)
                self.register(error=f"Artifact sut: {error}")
                self.assertEqual(list(self.cache.iterdir()), [])

    def test_incomplete_registration_only_retains_verified_blobs(self):
        extra = identity("second", "missing", b"second")
        self.write_manifest(self.entry, extra)
        self.register(error="Artifact second: Artifact file is missing")
        self.assertEqual([path.name for path in self.cache.iterdir()], [self.entry["sha256"]])
        (self.source / "missing").write_bytes(b"second")
        registration = self.register()
        self.assertEqual([entry["role"] for entry in registration["artifacts"]], ["sut", "second"])
        self.assertTrue(Path(registration["manifestPath"]).is_file())

    def test_archives_remain_opaque_bytes(self):
        archive = self.source / "archive.zip"
        with zipfile.ZipFile(archive, "w") as output:
            output.writestr("../escaped.txt", "Synthetic archive member")
        self.write_manifest(identity("archive", archive.name, archive.read_bytes()))
        placement = Path(self.register()["artifacts"][0]["path"])
        self.assertEqual(placement.read_bytes(), archive.read_bytes())
        self.assertFalse((self.root / "escaped.txt").exists())
        self.assertEqual(len(list(self.cache.iterdir())), 2)


class HttpTests(ArtifactTestCase):
    def test_http_cem_setup_consumes_the_verified_build(self):
        build = self.root / "build"
        subprocess.run(
            [TOOLS / "example/build.sh", build], cwd=REPO, check=True, timeout=15,
            env={**os.environ, "CHRONOS_SOURCE_REVISION": "synthetic-revision", "CHRONOS_BUILD_ID": "synthetic:1:1"},
        )
        manifest = build / "manifest.json"
        files = {f"/build/{name}": (build / name).read_bytes() for name in ["marker", "workload.txt"]}
        response_path = self.root / "cem.json"
        with cem_server(manifest) as cem, build_server(files) as (source, requests):
            endpoint = f"{cem}/api/v1/cem/uniqueId=local-node/version=2/environment=fixture"

            def response(arguments):
                with urllib.request.urlopen(endpoint + arguments, timeout=5) as http:
                    self.assertEqual(http.headers.get_content_type(), "application/json")
                    return json.load(http)

            legacy = response("/jobId=2")
            self.assertEqual(legacy["status"]["code"], 200)
            self.assertEqual(legacy["response"]["setup"], {"mode": "legacy"})
            self.assertNotIn("artifactManifest", legacy["response"])
            for capability in ("", "/artifactManifest=2"):
                unsupported = response("/jobId=1" + capability)
                self.assertEqual(unsupported["status"]["code"], 600)
                self.assertIn("artifactManifest=1", unsupported["status"]["message"])
                self.assertNotIn("response", unsupported)
            invalid = response("/jobId=3/artifactManifest=1")
            self.assertIn("Invalid CEM setting artifactManifestV1", invalid["status"]["message"])
            selected = response("/jobId=1/artifactManifest=1")
            self.assertEqual(selected["status"]["code"], 200)
            data = selected["response"]
            self.assertEqual(data["setup"], {"mode": "synthetic"})
            self.assertIn("<mode>synthetic</mode>", data["cdl"])
            self.assertNotIn("artifactManifestV1", data["cdl"])
            self.assertEqual(data["artifactManifest"], json.loads(manifest.read_text()))
            self.assertEqual(requests, [])

            response_path.write_text(json.dumps(legacy))
            self.cli("setup", response_path, self.cache, source, error="missing artifactManifest")
            response_path.write_text(json.dumps(selected))
            prepared = self.cli("setup", response_path, self.cache, source)
            self.assertEqual(requests, ["/build/marker", "/build/workload.txt"])

        # The same approved URLs can be resolved offline from the verified cache.
        self.assertEqual(self.cli("setup", response_path, self.cache, source), prepared)
        sut, workload = prepared["artifacts"]
        executable = self.root / "verified-marker"
        shutil.copyfile(sut["path"], executable)
        executable.chmod(0o700)
        marker = subprocess.run([executable], text=True, capture_output=True, check=True, timeout=5)
        self.assertEqual(marker.stdout, "Verified synthetic Chronos build\n")
        self.assertEqual(Path(workload["path"]).read_bytes(), files["/build/workload.txt"])
        self.assertEqual(sut["sourceRevision"], "synthetic-revision")
        self.assertEqual(sut["buildId"], "synthetic:1:1")

    def test_http_source_policy_stream_cap_status_and_hash(self):
        files = {"/build/payload": PAYLOAD, "/build/oversize": PAYLOAD * 10, "/build/changed": b"x" * len(PAYLOAD)}
        with build_server(files) as (source, requests):
            for uri, error in [
                (source + "x/payload", "outside the configured source"),
                (source.replace("127.0.0.1", "localhost") + "/payload", "Artifact entry 1"),
                ("redirect", "HTTP 302"),
                ("missing", "HTTP 404"),
                ("oversize", "exceeds the declared sizeBytes"),
                ("changed", "SHA-256 mismatch"),
            ]:
                with self.subTest(uri=uri):
                    self.write_manifest({**self.entry, "uri": uri})
                    self.register(source=source, error=error)
                    if self.cache.exists():
                        self.assertEqual(list(self.cache.iterdir()), [])
            self.assertEqual(requests, ["/build/redirect", "/build/missing", "/build/oversize", "/build/changed"])
            self.write_manifest({**self.entry, "uri": source + "/payload"})
            self.assertEqual(self.register(source=source)["artifacts"][0]["sha256"], self.entry["sha256"])

    def test_parallel_writers_converge_on_identical_verified_files(self):
        with build_server({"/build/payload": PAYLOAD}) as (source, _):
            with ThreadPoolExecutor(max_workers=8) as executor:
                placements = list(executor.map(lambda _: self.register(source=source), range(8)))
        self.assertEqual(placements, [placements[0]] * 8)
        self.assertEqual(set(self.cache.iterdir()), {
            Path(placements[0]["manifestPath"]),
            Path(placements[0]["artifacts"][0]["path"]),
        })


if __name__ == "__main__":
    unittest.main()
