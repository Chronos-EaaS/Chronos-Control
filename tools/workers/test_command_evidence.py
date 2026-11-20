"""Check the archive uploaded by the reference worker through its normal driver path."""
import importlib.util
import io
from pathlib import Path
import tempfile
import unittest
from zipfile import ZipFile


loader = importlib.util.spec_from_file_location("command_worker", Path(__file__).with_name("chronos-command.py"))
worker = importlib.util.module_from_spec(loader)
loader.loader.exec_module(worker)


class RecordingAgent:
    def __init__(self):
        self.status = None
        self.archive = None

    def upload(self, job_id, stream):
        self.archive = stream.read()

    def request(self, method, job_id, action=None, fields=None):
        if fields and "status" in fields:
            self.status = fields["status"]
        return {"status": self.status}


class CommandEvidenceTest(unittest.TestCase):
    def run_example(self, directory, command):
        driver = directory / "driver"
        driver.write_text("#!/bin/sh\nmkdir -p evidence/run\necho 123 > evidence/run/sample\n" + command)
        driver.chmod(0o700)
        agent = RecordingAgent()
        try:
            worker.run_driver(agent, 3, {}, 15, directory, {"driver": str(driver)}, 5)
        except worker.AgentError:
            if agent.status != -2:
                raise
        with ZipFile(io.BytesIO(agent.archive)) as archive:
            self.assertEqual(archive.read("evidence/run/sample"), b"123\n")
            self.assertIn("stderr.log", archive.namelist())
            self.assertNotIn("evidence/link", archive.namelist())
        return agent.status

    def test_success_failure_and_rejected_link_keep_raw_data(self):
        for command, expected in [("echo '{}'", 3), ("exit 2", -2), ("ln -s run/sample evidence/link\necho '{}'", -2)]:
            with self.subTest(command=command), tempfile.TemporaryDirectory() as temporary:
                self.assertEqual(self.run_example(Path(temporary), command), expected)


if __name__ == "__main__":
    unittest.main()
