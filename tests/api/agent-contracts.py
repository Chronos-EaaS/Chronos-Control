#!/usr/bin/env python3
"""Preserved v1 wire contracts on guarded, disposable v3 fixture stacks only."""
from __future__ import annotations
import argparse
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest
import urllib.error
import urllib.parse

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("chronos_api_contracts", Path(__file__).with_name("ui-api.py"))
api = importlib.util.module_from_spec(spec)
spec.loader.exec_module(api)
PROJECT = ""
MISSING_UI = False


class AgentContracts(unittest.TestCase):
    def setUp(self):
        result = subprocess.run(["docker", "exec", PROJECT + "-web-1", "php", "/opt/chronos/tests/fixtures/seed.php", "--reset"], capture_output=True, text=True, timeout=30)
        self.assertEqual(0, result.returncode, result.stderr)
        self.agent = api.Client()

    def envelope(self, method, path, fields=None, code=200, *, raw=None, headers=None):
        if fields is not None:
            raw = urllib.parse.urlencode(fields).encode()
            headers = {"Content-Type": "application/x-www-form-urlencoded", **(headers or {})}
        status, body, response_headers = self.agent.request(method, path, raw=raw, headers=headers, csrf=False)
        self.assertEqual(200, status, body)
        self.assertEqual(code, body["status"]["code"], body)
        self.assertIsInstance(body["status"]["message"], str)
        self.assertIn("application/json", response_headers["Content-Type"])
        self.assertNotIn("data", body)
        return body

    def job(self):
        return self.envelope("GET", "/api/v1/job/id=90")["response"]

    def owner(self):
        client = api.Client(); client.login("owner"); return client

    def test_v1_job_01_specific_job_numeric_fields_environment_and_xml(self):
        job = self.job()
        for key, value in {"id":90,"user":3,"username":"owner","status":0,"progress":0,"environment":"system-agent","system":"fixture-system"}.items():
            self.assertEqual(value, job[key])
        for key in ("id","user","status","progress","phases","currentPhase"):
            self.assertIsInstance(job[key], int)
        self.assertRegex(job["cdl"], r"<\?xml[^>]*>\s*<chronos")
        self.assertRegex(job["created"], r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$")

    def test_v1_job_02_missing_job_uses_application_602(self):
        for method, payload in (("GET", None), ("PATCH", {"status": 2, "progress": 47})):
            with self.subTest(method=method):
                body = self.envelope(method, "/api/v1/job/id=999999", payload, code=602)
                self.assertIn("Job does not exist", body["status"]["message"])
                self.assertNotIn("response", body)

    def test_v1_job_03_identifier_and_environment_queue_filters(self):
        job = self.envelope("GET", "/api/v1/job/id=next/supports=fixture-system/environment=agent")["response"]
        self.assertEqual((90, "system-agent", 0), (job["id"], job["environment"], job["status"]))
        self.envelope("GET", "/api/v1/job/id=next/supports=demoall/environment=empty-fixture", code=601)
        self.envelope("GET", "/api/v1/job/id=next/supports=unknown-fixture/environment=agent", code=600)

    def test_v1_job_04_form_lifecycle_and_phase_reset(self):
        self.envelope("PATCH", "/api/v1/job/id=90", {"status":2,"progress":47})
        self.assertEqual((2,47), (self.job()["status"],self.job()["progress"]))
        self.envelope("PATCH", "/api/v1/job/id=90", {"currentPhase":3,"progress":80})
        self.assertEqual((3,0), (self.job()["currentPhase"],self.job()["progress"]))
        self.envelope("PATCH", "/api/v1/job/id=90", {"currentPhase":99,"progress":25})
        self.assertEqual((3,25), (self.job()["currentPhase"],self.job()["progress"]))

    def test_v1_job_05_result_json_form_and_unicode_log_append(self):
        result = {"throughput":123.5,"note":"A&B + Zürich","zero":0,"flag":False}
        self.envelope("PATCH", "/api/v1/job/id=90", {"status":3,"result":json.dumps(result)})
        self.envelope("POST", "/api/v1/job/id=90/action=appendLog", {"log":"Agent: A&B + Zürich\n"})
        owner = self.owner()
        self.assertIn("Agent: A&B + Zürich\n", owner.request("GET", "/api/ui/jobs/90/log")[1]["data"]["text"])
        self.assertEqual(result, owner.request("GET", "/api/ui/jobs/90/results")[1]["data"]["jobs"][0]["result"])
        self.assertEqual(3, self.job()["status"])

    def test_v1_job_06_upload_target_retains_configured_wire_shape(self):
        target = self.envelope("POST", "/api/v1/job/id=90/action=getUploadTarget")["response"]
        # The returned hostname is fixture configuration, never contacted by this test.
        self.assertEqual({"method":"http","path":"/api/v1/job","hostname":"http://127.0.0.1:8088"}, target)

    def test_v1_job_07_multipart_upload_keeps_exact_bytes_behind_authorized_delivery(self):
        archive = bytes.fromhex("504b0506000000000000000000000000000000000000")
        boundary = "chronos-synthetic-upload-boundary"
        body = ("--"+boundary+'\r\nContent-Disposition: form-data; name="result"; filename="result.zip"\r\nContent-Type: application/zip\r\n\r\n').encode()+archive+("\r\n--"+boundary+"--\r\n").encode()
        self.envelope("POST", "/api/v1/job/id=90/action=upload", raw=body, headers={"Content-Type":"multipart/form-data; boundary="+boundary})
        self.envelope("PATCH", "/api/v1/job/id=90", {"status":3})
        with self.owner().opener.open(api.BASE_URL+"/api/ui/jobs/90/download",timeout=5) as response:
            self.assertEqual(200,response.status); self.assertEqual(archive,response.read()); self.assertEqual("application/zip",response.headers.get_content_type())
        with self.assertRaises(urllib.error.HTTPError) as denied:
            self.agent.opener.open(api.BASE_URL+"/data/evaluation/90.zip",timeout=5)
        self.assertIn(denied.exception.code,(403,404)); denied.exception.close()

    def test_v1_cem_01_outdated_and_unknown_environment_codes(self):
        self.envelope("GET", "/api/v1/cem/uniqueId=fixture-contract/version=1/environment=fixture", code=631)
        self.envelope("GET", "/api/v1/cem/uniqueId=fixture-contract/version=2/environment=unknown-fixture", code=632)

    def test_v1_cem_02_specific_job_setup_and_cdl(self):
        value = self.envelope("GET", "/api/v1/cem/uniqueId=fixture-contract/version=2/environment=fixture/jobId=90")["response"]
        self.assertEqual({"id":90,"status":0}, value["job"])
        self.assertEqual(1,value["system"]["id"])
        self.assertEqual("fixture-setup-value",value["setup"]["fixtureSetup"])
        self.assertRegex(value["cdl"],r"<\?xml")

    def test_v1_cem_03_start_telemetry_log_and_termination(self):
        path = "/api/v1/cem/uniqueId=fixture-contract/version=2/environment=fixture"
        self.envelope("POST",path+"/action=jobStarted",{"jobId":90})
        self.assertEqual(1,self.job()["status"])
        self.envelope("POST",path+"/action=nodeStatus",{"jobId":90,"cpu":12.5,"memoryUsed":1024,"memoryTotal":4096,"hostname":"fixture-node","os":"Fixture Linux","healthStatus":""})
        self.envelope("POST",path+"/action=jobLog",{"jobId":90,"message":"setup complete"})
        self.assertIn("CEM > setup complete",self.owner().request("GET","/api/ui/jobs/90/log")[1]["data"]["text"])
        self.envelope("POST",path+"/action=jobTerminated",{"jobId":90,"reason":"simulated fixture failure"})
        self.assertEqual(-2,self.job()["status"])

    def test_v1_cem_04_environment_queue_uses_scheduled_jobs(self):
        self.envelope("PATCH","/api/v1/job/id=2",{"status":0})
        value=self.envelope("GET","/api/v1/cem/uniqueId=fixture-contract/version=2/environment=fixture")["response"]
        self.assertEqual(2,value["job"]["id"])

    def test_dispatch_rejects_unknown_endpoints_and_browser_handlers(self):
        for path, method, status in (("/api/v1/unknown","GET",404),("/api/v1/cem","PATCH",405),("/api/v1/job/id=90","PUT",405)):
            actual,body,headers=self.agent.request(method,path,csrf=False)
            self.assertEqual(status,actual);self.assertEqual(600,body["status"]["code"])
            if status==405:self.assertIn("GET",headers["Allow"])
        actual,body,_=self.agent.request("GET","/api/ui/job/id=90")
        self.assertEqual(401,actual);self.assertIn("error",body);self.assertNotIn("status",body)
        actual,body,_=self.owner().request("GET","/api/ui/job/id=90")
        self.assertEqual(404,actual);self.assertIn("error",body);self.assertNotIn("status",body)
        self.assertEqual(404,self.agent.request("GET","/api/system/1/data")[0])

    def test_missing_ui_build_is_503_while_agent_and_api_continue(self):
        if not MISSING_UI:self.skipTest("Only the explicit source-snapshot fixture has no compiled UI.")
        with self.assertRaises(urllib.error.HTTPError) as unavailable:self.agent.opener.open(api.BASE_URL+"/",timeout=5)
        self.assertEqual(503,unavailable.exception.code);self.assertIn(b"Chronos UI build is unavailable",unavailable.exception.read());unavailable.exception.close()
        self.assertEqual(90,self.job()["id"])
        self.assertEqual(200,self.agent.request("GET","/api/ui/meta")[0])


if __name__ == "__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("--base-url",required=True)
    parser.add_argument("--fixture-project",required=True,choices=("chronos-v3-session-verification","chronos-v3-ui-regression"))
    parser.add_argument("--expect-missing-ui",action="store_true")
    options,remaining=parser.parse_known_args();api.BASE_URL=options.base_url.rstrip("/");api.guard_target(api.BASE_URL)
    port={"chronos-v3-session-verification":8090,"chronos-v3-ui-regression":8089}[options.fixture_project]
    if urllib.parse.urlsplit(api.BASE_URL).port!=port:raise SystemExit("Fixture project must match the guarded loopback port.")
    PROJECT=options.fixture_project;MISSING_UI=options.expect_missing_ui
    unittest.main(argv=[__file__,*remaining],verbosity=2)
