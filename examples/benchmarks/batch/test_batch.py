"""Local synthetic fixtures and real codec smoke tests."""

import fcntl
import hashlib
import json
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from adapters import Gzip, Zstandard


SCRIPT = Path(__file__).with_name("batch.py").resolve()


def read_json(path):
    return json.loads(path.read_text())


def file_identity(path):
    data = path.read_bytes()
    return {"sizeBytes": len(data), "sha256": hashlib.sha256(data).hexdigest()}


class AdapterTests(unittest.TestCase):
    def test_arguments(self):
        executable = Path("/tools with spaces/codec")
        input_path = Path("/synthetic path/input - 01.bin")
        output_path = Path("/synthetic path/archive")
        self.assertEqual(Gzip(6).compress(executable, input_path), [
            str(executable), "-n", "-6", "-c", "--", str(input_path),
        ])
        self.assertEqual(Zstandard(3).compress(executable, input_path), [
            str(executable), "--single-thread", "--check", "--no-progress",
            "-3", "-c", "--", str(input_path),
        ])
        for adapter in (Gzip(6), Zstandard(3)):
            self.assertEqual(adapter.decompress(executable, output_path), [
                str(executable), "-d", "-c", "--", str(output_path),
            ])


class BatchTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="chronos-batch-test-")
        self.addCleanup(temporary.cleanup)
        self.base = Path(temporary.name).resolve()
        self.study = self.base / "study with spaces; dollar$"

    def cli(self, *args, code=0):
        completed = subprocess.run(
            [sys.executable, str(SCRIPT), *map(str, args)],
            capture_output=True, text=True, timeout=15,
        )
        self.assertEqual(completed.returncode, code, completed.stdout + completed.stderr)
        return completed

    def plan(self, *options):
        self.cli("plan", self.study, "--repetitions", 1, "--size-bytes", 98_321, *options)
        return read_json(self.study / "plan.json")

    def attempt(self, repetition="gzip-001", attempt=1):
        directory = self.study / "attempts" / repetition / f"attempt-{attempt:06d}"
        return directory, read_json(directory / "result.json")

    def executable(self, source, name="test codec"):
        path = self.base / name
        path.write_text(source)
        path.chmod(0o755)
        return path

    def test_plan_is_deterministic_and_write_once(self):
        plan = self.plan()
        self.assertEqual(plan["repetitions"], [
            {"repetitionId": "gzip-001", "codec": "gzip"},
            {"repetitionId": "zstd-001", "codec": "zstd"},
        ])
        expected = {"sha256": plan["input"]["sha256"], "sizeBytes": plan["input"]["sizeBytes"]}
        self.assertEqual(expected, file_identity(self.study / "input.bin"))
        plan_file = self.study / "plan.json"
        initial = plan_file.read_bytes()
        self.cli("plan", self.study, code=2)
        self.assertEqual(initial, plan_file.read_bytes())
        self.assertEqual(expected, file_identity(self.study / "input.bin"))

        second = self.base / "second"
        self.cli("plan", second, "--size-bytes", 98_321, "--seed", 2027)
        self.assertEqual(expected, file_identity(second / "input.bin"))
        third = self.base / "third"
        self.cli("plan", third, "--size-bytes", 98_321, "--seed", 2028)
        self.assertNotEqual(expected["sha256"], file_identity(third / "input.bin")["sha256"])

        plan_file.write_bytes(initial + b" ")
        response = self.cli("run", self.study, code=2)
        self.assertIn("Plan digest mismatch", response.stderr)
        self.assertFalse((self.study / "attempts").exists())

    def test_replaced_plan_cannot_reuse_existing_attempt(self):
        self.plan("--codec", "gzip", "--gzip-executable", self.base / "missing")
        self.cli("run", self.study, code=1)
        directory, _ = self.attempt()
        original = (directory / "result.json").read_bytes()
        replacement = self.base / "replacement"
        self.cli("plan", replacement, "--codec", "gzip")
        for name in ("plan.json", "plan.sha256"):
            shutil.copyfile(replacement / name, self.study / name)
        response = self.cli("run", self.study, "--repetition", "gzip-001", "--retry", code=2)
        self.assertIn("Existing attempt refers to a different plan", response.stderr)
        self.assertEqual(original, (directory / "result.json").read_bytes())
        self.assertFalse((directory.parent / "attempt-000002").exists())

    def test_bad_levels_fail_before_study_creation(self):
        self.cli("plan", self.study, "--gzip-level", 10, code=2)
        self.cli("plan", self.study, "--zstd-level", 20, code=2)
        self.assertFalse(self.study.exists())

    def test_missing_executable_leaves_failure_evidence(self):
        self.plan("--codec", "gzip", "--gzip-executable", self.base / "missing")
        response = self.cli("run", self.study, code=1)
        directory, result = self.attempt()
        self.assertIn("Executable missing or not runnable", response.stderr)
        self.assertEqual(result["setup"]["status"], "failed")
        self.assertEqual(result["status"], "failed")
        self.assertIsNone(result["measurement"])
        self.assertEqual(read_json(directory / "attempt.json")["attemptId"], result["attemptId"])

    def test_executable_with_broken_interpreter_records_spawn_error(self):
        executable = self.executable("#!/no/such/interpreter\n")
        self.plan("--codec", "gzip", "--gzip-executable", executable)
        self.cli("run", self.study, code=1)
        directory, result = self.attempt()
        probe = result["setup"]["executable"]["versionProbe"]
        self.assertEqual(probe["status"], "spawn-error")
        self.assertIsNone(probe["exitCode"])
        self.assertTrue((directory / probe["stderr"]).is_file())

    def test_failing_version_probe_retains_version_streams(self):
        executable = self.executable("#!/bin/sh\nprintf 'version failed\\n' >&2\nexit 14\n")
        self.plan("--codec", "gzip", "--gzip-executable", executable)
        self.cli("run", self.study, code=1)
        directory, result = self.attempt()
        evidence = result["setup"]["executable"]
        self.assertEqual(evidence["versionProbe"]["exitCode"], 14)
        self.assertEqual(evidence["versionBanner"], "version failed")
        self.assertEqual((directory / "version.stderr.log").read_text(), "version failed\n")
        self.assertIsNone(result["measurement"])

    def test_compression_exit_code_retains_partial_archive_and_stderr(self):
        executable = self.executable("""#!/bin/sh
if [ "$1" = "--version" ]; then
    printf 'test-gzip v1\n' >&2
    exit 0
fi
printf partial
printf 'injected compressor failure\n' >&2
exit 23
""")
        link = self.base / "invocation alias"
        link.symlink_to(executable)
        self.plan("--codec", "gzip", "--gzip-executable", link, "--warmups", 0)
        self.cli("run", self.study, code=1)
        directory, result = self.attempt()
        evidence = result["setup"]["executable"]
        self.assertEqual(evidence["versionBanner"], "test-gzip v1")
        self.assertEqual(evidence["identity"], {
            "invokedPath": str(link), "resolvedPath": str(executable), **file_identity(executable),
        })
        measured = result["measurement"]
        command = measured["compression"]
        self.assertEqual(command["exitCode"], 23)
        self.assertEqual(command["argv"][0], str(link))
        self.assertEqual((directory / command["stderr"]).read_text(), "injected compressor failure\n")
        self.assertEqual((directory / measured["output"]["path"]).read_bytes(), b"partial")
        self.assertNotIn("validation", measured)

    def test_timeout_retains_exit_and_stops_attempt(self):
        executable = self.executable("""#!/bin/sh
if [ "$1" = "--version" ]; then
    printf 'test-gzip v1\n'
    exit 0
fi
exec /bin/sleep 10
""")
        self.plan("--codec", "gzip", "--gzip-executable", executable,
                  "--timeout-seconds", 1, "--warmups", 0)
        self.cli("run", self.study, code=1)
        _, result = self.attempt()
        command = result["measurement"]["compression"]
        self.assertEqual(command["status"], "timed-out")
        self.assertEqual(command["exitCode"], -9)
        self.assertEqual(result["status"], "failed")

    def test_warmup_failure_blocks_measurement(self):
        executable = self.executable("""#!/bin/sh
if [ "$1" = "--version" ]; then
    printf 'test-gzip v1\n'
    exit 0
fi
exit 9
""")
        self.plan("--codec", "gzip", "--gzip-executable", executable)
        self.cli("run", self.study, code=1)
        _, result = self.attempt()
        self.assertEqual(result["warmups"][0]["compression"]["exitCode"], 9)
        self.assertIsNone(result["measurement"])

    def test_zero_exit_from_decoder_cannot_hide_wrong_bytes(self):
        executable = self.executable("""#!/bin/sh
case "$1" in
    --version) printf 'test-gzip v1\n' ;;
    -d) printf 'incorrect' ;;
    *) printf '\\037\\213' ;;
esac
""")
        self.plan("--codec", "gzip", "--gzip-executable", executable, "--warmups", 0)
        self.cli("run", self.study, code=1)
        directory, result = self.attempt()
        validation = result["measurement"]["validation"]
        self.assertEqual(validation["decompression"]["exitCode"], 0)
        self.assertFalse(validation["byteMatch"])
        self.assertFalse(validation["identityMatch"])
        self.assertTrue(validation["formatMagicMatch"])
        self.assertFalse(list(directory.glob("restore-*")))

    def test_input_mutation_stops_before_execution(self):
        self.plan("--codec", "gzip")
        input_path = self.study / "input.bin"
        input_path.write_bytes(b"x" + input_path.read_bytes()[1:])
        self.cli("run", self.study, code=1)
        _, result = self.attempt()
        self.assertIn("Input identity differs", result["error"])
        self.assertEqual(result["setup"]["executable"], {})

    def test_running_study_cannot_allocate_another_attempt(self):
        self.plan("--codec", "gzip")
        with (self.study / ".run.lock").open("a") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            response = self.cli("run", self.study, code=2)
            self.assertIn("Another runner", response.stderr)
        self.assertFalse((self.study / "attempts").exists())

    def assert_validated_sample(self, directory, sample, plan):
        archive = directory / sample["output"]["path"]
        output = dict(sample["output"])
        del output["path"]
        self.assertEqual(output, file_identity(archive))
        self.assertEqual(sample["compression"]["exitCode"], 0)
        self.assertGreater(sample["compression"]["elapsedNs"], 0)
        self.assertTrue((directory / sample["compression"]["stderr"]).is_file())
        validation = sample["validation"]
        self.assertEqual(validation["status"], "succeeded")
        self.assertEqual(validation["decompression"]["exitCode"], 0)
        self.assertEqual(validation["restored"], file_identity(self.study / "input.bin"))
        self.assertEqual(validation["restored"]["sha256"], plan["input"]["sha256"])
        self.assertTrue(validation["byteMatch"] and validation["identityMatch"] and validation["formatMagicMatch"])
        self.assertFalse((directory / validation["decompression"]["stdout"]).exists())
        self.assertTrue((directory / validation["decompression"]["stderr"]).is_file())
        self.assertFalse(list(directory.glob("restore-*")))

    def assert_codec_smoke(self, codec):
        plan = self.plan("--codec", codec)
        self.cli("run", self.study)
        directory, result = self.attempt(f"{codec}-001")
        self.assertEqual(result["status"], "succeeded")
        self.assertEqual(result["dataClass"], "local-smoke")
        self.assertEqual(result["studyId"], plan["studyId"])
        self.assertEqual(result["planSha256"], file_identity(self.study / "plan.json")["sha256"])
        evidence = result["setup"]["executable"]
        binary = Path(evidence["identity"]["resolvedPath"])
        self.assertEqual(evidence["identity"]["sha256"], file_identity(binary)["sha256"])
        self.assertTrue(evidence["versionBanner"])
        self.assertEqual(len(result["warmups"]), 1)
        for sample in (*result["warmups"], result["measurement"]):
            self.assert_validated_sample(directory, sample, plan)

    @unittest.skipUnless(shutil.which("gzip"), "gzip CLI is missing")
    def test_gzip_smoke(self):
        self.assert_codec_smoke("gzip")

    @unittest.skipUnless(shutil.which("zstd"), "zstd CLI is missing")
    def test_zstd_smoke(self):
        self.assert_codec_smoke("zstd")

    @unittest.skipUnless(shutil.which("gzip"), "gzip CLI is missing")
    def test_corruption_and_recovery_preserve_first_attempt(self):
        plan = self.plan("--codec", "gzip")
        self.cli("run", self.study, "--repetition", "gzip-001", "--inject-corruption", code=1)
        previous, failed = self.attempt()
        measurement = failed["measurement"]
        self.assertEqual(failed["status"], "failed")
        self.assertEqual(measurement["compression"]["exitCode"], 0)
        self.assertNotEqual(measurement["validation"]["decompression"]["exitCode"], 0)
        self.assertNotEqual(measurement["failureInjection"]["before"]["sha256"], measurement["output"]["sha256"])
        self.assertFalse(list(previous.glob("restore-*")))
        files = {path.name: path.read_bytes() for path in previous.iterdir()}

        self.cli("run", self.study, code=1)
        self.assertFalse((previous.parent / "attempt-000002").exists())
        self.cli("run", self.study, "--repetition", "gzip-001", "--retry")
        directory, recovered = self.attempt(attempt=2)
        self.assertEqual(recovered["status"], "succeeded")
        for key in ("studyId", "planSha256", "repetitionId"):
            self.assertEqual(failed[key], recovered[key])
        self.assertEqual(recovered["retryOf"], failed["attemptId"])
        self.assertEqual(files, {path.name: path.read_bytes() for path in previous.iterdir()})
        self.assert_validated_sample(directory, recovered["measurement"], plan)
        self.cli("run", self.study)
        self.cli("run", self.study, "--repetition", "gzip-001", "--retry", code=2)
        self.assertFalse((previous.parent / "attempt-000003").exists())

    @unittest.skipUnless(shutil.which("gzip"), "gzip CLI is missing")
    def test_incomplete_attempt_can_be_retried(self):
        self.plan("--codec", "gzip")
        previous = self.study / "attempts" / "gzip-001" / "attempt-000001"
        previous.mkdir(parents=True)
        (previous / "partial.log").write_text("retained")
        self.cli("run", self.study, code=1)
        self.cli("run", self.study, "--repetition", "gzip-001", "--retry")
        _, recovered = self.attempt(attempt=2)
        self.assertEqual(recovered["status"], "succeeded")
        self.assertEqual(recovered["retryOf"], previous.name)
        self.assertEqual((previous / "partial.log").read_text(), "retained")


if __name__ == "__main__":
    unittest.main()
