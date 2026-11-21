"""Smoke the staged study drivers with the command worker's artifact layout."""

import hashlib
from importlib.util import module_from_spec, spec_from_file_location
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import Mock
from zipfile import ZipFile


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
        self.assertEqual(request["specification"]["environment"], "system-default")
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
        request, work = self.stage("gzip", "--skip-warmup", "--environment", "system-local")
        self.assertEqual(request["specification"]["environment"], "system-local")
        request["specification"]["phases"] = 0
        completed = self.run_driver(request, work)
        self.assertEqual(completed.returncode, 1, completed.stderr)
        self.assertIn("warmups must match", completed.stderr)
        self.assertEqual(completed.stdout, "")
        self.assertTrue((work / "evidence/chronos.json").is_file())
        self.assertFalse((work / "evidence/study").exists())

    def run_worker(self, request, work, timeout):
        agent = Mock()
        archive = self.root / "result.zip"
        agent.upload.side_effect = lambda job_id, stream: archive.write_bytes(stream.read())
        error = None
        try:
            worker.run_driver(agent, request["repetitionId"], request["specification"], request["attemptId"],
                              work, request["artifactPaths"], timeout)
        except worker.AgentError as failure:
            error = str(failure)
        return agent.request.call_args.kwargs["fields"], error, archive

    def set_parameters(self, request, **values):
        spec = request["specification"]
        spec["parameters"].update(values)
        spec["effectiveParameters"].update(values)
        del spec["sha256"]
        spec["sha256"] = hashlib.sha256(json.dumps(spec).encode()).hexdigest()

    def check_database(self, engine, tables):
        request, work = self.stage(engine)
        self.set_parameters(request, tables=tables, rowsPerTable=100)
        fields, error, archive = self.run_worker(request, work, timeout=180)
        self.assertEqual(fields["status"], 3, error)
        result = json.loads(fields["result"])
        self.assertEqual(set(result), set(request["specification"]["benchmark"]["contract"]["results"]))
        self.assertGreater(result["events"], 0)
        self.assertGreater(result["sysbenchElapsedSeconds"], 0)
        with ZipFile(archive) as bundle:
            self.assertEqual(json.loads(bundle.read("evidence/chronos.json")), request)
            attempt, = [name for name in bundle.namelist() if name.endswith("/attempt.json")]
            detail = json.loads(bundle.read(attempt))
            self.assertTrue(detail["eligible"])
            self.assertFalse(detail["teardownErrors"])
            self.assertEqual(len(detail["datasetBefore"]), tables)
            self.assertEqual(detail["datasetBefore"], detail["datasetAfter"])
            directory = Path(attempt).parent
            self.assertTrue(bundle.read(str(directory / detail["measurement"]["stdout"])))
            for stage in ("before", "after"):
                data = bundle.read(str(directory / f"{stage}-table-checks.zip"))
                with ZipFile(io.BytesIO(data)) as checks:
                    self.assertEqual(len(checks.namelist()), 6 * tables)
                    for name in checks.namelist():
                        if name.endswith(".json"):
                            record = json.loads(checks.read(name))
                            self.assertEqual(record["exitCode"], 0)
                            self.assertTrue(checks.read(record["stdout"]))
                            self.assertIn(record["stderr"], checks.namelist())

    @unittest.skipUnless(os.environ.get("CHRONOS_STUDY_DOCKER") == "1", "opt in to local Docker")
    def test_postgresql(self):
        self.check_database("postgresql", 32)

    @unittest.skipUnless(os.environ.get("CHRONOS_STUDY_DOCKER") == "1", "opt in to local Docker")
    def test_mariadb(self):
        self.check_database("mariadb", 1)

    @unittest.skipUnless(os.environ.get("CHRONOS_STUDY_DOCKER") == "1", "opt in to local Docker")
    def test_worker_timeout_cleans_owned_docker_resources(self):
        request, work = self.stage("postgresql", "--skip-warmup")
        self.set_parameters(request, measureSeconds=60)
        fields, error, archive = self.run_worker(request, work, timeout=12)
        self.assertEqual(fields["status"], -2)
        self.assertIn("timeout", error)
        with ZipFile(archive) as bundle:
            attempt, = [name for name in bundle.namelist() if name.endswith("/attempt.json")]
            detail = json.loads(bundle.read(attempt))
            self.assertEqual(detail["status"], "cancelled")
            self.assertFalse(detail["teardownErrors"])
            self.assertTrue(any(name.endswith("start-database.json") for name in bundle.namelist()))
        values = request["specification"]["effectiveParameters"]
        docker = [values["dockerExecutable"], "--host", "unix://" + values["dockerSocket"]]
        for list_resources in (["ps", "-aq"], ["network", "ls", "-q"]):
            remaining = subprocess.run(docker + list_resources + ["--filter", "label=" + detail["dockerOwnershipLabel"]],
                                       check=True, capture_output=True, text=True, timeout=10)
            self.assertEqual(remaining.stdout.strip(), "")


if __name__ == "__main__":
    unittest.main()
