#!/usr/bin/env python3
"""Displayed-text SQL search contracts on the isolated synthetic v3 fixture only."""
from __future__ import annotations

import argparse
import importlib.util
import json
from pathlib import Path
import secrets
import subprocess
import sys
import unittest
import urllib.parse

sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("chronos_v3_api", Path(__file__).with_name("v3-api.py"))
api = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(api)
ROOT = Path(__file__).resolve().parents[2]


def fixture_sql(statements):
    source = r'''
if (getenv('CHRONOS_TEST_STACK') !== '1' || !is_file('/.dockerenv') || !is_file('/opt/chronos/webroot/healthz.txt')) throw new RuntimeException('Synthetic fixture required.');
$db = new PDO('mysql:host=db;dbname=chronos_test;charset=utf8mb4','chronos_test','chronos_test_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'chronos_test') throw new RuntimeException('Synthetic database required.');
$results=[];
foreach(json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR) as [$sql,$values]) {
    $query=$db->prepare($sql);$query->execute($values);$results[]=['id'=>(int)$db->lastInsertId(),'rows'=>$query->columnCount()?$query->fetchAll(PDO::FETCH_ASSOC):[]];
}
echo json_encode($results,JSON_THROW_ON_ERROR);
'''
    command = ["docker", "compose", "--project-name", "chronos-v3-ui-regression", "--file", str(ROOT / "compose.v3.test.yml"), "exec", "-T", "web", "php", "-r", source]
    result = subprocess.run(command, input=json.dumps(statements), text=True, capture_output=True, check=True, timeout=20)
    return json.loads(result.stdout)


def fixture_search(cases):
    """Use real MySQL ICU/collation semantics, without a PHP-PCRE substitute."""
    source = r'''
if (getenv('CHRONOS_TEST_STACK') !== '1' || !is_file('/.dockerenv')) throw new RuntimeException('Synthetic fixture required.');
require '/opt/chronos/vendor/autoload.php';
$db = new PDO('mysql:host=db;dbname=chronos_test;charset=utf8mb4','chronos_test','chronos_test_only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'chronos_test') throw new RuntimeException('Synthetic database required.');
$results=[];
foreach(json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR) as [$query,$stored,$expected]) {
    [$where,$values]=\Chronos\Application\Shared\TextSearch::condition($query,['value']);
    $statement=$db->prepare('SELECT COUNT(*) FROM (SELECT ? AS value) fixture WHERE '.$where);
    $statement->execute([$stored,...$values]);$results[]=(bool)$statement->fetchColumn();
}
echo json_encode($results,JSON_THROW_ON_ERROR);
'''
    command = ["docker", "compose", "--project-name", "chronos-v3-ui-regression", "--file", str(ROOT / "compose.v3.test.yml"), "exec", "-T", "web", "php", "-r", source]
    result = subprocess.run(command, input=json.dumps(cases), text=True, capture_output=True, timeout=30)
    if result.returncode:
        raise AssertionError((result.stderr or result.stdout)[-1200:])
    return json.loads(result.stdout)


class SearchContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.admin = api.Client(); cls.admin.login("superadmin")
        cls.tag = "search" + secrets.token_hex(4)

        def create(path, body, client=cls.admin):
            status, result, _ = client.request("POST", "/api/v3" + path, body)
            assert status == 201, (status, result)
            return result["data"]["id"]

        cls.user_id = create("/users", {"username": cls.tag, "firstName": "Zürich &", "lastName": cls.tag,
            "email": cls.tag + "@example.test", "gender": 0, "password": api.PASSWORD, "passwordConfirmation": api.PASSWORD})
        cls.owner = api.Client(); cls.owner.login(cls.tag)
        cls.system_id = create("/systems", {"name": cls.tag + " system", "description": "Zürich & A 100%_=.", "ownerId": cls.user_id})
        cls.project_id = create("/projects", {"name": cls.tag + " project", "description": "Zürich & A 100%_=.", "systemId": cls.system_id}, cls.owner)
        cls.experiment_id = create(f"/projects/{cls.project_id}/experiments", {"name": cls.tag + " experiment", "description": "Zürich & A 100%_=.", "parameters": {}, "runs": 1, "warmUp": False}, cls.owner)
        cls.evaluation_id = create(f"/experiments/{cls.experiment_id}/evaluations", {"name": cls.tag + " evaluation", "description": "Zürich & A 100%_=.", "environment": "system-default"}, cls.owner)
        status, result, _ = cls.owner.request("GET", f"/api/v3/evaluations/{cls.evaluation_id}/jobs")
        assert status == 200, result
        cls.job_id = result["data"]["items"][0]["id"]
        status, result, _ = cls.owner.request("PATCH", f"/api/v3/jobs/{cls.job_id}", {"description": "Zürich & A 100%_=."})
        assert status == 200, result

    def listing(self, path, text, *, client=None, **query):
        client = client or self.owner
        query = {"search": text, "pageSize": 100, **query}
        status, result, _ = client.request("GET", "/api/v3" + path + "?" + urllib.parse.urlencode(query))
        self.assertEqual(200, status, result)
        return result["data"]

    def assert_found(self, path, text, expected, **query):
        data = self.listing(path, text, **query)
        self.assertIn(expected, [row["id"] for row in data["items"]], (path, text, data))

    def test_01_new_html_encoded_metadata_and_all_workflow_scopes(self):
        paths = [("/systems", self.system_id), ("/projects", self.project_id),
            (f"/projects/{self.project_id}/experiments", self.experiment_id),
            ("/evaluations", self.evaluation_id), (f"/projects/{self.project_id}/evaluations", self.evaluation_id),
            (f"/experiments/{self.experiment_id}/evaluations", self.evaluation_id),
            ("/jobs", self.job_id), (f"/evaluations/{self.evaluation_id}/jobs", self.job_id)]
        for path, expected in paths:
            with self.subTest(path=path):
                self.assert_found(path, "Zürich & A", expected)
                self.assert_found(path, "100%_=.", expected)
        self.assert_found("/users", "Zürich &", self.user_id, client=self.admin)
        for path, expected in [("/systems", self.system_id), ("/projects", self.project_id), ("/jobs", self.job_id)]:
            self.assert_found(path, "Zürich & " + self.tag, expected)

    def test_02_legacy_named_entities_and_mixed_owner_names(self):
        fixture_sql([
            ("UPDATE `System` SET description=? WHERE systemId=?", ["Z&uuml;rich &amp; mixed & raw", self.system_id]),
            ("UPDATE `Project` SET description=? WHERE projectId=?", ["Z&uuml;rich &amp; mixed & raw", self.project_id]),
            ("UPDATE `User` SET firstname=?,lastname=? WHERE userId=?", ["Caf&eacute;", "Raw & Name", self.user_id]),
        ])
        for path, expected in [("/systems", self.system_id), ("/projects", self.project_id)]:
            self.assert_found(path, "Zürich & mixed & raw", expected)
            self.assert_found(path, "Café Raw & Name", expected)
        self.assert_found("/jobs", "Café Raw & Name", self.job_id)
        self.assert_found("/users", "Café", self.user_id, client=self.admin)

    def test_03_literal_entity_spellings_do_not_match_encoded_characters(self):
        inserted = fixture_sql([
            ("INSERT INTO `Event`(title,time,eventText,eventType,relatedId,userId,nodeId) VALUES(?,NOW(),?,'project',?,?,NULL)",
                ["Encoded character", "Decoded &amp;", self.project_id, self.user_id]),
            ("INSERT INTO `Event`(title,time,eventText,eventType,relatedId,userId,nodeId) VALUES(?,NOW(),?,'project',?,?,NULL)",
                ["Literal entity", "Literal &amp;amp;", self.project_id, self.user_id]),
            ("INSERT INTO `Event`(title,time,eventText,eventType,relatedId,userId,nodeId) VALUES(?,NOW(),?,'project',?,?,NULL)",
                ["Mixed event", "Z&uuml;rich &amp; encoded + raw & tail", self.project_id, self.user_id]),
        ])
        path = f"/projects/{self.project_id}/events"
        ids = [row["id"] for row in self.listing(path, "&amp;")["items"]]
        self.assertIn(inserted[1]["id"], ids)
        self.assertNotIn(inserted[0]["id"], ids)
        self.assert_found(path, "Zürich & encoded + raw & tail", inserted[2]["id"])

    def test_04_non_bmp_search_and_raw_environment_fields(self):
        fixture_sql([("UPDATE `Project` SET name=?,description=? WHERE projectId=?", ["Synthetic &#129514; &amp; marker", "Synthetic &#129514; &amp; marker", self.project_id])])
        self.assert_found("/projects", "🧪 & marker", self.project_id)
        self.assert_found("/evaluations", "🧪 & marker", self.evaluation_id)
        self.assert_found("/jobs", "🧪 & marker", self.job_id)
        for path in ("/users", "/systems", "/cem/nodes"):
            self.listing(path, "🧪", client=self.admin)
        fixture_sql([("UPDATE `Job` SET environment=? WHERE jobId=?", ["system-raw&amp;", self.job_id])])
        self.assert_found("/jobs", "raw&amp;", self.job_id)

    def test_05_large_text_and_maximum_search_keep_mysql_resource_limits(self):
        suffix = "x" * 249 + "Z"
        fixture_sql([("UPDATE `Project` SET description=? WHERE projectId=?", ["p" * 64000 + suffix, self.project_id])])
        self.assert_found("/projects", suffix, self.project_id)
        self.listing("/projects", "q" * 250)
        mixed = "&a" * 125
        fixture_sql([("UPDATE `Project` SET description=? WHERE projectId=?", ["p" * 64000 + mixed.replace("&", "&amp;"), self.project_id])])
        self.assert_found("/projects", mixed, self.project_id)
        self.listing("/projects", "&b" * 125)
        self.listing("/projects", "".join(chr(0x10000 + index) for index in range(250)))
        fixture_sql([("UPDATE `Project` SET description=? WHERE projectId=?", ["Café plain", self.project_id])])
        self.assert_found("/projects", "CAFE", self.project_id)
        fixture_sql([("UPDATE `Project` SET description=? WHERE projectId=?", ["Caf&eacute; legacy", self.project_id])])
        self.assert_found("/projects", "CAFE", self.project_id)

    def test_06_exact_displayed_text_and_numeric_case_folding(self):
        cases = [
            ["A & B", "A &amp; B", True], ["ZÜRICH", "Z&uuml;rich", True],
            ["Mixed Zürich & B raw & C", "Mixed Z&uuml;rich &amp; B raw & C", True],
            ["&amp;", "&amp;amp;", True], ["&amp;", "&amp;", False], ["amp", "&amp;", False],
            ["&", "&eacute;", False], ["&", "&#129514;", False], ["Caf&", "Caf&eacute;", False],
            ["&unknown;", "&unknown;", True], ["&#0;", "&#0;", True],
            ["🧪", "&#129514;", True], ["🧪", "&#x1f9ea;", True],
            ["'quoted'", "&#039;quoted&apos;", True],
            ["100%_=[]().*+?\\E$", "100%_=[]().*+?\\E$", True],
            ["100%_=", "100abcZ=", False], ["A B", "A&amp; B", False],
            ["E", "&#101;", True], ["e", "&#69;", True], ["𐐨", "&#66560;", True],
            ["CAFE", "Caf&#233;", True], ["&amp;", "&amp&#59;", True],
            ["$", "&#36;", True], ["\\", "&#92;", True],
        ]
        for case, actual in zip(cases, fixture_search(cases), strict=True):
            with self.subTest(query=case[0], stored=case[1]):
                self.assertEqual(case[2], actual)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:8089")
    arguments, remaining = parser.parse_known_args()
    api.BASE_URL = arguments.base_url.rstrip("/")
    if urllib.parse.urlsplit(api.BASE_URL).port != 8089:
        raise SystemExit("Search contracts require the explicit v3 UI fixture on port 8089.")
    api.guard_target(api.BASE_URL)
    unittest.main(argv=[__file__, *remaining], verbosity=2)
