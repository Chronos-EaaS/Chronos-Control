"""Regression tests for the standalone runner. The default suite needs no Docker."""

import contextlib
import io
import json
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import threading
import unittest
from unittest.mock import Mock, patch

import benchmark


SUMMARY = """sysbench 1.0.20
[ 2s ] thds: 2 tps: 12.00 qps: 192.00 (r/w/o: 168.00/0.00/24.00)
SQL statistics:
    queries performed:
        read:                            336
        write:                           0
        other:                           48
        total:                           384
    transactions:                        24   (12.00 per sec.)
    queries:                             384  (192.00 per sec.)
    ignored errors:                      0    (0.00 per sec.)
    reconnects:                          0    (0.00 per sec.)

General statistics:
    total time:                          2.0000s
    total number of events:              24

Latency (ms):
         min:                                    1.00
         avg:                                    2.00
         max:                                    3.00
         95th percentile:                        2.90

Threads fairness:
    events (avg/stddev):           12.0000/0.00
"""

DOCKER = ["docker", "--host", "unix:///local-study.sock"]


def command_result(output="", code=0):
    result = Mock(ok=code == 0, output=Mock(return_value=output),
                  record={"status": "exited", "exitCode": code})
    result.require_success.return_value = result
    return result


class StudyPlanTests(unittest.TestCase):
    def test_dry_run_has_stable_order_and_explicit_drivers_without_docker(self):
        with tempfile.TemporaryDirectory() as temp:
            output = Path(temp) / "unused"
            completed = subprocess.run(
                [sys.executable, str(benchmark.HERE / "benchmark.py"), "--docker-socket",
                 "/missing/docker.sock", "--output", str(output), "--dry-run",
                 "--tables", "2", "--rows", "100", "--repetitions", "2",
                 "--threads", "4", "--seed", "17", "--warmup", "0", "--duration", "2"],
                check=True, capture_output=True, text=True,
            )
            self.assertFalse(output.exists())
        planned = json.loads(completed.stdout)
        self.assertEqual([rep["id"] for rep in planned["repetitions"]],
                         ["001-postgresql", "001-mariadb", "002-mariadb", "002-postgresql"])
        for system, name in (("postgresql", "pgsql"), ("mariadb", "mysql")):
            with self.subTest(system=system):
                commands = planned["workloadCommands"][system]
                self.assertEqual(set(commands), {"prepare", "measure"})
                for command in commands.values():
                    self.assertIn(f"--db-driver={name}", command)
                    self.assertIn("--rand-seed=17", command)
                    self.assertIn("--tables=2", command)
                    self.assertIn("--table-size=100", command)
                self.assertIn("--threads=1", commands["prepare"])
                self.assertIn("--threads=4", commands["measure"])
                self.assertIn("--time=2", commands["measure"])

    def test_invalid_parameters_exit_before_docker(self):
        for argv in (["--rows", "0"], ["--threads", "65"], ["--duration", "0"],
                     ["--seed", "0"], ["--warmup", "-1"], ["--tables", "many"],
                     ["--client-image=--help"], ["--docker-socket", "tcp://host:2376"]):
            with self.subTest(argv=argv), contextlib.redirect_stderr(io.StringIO()):
                with self.assertRaises(SystemExit) as raised:
                    benchmark.parse_args(argv)
                self.assertEqual(raised.exception.code, 2)

    def test_preflight_failure_keeps_planned_repetitions(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp) / "run"
            args = benchmark.parse_args(["--docker-socket", str(Path(temp) / "missing.sock")])
            state = benchmark.run_study(directory, benchmark.plan(args), args, benchmark.Cancellation())
            self.assertEqual(state["status"], "failed")
            self.assertEqual(json.loads((directory / "run.json").read_text()), state)
            self.assertTrue(all(rep["status"] == "planned" and rep["attempts"] == []
                                for rep in state["repetitions"]))
            with self.assertRaises(FileExistsError):
                benchmark.run_study(directory, benchmark.plan(args), args, benchmark.Cancellation())


class SummaryTests(unittest.TestCase):
    def test_parse_full_final_summary(self):
        result = benchmark.parse_summary(SUMMARY, 2)
        self.assertEqual(result["transactions"], 24)
        self.assertEqual(result["readQueries"], 336)
        self.assertEqual(result["transactionsPerSecond"], 12)
        self.assertEqual(result["latencyMilliseconds"]["p95"], 2.9)

    def test_reject_incomplete_erroneous_or_inconsistent_summary(self):
        for raw in (SUMMARY.split("Threads fairness:")[0], SUMMARY + SUMMARY, SUMMARY + "FATAL: failed\n",
                    SUMMARY.replace("336", "335"), SUMMARY.replace("2.0000s", "1.2s"),
                    SUMMARY.replace("ignored errors:                      0", "ignored errors:                      1"),
                    SUMMARY.replace("reconnects:                          0", "reconnects:                          1")):
            with self.subTest(raw=raw[-70:]), self.assertRaises(benchmark.StudyError):
                benchmark.parse_summary(raw, 2)


class EvidenceTests(unittest.TestCase):
    def test_nonzero_exit_keeps_argv_code_and_both_output_streams(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            recorder = benchmark.Recorder(directory, benchmark.Cancellation())
            args = [sys.executable, "-c", "import sys; print('out'); print('err', file=sys.stderr); sys.exit(7)"]
            result = recorder.call("test-phase", args)
            self.assertFalse(result.ok)
            self.assertEqual(result.output(), "out\n")
            self.assertEqual((directory / "001-test-phase.stderr.log").read_text(), "err\n")
            record = json.loads((directory / "001-test-phase.json").read_text())
            self.assertEqual(record["exitCode"], 7)
            self.assertEqual(record["argv"], args)

    def test_timed_out_and_cancelled_commands_save_status(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            cancellation = benchmark.Cancellation()
            recorder = benchmark.Recorder(directory, cancellation)
            sleepy = [sys.executable, "-c", "import time; time.sleep(30)"]
            self.assertEqual(recorder.call("timeout", sleepy, timeout=0.1).record["status"], "timed-out")
            timer = threading.Timer(0.1, cancellation.receive, args=(signal.SIGTERM, None))
            timer.start()
            try:
                with self.assertRaises(benchmark.Cancelled):
                    recorder.call("cancel", sleepy)
            finally:
                timer.join()
            record = json.loads((directory / "002-cancel.json").read_text())
            self.assertEqual(record["status"], "cancelled")
            self.assertLess(record["wallSeconds"], 5)
            self.assertTrue(recorder.call("after-cancel", [sys.executable, "-V"], teardown=True).ok)


class TeardownTests(unittest.TestCase):
    def test_failure_removing_container_still_removes_network_and_verifies_both(self):
        db_id, client_id, network_id = "a" * 64, "b" * 64, "c" * 64
        recorder = Mock()
        recorder.call.side_effect = [
            command_result(f"{db_id}\n{client_id}\n"), command_result(db_id), command_result(),
            command_result(code=1), command_result(network_id), command_result(),
            command_result(client_id), command_result(),
        ]
        label = "org.chronos.studies.database.attempt=abc"
        errors = benchmark.teardown_resources(recorder, DOCKER, label, "study-db")
        self.assertIn("remove-containers: exited (exit 1)", errors)
        self.assertIn("owned Docker resources remain after teardown", errors)
        calls = {call.args[0]: call for call in recorder.call.call_args_list}
        self.assertEqual(calls["remove-networks"].args[1], DOCKER + ["network", "rm", network_id])
        self.assertEqual(calls["remove-containers"].args[1], DOCKER + ["rm", "-fv", db_id, client_id])
        for phase in ("find-containers", "find-networks", "verify-containers", "verify-networks"):
            self.assertIn(f"label={label}", calls[phase].args[1])
            self.assertTrue(calls[phase].kwargs["teardown"])

    def test_partial_provisioning_executes_teardown_and_records_failure(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp) / "attempt-001"
            args = benchmark.parse_args(["--system", "postgresql"])
            plan = benchmark.plan(args)
            info = {"id": "study", "plan": plan,
                    "images": {"client": {"id": "client"}, "postgresql": {"id": "database"}}}
            repetition = plan["repetitions"][0]

            def commands(_recorder, phase, _argv, **_options):
                if phase == "start-database":
                    raise benchmark.StudyError("simulated partial Docker create failure")
                return command_result()

            with patch.object(benchmark.Recorder, "call", autospec=True, side_effect=commands):
                with patch.object(benchmark, "teardown_resources", return_value=[]) as teardown:
                    state = benchmark.run_attempt(directory, DOCKER, info, repetition, 1, args,
                                                  benchmark.Cancellation())
            self.assertEqual(state["status"], "failed")
            self.assertFalse(state["eligible"])
            self.assertIn("partial Docker create", state["error"])
            self.assertEqual(teardown.call_args.args[2], state["dockerOwnershipLabel"])
            self.assertEqual(json.loads((directory / "attempt.json").read_text()), state)


class RetryTests(unittest.TestCase):
    def test_clean_failure_retries_under_same_repetition_and_cleanup_failure_stops_schedule(self):
        responses = [
            {"status": "failed", "eligible": False, "teardownErrors": []},
            {"status": "succeeded", "eligible": True, "teardownErrors": []},
            {"status": "failed", "eligible": False, "teardownErrors": ["simulated removal failure"]},
        ]
        for cleanup_failed in (False, True):
            with self.subTest(cleanup_failed=cleanup_failed), tempfile.TemporaryDirectory() as temp:
                directory = Path(temp) / "run"
                args = benchmark.parse_args(["--system", "postgresql", "--repetitions", "2", "--retries", "1"])
                engine = {"os": "linux", "architecture": "amd64"}
                images = {"client": {"architecture": "amd64"}}
                outcomes = [responses[2]] if cleanup_failed else [*responses[:2], responses[1]]
                with (
                    patch.object(Path, "is_socket", return_value=True),
                    patch.object(benchmark.Recorder, "call", side_effect=[
                        command_result("{}"), command_result(json.dumps(engine)),
                    ]),
                    patch.object(benchmark, "resolve_images", return_value=images),
                    patch.object(benchmark, "run_attempt", side_effect=outcomes) as attempt,
                    contextlib.redirect_stderr(io.StringIO()),
                ):
                    state = benchmark.run_study(directory, benchmark.plan(args), args, benchmark.Cancellation())
                first, second = state["repetitions"]
                if cleanup_failed:
                    self.assertEqual(state["status"], "failed")
                    self.assertEqual(attempt.call_count, 1)
                    self.assertEqual(second["status"], "planned")
                else:
                    self.assertEqual(state["status"], "succeeded")
                    self.assertEqual([entry["id"] for entry in first["attempts"]], ["attempt-001", "attempt-002"])
                    self.assertEqual([entry["eligible"] for entry in first["attempts"]], [False, True])
                    self.assertEqual(second["attempts"][0]["id"], "attempt-001")


if __name__ == "__main__":
    unittest.main()
