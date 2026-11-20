"""Smoke the staged study drivers with the command worker's artifact layout."""

import hashlib
from importlib.util import module_from_spec, spec_from_file_location
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest


HERE = Path(__file__).resolve().parent
WORKER = HERE.parents[1] / "tools" / "workers" / "chronos-command.py"
sys.path.insert(0, str(WORKER.parent))
loader = spec_from_file_location("command_worker", WORKER)
worker = module_from_spec(loader)
loader.loader.exec_module(worker)


class CommandDriverTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix="chronos-study-contract-")
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)

    def stage(self, pilot, *options):
        cache = self.root / "cache"
        output = self.root / "requests"
        subprocess.run(
            [sys.executable, str(HERE / "stage.py"), pilot, "--cache", str(cache),
             "--output", str(output), "--system-id", "1", *options],
            check=True, timeout=60,
        )
        benchmark, binding, experiment, evaluation = (
            json.loads((output / f"{name}.json").read_text())
            for name in ("benchmark", "binding", "experiment", "evaluation")
        )
        definition = binding["definition"]
        work = self.root / "work"
        artifacts = work / "artifacts"
        artifacts.mkdir(parents=True)
        paths = {entry["role"]: str(worker.stage_artifact(entry, cache, artifacts))
                 for entry in definition["artifacts"]["artifacts"]}
        parameters = evaluation["jobs"][0]["parameters"]
        effective = {definition["parameterMap"][name]: value for name, value in parameters.items()}
        specification = {
            "schemaVersion": 1, "protocol": "attempt-v1",
            "system": {"id": 1, "identifier": "demoall", "version": definition["systemVersion"]},
            "benchmark": {"id": 1, **benchmark},
            "binding": {"id": 1, "version": binding["version"]},
            "adapter": definition["adapter"], "artifacts": definition["artifacts"],
            "environment": evaluation["environment"], "phases": 0 if experiment["warmUp"] else 2,
            "parameters": parameters, "effectiveParameters": {**definition["constants"], **effective},
            "setup": {}, "cdl": "",
        }
        specification["sha256"] = hashlib.sha256(json.dumps(specification).encode()).hexdigest()
        request = {"repetitionId": 23, "attemptId": 42, "specification": specification, "artifactPaths": paths}
        return request, work

    def run_driver(self, request, work):
        return subprocess.run(
            [request["artifactPaths"]["driver"]], input=json.dumps(request),
            cwd=work, env={"PATH": os.defpath}, capture_output=True, text=True, timeout=180,
        )

    def assert_success(self, completed, request, work):
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertEqual(json.loads((work / "evidence/chronos.json").read_text()), request)
        result = json.loads(completed.stdout)
        self.assertEqual(set(result), set(request["specification"]["benchmark"]["contract"]["results"]))
        return result

    def check_batch(self, codec):
        request, work = self.stage(codec)
        result = self.assert_success(self.run_driver(request, work), request, work)
        input_file = work / "evidence/study/input.bin"
        self.assertEqual(result["restoredSha256"], hashlib.sha256(input_file.read_bytes()).hexdigest())
        self.assertEqual(result["inputBytes"], input_file.stat().st_size)
        self.assertGreater(result["compressionElapsedNs"], 0)
        attempt, = (work / "evidence").rglob("result.json")
        detail = json.loads(attempt.read_text())
        self.assertEqual(detail["status"], "succeeded")
        self.assertEqual(len(detail["warmups"]), 1)
        self.assertEqual(result["compressedBytes"], (attempt.parent / detail["measurement"]["output"]["path"]).stat().st_size)

    @unittest.skipUnless(shutil.which("gzip"), "gzip is required")
    def test_gzip(self):
        self.check_batch("gzip")

    @unittest.skipUnless(shutil.which("zstd"), "zstd is required")
    def test_zstd(self):
        self.check_batch("zstd")

    @unittest.skipUnless(shutil.which("gzip"), "gzip is required")
    def test_reject_wrong_codec_digest_and_keep_failed_evidence(self):
        request, work = self.stage("gzip", "--skip-warmup")
        request["specification"]["system"]["version"] = "0" * 64
        completed = self.run_driver(request, work)
        self.assertEqual(completed.returncode, 1, completed.stderr)
        self.assertIn("Codec executable digest differs", completed.stderr)
        self.assertEqual(completed.stdout, "")
        evidence, = (work / "evidence").rglob("result.json")
        self.assertEqual(json.loads(evidence.read_text())["status"], "failed")
        self.assertFalse(list((work / "evidence").rglob("version.stdout.log")))

    @unittest.skipUnless(shutil.which("gzip"), "gzip is required")
    def test_reject_conflicting_warmup_with_no_measurement(self):
        request, work = self.stage("gzip", "--skip-warmup")
        request["specification"]["phases"] = 0
        completed = self.run_driver(request, work)
        self.assertEqual(completed.returncode, 1, completed.stderr)
        self.assertIn("warmups must match", completed.stderr)
        self.assertEqual(completed.stdout, "")
        self.assertTrue((work / "evidence/chronos.json").is_file())
        self.assertFalse((work / "evidence/study").exists())

    def check_database(self, engine):
        request, work = self.stage(engine)
        result = self.assert_success(self.run_driver(request, work), request, work)
        self.assertGreater(result["events"], 0)
        self.assertGreater(result["sysbenchElapsedSeconds"], 0)
        evidence, = (work / "evidence").rglob("attempt.json")
        detail = json.loads(evidence.read_text())
        self.assertTrue(detail["eligible"])
        self.assertFalse(detail["teardownErrors"])
        self.assertEqual(detail["datasetBefore"], detail["datasetAfter"])
        self.assertTrue((evidence.parent / detail["measurement"]["stdout"]).is_file())

    @unittest.skipUnless(os.environ.get("CHRONOS_STUDY_DOCKER") == "1", "opt in to local Docker")
    def test_postgresql(self):
        self.check_database("postgresql")

    @unittest.skipUnless(os.environ.get("CHRONOS_STUDY_DOCKER") == "1", "opt in to local Docker")
    def test_mariadb(self):
        self.check_database("mariadb")


if __name__ == "__main__":
    unittest.main()
