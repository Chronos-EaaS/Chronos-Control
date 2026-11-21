"""Check the archive uploaded by the reference worker through its normal driver path."""
import importlib.util
import io
from pathlib import Path
import shlex
import sys
import tempfile
import unittest
from unittest.mock import patch
from zipfile import ZipFile


loader = importlib.util.spec_from_file_location("command_worker", Path(__file__).with_name("chronos-command.py"))
worker = importlib.util.module_from_spec(loader)
loader.loader.exec_module(worker)


class RecordingAgent:
    def __init__(self):
        self.status = None
        self.archive = None
        self.log = []
        self.reason = None

    def upload(self, job_id, stream):
        self.archive = stream.read()

    def request(self, method, job_id, action=None, fields=None):
        if action == "appendLog":
            self.log.append(fields["log"])
        if fields and "status" in fields:
            self.status = fields["status"]
            self.reason = fields.get("reason")
        return {"status": self.status}


class CommandEvidenceTest(unittest.TestCase):
    def run_example(self, directory, command, *, agent=None, timeout=5):
        driver = directory / "driver"
        driver.write_text("#!/bin/sh\nmkdir -p evidence/run\necho 123 > evidence/run/sample\n" + command)
        driver.chmod(0o700)
        agent = agent or RecordingAgent()
        try:
            worker.run_driver(agent, 3, {}, 15, directory, {"driver": str(driver)}, timeout)
        except worker.AgentError:
            if agent.status != -2:
                raise
        if agent.archive:
            with ZipFile(io.BytesIO(agent.archive)) as archive:
                self.assertEqual(archive.read("evidence/run/sample"), b"123\n")
                self.assertIn("stderr.log", archive.namelist())
                self.assertNotIn("evidence/link", archive.namelist())
        return agent

    def test_success_failure_and_rejected_link_keep_raw_data(self):
        for command, expected in [("echo '{}'", 3), ("exit 2", -2), ("ln -s run/sample evidence/link\necho '{}'", -2)]:
            with self.subTest(command=command), tempfile.TemporaryDirectory() as temporary:
                agent = self.run_example(Path(temporary), command)
                self.assertEqual(agent.status, expected)
                self.assertIsNotNone(agent.archive)

    def test_log_failure_preserves_archive_and_reports_any_archive_failure(self):
        for archive_failed in (False, True):
            with self.subTest(archive_failed=archive_failed), tempfile.TemporaryDirectory() as temporary:
                agent = RecordingAgent()
                request = agent.request

                def fail_log(method, job_id, action=None, fields=None):
                    if action == "appendLog":
                        raise OSError("log interrupted")
                    return request(method, job_id, action, fields)

                agent.request = fail_log
                if archive_failed:
                    def fail_archive(job_id, stream):
                        raise OSError("archive interrupted")
                    agent.upload = fail_archive
                self.run_example(Path(temporary), "echo trace >&2; echo '{}'", agent=agent)
                self.assertEqual(agent.status, -2)
                self.assertIn("log interrupted", agent.reason)
                if archive_failed:
                    self.assertIn("archive interrupted", agent.reason)
                else:
                    with ZipFile(io.BytesIO(agent.archive)) as archive:
                        self.assertEqual(archive.read("stderr.log"), b"trace\n")
                        self.assertEqual(archive.read("stdout.json"), b"{}\n")

    def test_utf8_split_across_upload_chunks_matches_raw_stderr(self):
        script = "import sys; sys.stderr.buffer.write(b'a'*65535 + 'é漢'.encode()); print('{}')"
        with tempfile.TemporaryDirectory() as temporary:
            agent = self.run_example(Path(temporary), f"exec {shlex.quote(sys.executable)} -c {shlex.quote(script)}")
        self.assertEqual(agent.status, 3)
        with ZipFile(io.BytesIO(agent.archive)) as archive:
            self.assertEqual("".join(agent.log).encode(), archive.read("stderr.log"))

    def test_deadline_allows_teardown_and_still_fails_attempt(self):
        script = """
import signal
from pathlib import Path
def finish(signum, frame):
    Path("evidence/closed").write_text("teardown complete")
    raise SystemExit(0)
signal.signal(signal.SIGTERM, finish)
signal.pause()
"""
        with tempfile.TemporaryDirectory() as temporary:
            agent = self.run_example(Path(temporary), f"exec {shlex.quote(sys.executable)} -c {shlex.quote(script)}",
                                     timeout=1)
        self.assertEqual(agent.status, -2)
        self.assertIn("timeout", agent.reason)
        with ZipFile(io.BytesIO(agent.archive)) as archive:
            self.assertEqual(archive.read("evidence/closed"), b"teardown complete")

    def test_deadline_reaps_descendants_of_an_unresponsive_driver(self):
        import fcntl
        child = ("import fcntl, time; f=open('lease','w'); fcntl.flock(f,fcntl.LOCK_EX); "
                 "open('evidence/child-started','w').close(); time.sleep(30)")
        script = ("import signal,subprocess,sys; signal.signal(signal.SIGTERM,signal.SIG_IGN); "
                  f"subprocess.Popen([sys.executable,'-c',{child!r}]); signal.pause()")
        with tempfile.TemporaryDirectory() as temporary, patch.object(worker, "CLEANUP_TIMEOUT", 0.1):
            directory = Path(temporary)
            agent = self.run_example(directory, f"exec {shlex.quote(sys.executable)} -c {shlex.quote(script)}",
                                     timeout=1)
            self.assertEqual(agent.status, -2)
            self.assertTrue((directory / "run/evidence/child-started").is_file())
            with (directory / "run/lease").open() as lease:
                fcntl.flock(lease, fcntl.LOCK_EX | fcntl.LOCK_NB)

    def test_authenticated_transport(self):
        for url in ("https://chronos.example/path", "http://localhost:8080", "http://127.0.0.1", "http://[::1]:80"):
            with self.subTest(url=url):
                worker.Agent(url, "token")
        for url in ("http://chronos.example", "http://localhost.example", "http://127.0.0.1.example",
                    "https://user@localhost", "ftp://localhost", "https://localhost/path?query",
                    "https://localhost/path#fragment"):
            with self.subTest(url=url), self.assertRaises(worker.AgentError):
                worker.Agent(url, "token")


if __name__ == "__main__":
    unittest.main()
