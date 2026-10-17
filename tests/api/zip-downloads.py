#!/usr/bin/env python3
"""Actual authorized ZIP downloads on strictly guarded synthetic loopback fixtures."""
import argparse
import importlib.util
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location("chronos_api_contracts", Path(__file__).with_name("v3-api.py"))
api = importlib.util.module_from_spec(spec)
spec.loader.exec_module(api)


class ZipDownloads(unittest.TestCase):
    def client(self, name):
        client = api.Client(); client.login(name); return client

    def download(self, client, path, filename, *, staged=True):
        with client.opener.open(api.BASE_URL + path, timeout=20) as response:
            self.assertEqual(200, response.code)
            self.assertEqual("application/zip", response.headers.get_content_type())
            self.assertTrue(response.headers["Content-Disposition"].startswith('attachment; filename="' + filename + '"'))
            self.assertEqual("nosniff", response.headers["X-Content-Type-Options"])
            self.assertIn("no-store", response.headers["Cache-Control"])
            payload = response.read(8 * 1024 * 1024)
            if staged: self.assertEqual(len(payload), int(response.headers["Content-Length"]))
        return payload

    def test_configuration_export_contains_only_exact_configuration_documents(self):
        owner = self.client("owner")
        payload = self.download(owner, "/api/v3/systems/1/export", "system1.zip")
        with zipfile.ZipFile(io.BytesIO(payload)) as archive:
            self.assertEqual(["parameters.json", "resultConfigurations.json"], archive.namelist())
            self.assertIsNone(archive.testzip())
            self.assertEqual(owner.request("GET", "/api/v3/systems/1/parameters")[1]["data"]["groups"], json.loads(archive.read("parameters.json")))
            self.assertEqual(owner.request("GET", "/api/v3/systems/1/result-configurations")[1]["data"], json.loads(archive.read("resultConfigurations.json")))
        self.assertEqual(403, self.client("member").request("GET", "/api/v3/systems/1/export")[0])

    def test_evaluation_entries_equal_each_original_job_archive_byte_for_byte(self):
        member = self.client("member")
        payload = self.download(member, "/api/v3/evaluations/2/download", "evaluation2.zip")
        with zipfile.ZipFile(io.BytesIO(payload)) as archive:
            self.assertEqual([str(id) + ".zip" for id in range(7, 13)], archive.namelist())
            self.assertIsNone(archive.testzip())
            for id in range(7, 13):
                single = self.download(member, f"/api/v3/jobs/{id}/download", f"job{id}.zip", staged=False)
                self.assertEqual(single, archive.read(f"{id}.zip"))
                with zipfile.ZipFile(io.BytesIO(single)) as result:
                    self.assertEqual(["result.json"], result.namelist())
                    self.assertIn("metric", json.loads(result.read("result.json")))
        self.assertEqual(403, self.client("outsider").request("GET", "/api/v3/evaluations/2/download")[0])
        self.assertEqual(401, api.Client().request("GET", "/api/v3/evaluations/2/download")[0])

    def test_incomplete_and_missing_result_archives_return_json_errors(self):
        owner = self.client("owner")
        self.assertEqual(409, owner.request("GET", "/api/v3/evaluations/1/download")[0])
        # Reserved agent fixture jobs have no result archives. Change only those
        # disposable rows through the same authorized API used by the real UI.
        try:
            for id in (90, 91):
                self.assertEqual(200, owner.request("PATCH", f"/api/v3/jobs/{id}", {"status": 3})[0])
                status, body, headers = owner.request("GET", f"/api/v3/jobs/{id}/download")
                self.assertEqual(404, status); self.assertEqual("archive_unavailable", body["error"]["code"])
                self.assertEqual("application/json", headers.get_content_type())
                self.assertNotIn("Content-Disposition", headers)
            status, body, headers = owner.request("GET", "/api/v3/evaluations/90/download")
            self.assertEqual(404, status); self.assertEqual("archive_unavailable", body["error"]["code"])
            self.assertEqual("application/json", headers.get_content_type()); self.assertNotIn("Content-Disposition", headers)
        finally:
            for id, status in ((90, 0), (91, 2)):
                self.assertEqual(200, owner.request("PATCH", f"/api/v3/jobs/{id}", {"status": status})[0])


if __name__ == "__main__":
    parser = argparse.ArgumentParser(); parser.add_argument("--base-url", default="http://127.0.0.1:8089")
    options, remaining = parser.parse_known_args(); api.BASE_URL = options.base_url.rstrip("/"); api.guard_target(api.BASE_URL)
    unittest.main(argv=[__file__, *remaining], verbosity=2)
