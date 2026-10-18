#!/usr/bin/env python3
"""Supplementary Unicode contracts on the guarded, synthetic legacy-MySQL fixture."""
import argparse
import importlib.util
from pathlib import Path
import sys
import subprocess
import unittest
import urllib.parse

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("chronos_api_contracts", Path(__file__).with_name("ui-api.py"))
api = importlib.util.module_from_spec(spec)
spec.loader.exec_module(api)
FIXTURE_PROJECT = None


class UnicodeContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.owner = api.Client(); cls.owner.login("owner")
        cls.admin = api.Client(); cls.admin.login("admin")

    def request(self, client, method, path, body=None, status=200):
        actual, response, _ = client.request(method, path, body)
        self.assertEqual(status, actual, response)
        return response.get("data", response)

    def system(self, name="Unicode storage fixture", cem=False):
        return self.request(self.admin, "POST", "/api/ui/systems", {"name": name, "ownerId": 3, "cem": cem}, 201)

    def test_01_profile_names_round_trip(self):
        original = self.request(self.owner, "GET", "/api/ui/profile")
        try:
            changed = self.request(self.owner, "PATCH", "/api/ui/profile", {"firstName": "Review 🧪", "lastName": "Zürich 🚀"})
            self.assertEqual("Review 🧪", changed["firstName"])
            self.assertEqual("Zürich 🚀", self.request(self.owner, "GET", "/api/ui/profile")["lastName"])
        finally:
            self.request(self.owner, "PATCH", "/api/ui/profile", {"firstName": original["firstName"], "lastName": original["lastName"]})

    def test_02_system_and_project_text_round_trip(self):
        system = self.system("Review system 🧪")
        self.assertEqual("Review system 🧪", system["name"])
        project = self.request(self.owner, "POST", "/api/ui/projects", {
            "name": "Review project 🚀", "description": "Zürich & <markup> 🧪", "systemId": system["id"],
        }, 201)
        loaded = self.request(self.owner, "GET", f"/api/ui/projects/{project['id']}")
        self.assertEqual("Review project 🚀", loaded["name"])
        self.assertEqual("Zürich & <markup> 🧪", loaded["description"])

    def test_03_unsupported_raw_username_has_a_validation_error(self):
        response = self.request(self.admin, "POST", "/api/ui/users", {
            "username": "review-🧪", "firstName": "Review", "lastName": "Fixture", "email": "unicode@example.test", "gender": 0,
            "password": api.PASSWORD, "passwordConfirmation": api.PASSWORD,
        }, 422)
        self.assertIn("username", response["error"]["fields"])

    def test_04_setting_keys_reject_unsupported_characters_without_partial_writes(self):
        system = self.system()
        path = f"/api/ui/systems/{system['id']}/settings"
        before = self.request(self.owner, "GET", path)
        response = self.request(self.owner, "PUT", path, {"general": {"review-🧪": "value"}}, 422)
        self.assertEqual({"general.review-🧪"}, set(response["error"]["fields"]))
        self.assertEqual(before, self.request(self.owner, "GET", path))
        changed = self.request(self.owner, "PUT", path, {"general": {"review": "BLOB value 🧪"}})
        self.assertEqual("BLOB value 🧪", changed["general"]["review"])

    def test_05_parameter_files_experiments_copy_jobs_and_agent_xml_preserve_unicode(self):
        system = self.system()
        sid = system["id"]
        groups = [{"id": "base", "title": "Unicode 🧪", "depends": "", "dependsValue": "", "elements": [
            {"id": "label", "type": "string", "name": "Label 🚀", "parameter": "label", "default": "Zürich 🧪"},
        ]}]
        self.request(self.owner, "PUT", f"/api/ui/systems/{sid}/parameters", {"groups": groups})
        self.assertEqual(groups, self.request(self.owner, "GET", f"/api/ui/systems/{sid}/parameters")["groups"])
        project = self.request(self.owner, "POST", "/api/ui/projects", {"name": "Unicode parameter fixture", "systemId": sid}, 201)
        body = {"name": "Unicode experiment", "runs": 1, "distribution": "alter", "warmUp": False,
                "environment": "system-default", "parameters": {"label": "Zürich & <value> 🧪"}}
        experiment = self.request(self.owner, "POST", f"/api/ui/projects/{project['id']}/experiments", body, 201)
        values = self.request(self.owner, "GET", f"/api/ui/experiments/{experiment['id']}/parameters")
        self.assertEqual(body["parameters"], values["values"])
        copied = self.request(self.owner, "GET", f"/api/ui/projects/{project['id']}/experiment-options?copy={experiment['id']}")
        self.assertEqual(groups, copied["groups"])
        self.assertEqual(body["parameters"], copied["values"])
        evaluation = self.request(self.owner, "POST", f"/api/ui/experiments/{experiment['id']}/evaluations", {"environment": "system-default"}, 201)
        job = self.request(self.owner, "GET", f"/api/ui/evaluations/{evaluation['id']}/jobs")["items"][0]
        self.assertEqual(body["parameters"]["label"], job["configuration"]["parameters"]["label"])
        self.assertIn("🧪", job["description"])
        self.assertIn("Zürich &amp; &lt;value&gt; 🧪", self.request(self.owner, "GET", f"/api/ui/jobs/{job['id']}/cdl")["xml"])
        status, agent, _ = self.owner.request("GET", f"/api/v1/job/id={job['id']}")
        self.assertEqual(200, status)
        self.assertEqual(200, agent["status"]["code"], agent)
        self.assertIn("Zürich &amp; &lt;value&gt; 🧪", agent["response"]["cdl"])

    def test_06_unusable_cem_environment_is_rejected_before_storage(self):
        before = self.request(self.admin, "GET", "/api/ui/admin/environments")
        response = self.request(self.admin, "POST", "/api/ui/admin/environments", {"name": "review-🧪"}, 422)
        self.assertIn("name", response["error"]["fields"])
        self.assertEqual(before, self.request(self.admin, "GET", "/api/ui/admin/environments"))

    def test_07_unsupported_settings_section_lookup_is_a_validation_error(self):
        response = self.request(self.admin, "PATCH", "/api/ui/admin/settings/review-%F0%9F%A7%AA", {
            "revision": "a" * 64, "values": {"review": "value"},
        }, 422)
        self.assertIn("section", response["error"]["fields"])

    def test_08_unsupported_node_identifier_lookup_is_a_validation_error(self):
        response = self.request(self.admin, "GET", "/api/ui/cem/nodes/review-%F0%9F%A7%AA", status=422)
        self.assertIn("nodeId", response["error"]["fields"])

    def test_09_historical_blob_environment_remains_readable_and_removable(self):
        if FIXTURE_PROJECT is None:
            self.skipTest("Supply --fixture-project for guarded historical BLOB fixture preparation.")
        def prepare(action):
            result = subprocess.run(["docker", "exec", FIXTURE_PROJECT + "-web-1", "php", "/opt/chronos/tests/fixtures/unicode.php", action], capture_output=True, text=True, timeout=20)
            self.assertEqual(0, result.returncode, result.stderr)
        prepare("seed-legacy-environment")
        try:
            legacy = "legacy-review-🧪"
            items = self.request(self.admin, "GET", "/api/ui/admin/environments")["items"]
            self.assertEqual(0, next(item["nodeCount"] for item in items if item["name"] == legacy))
            system = self.system(cem=True)
            project = self.request(self.owner, "POST", "/api/ui/projects", {"name": "Legacy CEM Unicode fixture", "systemId": system["id"]}, 201)
            options = self.request(self.owner, "GET", f"/api/ui/projects/{project['id']}/experiment-options")
            self.assertIsInstance(options["defaults"], dict)
            removed = self.request(self.admin, "DELETE", "/api/ui/admin/environments/" + urllib.parse.quote(legacy, safe=""))
            self.assertNotIn(legacy, [item["name"] for item in removed["items"]])
        finally:
            prepare("remove-legacy-environment")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:8089")
    parser.add_argument("--fixture-project", choices=("chronos-v3-ui-regression",))
    options, remaining = parser.parse_known_args()
    api.BASE_URL = options.base_url.rstrip("/")
    api.guard_target(api.BASE_URL)
    FIXTURE_PROJECT = options.fixture_project
    if FIXTURE_PROJECT and urllib.parse.urlsplit(api.BASE_URL).port != 8089:
        raise SystemExit("Fixture project must match the guarded loopback port.")
    unittest.main(argv=[__file__, *remaining], verbosity=2)
