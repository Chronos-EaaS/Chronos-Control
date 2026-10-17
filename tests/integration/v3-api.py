#!/usr/bin/env python3
"""Real v3 HTTP contracts against explicitly identified synthetic loopback fixtures.

Only explicitly named disposable Docker fixtures may receive synthetic remember
records. Start/reset the fixture stack before this sequential suite. No production
configuration or third-party Python package is used.
"""
from __future__ import annotations

import argparse
import subprocess
import copy
import http.cookiejar
import json
import unittest
import urllib.error
import urllib.parse
import urllib.request


PASSWORD = "Chronos-test-2026!"
BASE_URL = ""
FIXTURE_PROJECT = None


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


class Client:
    def __init__(self):
        self.cookies = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPCookieProcessor(self.cookies)
        )
        self.token = ""

    def request(self, method, path, body=None, *, csrf=True, headers=None, raw=None):
        actual_headers = {"Accept": "application/json"}
        if method not in ("GET", "HEAD"):
            actual_headers["Origin"] = BASE_URL
            if csrf and self.token:
                actual_headers["X-CSRF-Token"] = self.token
        if body is not None:
            raw = json.dumps(body).encode()
            actual_headers["Content-Type"] = "application/json"
        if headers:
            actual_headers.update(headers)
        request = urllib.request.Request(BASE_URL + path, data=raw, headers=actual_headers, method=method)
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            content = response.read(2 * 1024 * 1024)
            status = response.code
            result_headers = response.headers
        try:
            value = json.loads(content)
        except json.JSONDecodeError as error:
            raise AssertionError(f"Expected JSON from {method} {path}: HTTP {status}, {content[:160]!r}") from error
        if isinstance(value.get("data"), dict) and "csrfToken" in value["data"]:
            self.token = value["data"]["csrfToken"]
        return status, value, result_headers

    def session(self):
        status, value, _ = self.request("GET", "/api/v3/session")
        assert status == 200, value
        return value["data"]

    def login(self, username, password=PASSWORD, remember=False):
        self.session()
        status, value, headers = self.request("POST", "/api/v3/session", {"username": username, "password": password, "remember": remember})
        assert status == 200, value
        return value["data"], headers

    def cookie(self, name):
        return next((cookie.value for cookie in self.cookies if cookie.name == name), None)


class V3ApiContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.clients = {}
        for username in ("owner", "member", "outsider", "admin", "superadmin"):
            client = Client()
            client.login(username)
            cls.clients[username] = client

    def data(self, client, path, *, status=200):
        actual, body, headers = client.request("GET", path)
        self.assertEqual(status, actual, body)
        self.assertIn("application/json", headers.get("Content-Type", ""))
        self.assertIn("no-store", headers.get("Cache-Control", ""))
        return body.get("data")

    def mutate(self, client, method, path, body=None, *, status=200, **options):
        actual, value, headers = client.request(method, path, body, **options)
        self.assertEqual(status, actual, value)
        self.assertIn("application/json", headers.get("Content-Type", ""))
        self.assertIn("no-store", headers.get("Cache-Control", ""))
        return value

    def test_01_public_metadata_session_and_input_boundary(self):
        client = Client()
        meta = self.data(client, "/api/v3/meta")
        self.assertEqual(3, meta["apiVersion"])
        self.assertEqual("Europe/Zurich", meta["timezone"])
        self.assertIsNone(meta["uiBuildId"])
        self.assertEqual({"minLength", "maxLength", "requireComplexity"}, set(meta["password"]))
        session = client.session()
        self.assertIsNone(session["user"])
        self.assertEqual(64, len(session["csrfToken"]))
        self.assertFalse(session["impersonation"]["active"])
        self.data(client, "/api/v3/projects", status=401)
        self.mutate(client, "POST", "/api/v3/session", {"username": "owner", "password": PASSWORD}, csrf=False, status=403)
        self.mutate(client, "POST", "/api/v3/session", {"username": "owner", "password": PASSWORD}, headers={"Origin": "https://unrelated.example.test"}, status=403)
        self.mutate(client, "POST", "/api/v3/session", raw=b"[]", headers={"Content-Type": "application/json"}, status=400)
        self.mutate(client, "POST", "/api/v3/session", raw=b"{", headers={"Content-Type": "application/json"}, status=400)
        self.mutate(client, "POST", "/api/v3/session", raw=b"username=owner", headers={"Content-Type": "application/x-www-form-urlencoded"}, status=415)
        self.mutate(client, "POST", "/api/v3/session", {"username": "disabled", "password": PASSWORD}, status=401)
        self.mutate(client, "POST", "/api/v3/session", {"username": "owner", "password": "wrong"}, status=401)

    def test_02_default_scopes_pagination_dashboard_and_secret_free_dtos(self):
        expected = {"owner": [1], "member": [1], "outsider": [2], "admin": [], "superadmin": []}
        for role, client in self.clients.items():
            listing = self.data(client, "/api/v3/projects")
            self.assertEqual(expected[role], [item["id"] for item in listing["items"]])
            self.assertEqual(5, listing["pageSize"])
            self.assertEqual(len(expected[role]), listing["total"])
        self.data(self.clients["owner"], "/api/v3/projects?all=true", status=403)
        self.assertEqual(2, self.data(self.clients["admin"], "/api/v3/projects?all=true")["total"])
        self.data(self.clients["owner"], "/api/v3/projects?sort=name%3BDROP", status=422)
        self.data(self.clients["owner"], "/api/v3/projects?pageSize=101", status=422)
        self.data(self.clients["owner"], "/api/v3/projects?all[]=true", status=422)
        self.assertEqual([], self.data(self.clients["owner"], "/api/v3/projects?page=2")["items"])
        self.assertEqual(1, self.data(self.clients["owner"], "/api/v3/projects?search=Fixture")["total"])
        dashboard = self.data(self.clients["owner"], "/api/v3/dashboard")
        self.assertEqual({"projects": 1, "experiments": 2, "evaluationsCompleted": 2, "evaluationsRunning": 2, "jobs": 15, "activeJobs": 5, "failedJobs": 1}, dashboard["counts"])
        self.assertTrue(any(event["title"] == "Fixture project created" for event in dashboard["events"]))
        outsider_events = self.data(self.clients["outsider"], "/api/v3/dashboard")["events"]
        self.assertFalse(any(event["resourceType"] == "project" and event["resourceId"] == 1 for event in outsider_events))
        system = self.data(self.clients["owner"], "/api/v3/systems/1")
        self.assertEqual("fixture-system", system["identifier"])
        self.assertFalse(system["hasLegacyResults"])
        for forbidden in ("password", "token", "vcsPassword", "vcsUser", "vcsUrl"):
            self.assertNotIn(forbidden, system)
            self.assertNotIn(forbidden, self.data(self.clients["owner"], "/api/v3/profile"))

    def test_03_role_owner_member_outsider_read_matrix(self):
        for role, client in self.clients.items():
            self.data(client, "/api/v3/projects/1", status=403 if role == "outsider" else 200)
            self.data(client, "/api/v3/systems/1", status=200 if role in ("owner", "admin", "superadmin") else 403)
            self.data(client, "/api/v3/systems/2", status=200 if role in ("outsider", "admin", "superadmin") else 403)
            self.data(client, "/api/v3/users", status=200 if role in ("admin", "superadmin") else 403)
        self.data(self.clients["owner"], "/api/v3/projects/9999", status=404)
        self.data(self.clients["owner"], "/api/v3/projects/1/members?candidates=true")
        self.data(self.clients["member"], "/api/v3/projects/1/members?candidates=true", status=403)

    def test_04_denied_mutations_leave_visible_domain_state_unchanged(self):
        owner, outsider, member = (self.clients[key] for key in ("owner", "outsider", "member"))
        private_before = self.data(outsider, "/api/v3/projects/2")
        members_before = self.data(owner, "/api/v3/projects/1/members")
        counts_before = self.data(owner, "/api/v3/dashboard")["counts"]
        self.mutate(owner, "PATCH", "/api/v3/projects/2", {"name": "Forbidden"}, status=403)
        self.mutate(outsider, "PATCH", "/api/v3/projects/1", {"description": "Forbidden"}, status=403)
        self.mutate(member, "POST", "/api/v3/projects/1/members", {"userId": 5}, status=403)
        self.mutate(member, "PATCH", "/api/v3/projects/1", {"archived": True}, status=403)
        self.mutate(owner, "POST", "/api/v3/projects", {"name": "Forbidden", "systemId": 2}, status=403)
        self.mutate(owner, "POST", "/api/v3/projects", {"name": "Forbidden", "systemId": 1, "ownerId": 5}, status=403)
        self.mutate(owner, "POST", "/api/v3/systems", {"name": "Forbidden", "ownerId": 3}, status=403)
        self.assertEqual(private_before, self.data(outsider, "/api/v3/projects/2"))
        self.assertEqual(members_before, self.data(owner, "/api/v3/projects/1/members"))
        self.assertEqual(counts_before, self.data(owner, "/api/v3/dashboard")["counts"])

    def test_05_project_member_lifecycle_and_atomic_validation(self):
        owner, member, outsider = (self.clients[key] for key in ("owner", "member", "outsider"))
        before = self.data(owner, "/api/v3/projects")["total"]
        self.mutate(owner, "POST", "/api/v3/projects", {"name": "", "systemId": 1}, status=422)
        self.mutate(owner, "POST", "/api/v3/projects", {"name": "Invalid", "systemId": 1, "ownerId": 6}, status=403)
        self.assertEqual(before, self.data(owner, "/api/v3/projects")["total"])
        project = self.mutate(owner, "POST", "/api/v3/projects", {"name": "API Zürich & XML <value>", "description": "Unicode and markup-like text", "systemId": 1}, status=201)["data"]
        project_id = project["id"]
        self.assertEqual("API Zürich & XML <value>", project["name"])
        members_path = f"/api/v3/projects/{project_id}/members"
        self.assertEqual([3], [item["id"] for item in self.data(owner, members_path)["items"]])
        self.mutate(owner, "POST", members_path, {"userId": 4})
        self.mutate(owner, "POST", members_path, {"userId": 4})
        self.assertEqual(2, len(self.data(owner, members_path)["items"]))
        self.data(outsider, f"/api/v3/projects/{project_id}", status=403)
        changed = self.mutate(member, "PATCH", f"/api/v3/projects/{project_id}", {"description": "Member edit"})["data"]
        self.assertEqual("Member edit", changed["description"])
        self.mutate(member, "DELETE", members_path + "/3", status=403)
        self.mutate(owner, "DELETE", members_path + "/3", status=422)
        self.mutate(owner, "DELETE", members_path + "/4")
        self.data(member, f"/api/v3/projects/{project_id}", status=403)
        self.mutate(owner, "PATCH", f"/api/v3/projects/{project_id}", {"archived": True})
        self.assertTrue(any(item["id"] == project_id for item in self.data(owner, "/api/v3/projects?archived=true")["items"]))
        self.mutate(owner, "PATCH", f"/api/v3/projects/{project_id}", {"archived": False})
        self.assertFalse(self.data(owner, f"/api/v3/projects/{project_id}")["archived"])

    def test_06_admin_creation_roles_and_self_protection(self):
        admin, superadmin = self.clients["admin"], self.clients["superadmin"]
        body = {"username": "api-fixture-user", "firstName": "API", "lastName": "Fixture", "email": "api-fixture@example.test", "gender": 1, "password": PASSWORD, "passwordConfirmation": PASSWORD}
        self.mutate(admin, "POST", "/api/v3/users", body | {"role": 2}, status=403)
        self.assertEqual(0, self.data(admin, "/api/v3/users?active=all&search=api-fixture-user")["total"])
        user = self.mutate(admin, "POST", "/api/v3/users", body, status=201)["data"]
        user_id = user["id"]
        self.mutate(admin, "POST", "/api/v3/users", body, status=409)
        self.mutate(admin, "PATCH", f"/api/v3/users/{user_id}", {"role": 1}, status=403)
        changed = self.mutate(superadmin, "PATCH", f"/api/v3/users/{user_id}", {"role": 1})["data"]
        self.assertEqual(1, changed["role"])
        self.mutate(admin, "PATCH", "/api/v3/users/2", {"active": False}, status=403)
        self.mutate(admin, "PATCH", "/api/v3/users/1", {"active": False}, status=403)
        self.mutate(admin, "PATCH", f"/api/v3/users/{user_id}", {"active": False})
        disabled = Client()
        disabled.session()
        self.mutate(disabled, "POST", "/api/v3/session", {"username": "api-fixture-user", "password": PASSWORD}, status=401)
        self.mutate(admin, "PATCH", f"/api/v3/users/{user_id}", {"active": True, "password": "Replacement-test-2026!", "passwordConfirmation": "Replacement-test-2026!"})
        enabled = Client()
        enabled.login("api-fixture-user", "Replacement-test-2026!")
        self.assertEqual(user_id, enabled.session()["user"]["id"])

    def test_07_profile_password_is_atomic_and_protects_privileged_fields(self):
        owner = self.clients["owner"]
        before = self.data(owner, "/api/v3/profile")
        self.mutate(owner, "PATCH", "/api/v3/profile", {"firstName": "Should not persist", "password": "Profile-test-2026!", "passwordConfirmation": "Profile-test-2026!", "currentPassword": "incorrect"}, status=422)
        self.assertEqual(before, self.data(owner, "/api/v3/profile"))
        self.mutate(owner, "PATCH", "/api/v3/profile", {"role": 2}, status=422)
        updated = self.mutate(owner, "PATCH", "/api/v3/profile", {"firstName": "Éva & XML", "password": "Profile-test-2026!", "passwordConfirmation": "Profile-test-2026!", "currentPassword": PASSWORD})["data"]
        self.assertEqual("Éva & XML", updated["firstName"])
        fresh = Client()
        fresh.login("owner", "Profile-test-2026!")
        self.mutate(fresh, "PATCH", "/api/v3/profile", {"firstName": before["firstName"], "password": PASSWORD, "passwordConfirmation": PASSWORD, "currentPassword": "Profile-test-2026!"})

    def test_08_remember_login_resume_logout_rotation_and_revocation(self):
        client = Client()
        before = client.session()
        before_id = client.cookie("PHPSESSID")
        session, headers = client.login("owner", remember=True)
        self.assertNotEqual(before_id, client.cookie("PHPSESSID"))
        self.assertNotEqual(before["csrfToken"], session["csrfToken"])
        remember = next(copy.copy(cookie) for cookie in client.cookies if cookie.name == "rememberMe")
        remember_headers = [line for line in headers.get_all("Set-Cookie", []) if line.lower().startswith("rememberme=")]
        self.assertTrue(any("HttpOnly" in line and "SameSite=Lax" in line and "path=/" in line.lower() for line in remember_headers))
        resumed = Client()
        resumed.cookies.set_cookie(copy.copy(remember))
        self.assertEqual(3, resumed.session()["user"]["id"])
        login_id = client.cookie("PHPSESSID")
        self.assertIsNone(self.mutate(client, "DELETE", "/api/v3/session")["data"]["user"])
        self.assertNotEqual(login_id, client.cookie("PHPSESSID"))
        self.assertIsNone(client.cookie("rememberMe"))
        stolen = Client()
        stolen.cookies.set_cookie(copy.copy(remember))
        self.assertIsNone(stolen.session()["user"])

    def test_09_impersonation_policy_return_and_permanent_switch(self):
        client = Client()
        client.login("admin")
        self.mutate(client, "POST", "/api/v3/session/impersonation", {"userId": 2}, status=403)
        self.mutate(client, "POST", "/api/v3/session/impersonation", {"userId": 1}, status=403)
        snapshot = self.mutate(client, "POST", "/api/v3/session/impersonation", {"userId": 3})["data"]
        self.assertEqual(3, snapshot["user"]["id"])
        self.assertEqual(2, snapshot["impersonation"]["originalUser"]["id"])
        self.mutate(client, "POST", "/api/v3/session/impersonation", {"userId": 4}, status=403)
        returned = self.mutate(client, "DELETE", "/api/v3/session")["data"]
        self.assertEqual(2, returned["user"]["id"])
        self.assertFalse(returned["impersonation"]["active"])
        hard = self.mutate(client, "POST", "/api/v3/session/impersonation", {"userId": 4, "permanent": True})["data"]
        self.assertEqual(4, hard["user"]["id"])
        self.assertFalse(hard["impersonation"]["active"])
        self.assertIsNone(self.mutate(client, "DELETE", "/api/v3/session")["data"]["user"])

    def test_10_system_creation_is_scoped_and_establishes_safe_repository_boundary(self):
        admin, owner = self.clients["admin"], self.clients["owner"]
        count = self.data(admin, "/api/v3/systems?all=true")["total"]
        self.mutate(admin, "POST", "/api/v3/systems", {"name": "Invalid", "ownerId": 6}, status=422)
        self.mutate(admin, "POST", "/api/v3/systems", {"name": "Invalid", "ownerId": 3, "storagePath": "/opt/chronos"}, status=422)
        self.assertEqual(count, self.data(admin, "/api/v3/systems?all=true")["total"])
        system = self.mutate(admin, "POST", "/api/v3/systems", {"name": "API Local System", "description": "Safe local files", "ownerId": 3}, status=201)["data"]
        self.assertTrue(system["identifier"].startswith("system-"))
        self.assertEqual(3, system["ownerId"])
        self.assertEqual(system["id"], self.data(owner, f"/api/v3/systems/{system['id']}")["id"])
        # The legacy configuration route invokes VCS::commit. This must succeed
        # only because the new system has its own verified Git boundary; the
        # verification stack substitutes inert argv-aware Git, never a remote.
        payload = urllib.parse.urlencode({"systemId": system["id"], "content": "W10="}).encode()
        status, legacy, _ = owner.request("PATCH", "/api/ui/builder", raw=payload, headers={"Content-Type": "application/x-www-form-urlencoded"})
        self.assertEqual(200, status, legacy)
        self.assertEqual(200, legacy["status"]["code"], legacy)

    def test_12_deterministic_binary_and_legacy_remember_records_restore_real_auth(self):
        if FIXTURE_PROJECT is None:
            self.skipTest("Supply --fixture-project for guarded deterministic database records.")
        # Executed only inside a named disposable container. These hard guards
        # independently prevent reading or writing any deployed database.
        script = r'''<?php
if (getenv('CHRONOS_TEST_STACK') !== '1' || file_get_contents('/opt/chronos/webroot/healthz.txt') !== "chronos-test-ready\n") {exit(51);}
define('SERVER_ROOT', '/opt/chronos');
require '/opt/chronos/constants.php';
require '/opt/chronos/config.php';
if (DB_HOST !== 'db' || DB_NAME !== 'chronos_test') {exit(52);}
require '/opt/chronos/libraries/remembertoken.php';
$db = new PDO('mysql:host=db;dbname=chronos_test', DB_USER, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$out = [];
foreach (['binary' => str_repeat("\0", 33), 'legacy' => str_repeat('L', 33)] as $kind => $raw) {
    $selector = base64_encode($kind === 'binary' ? 'nul-token' : 'old-token');
    $hash = $kind === 'binary' ? RememberToken_Library::hash($raw) : password_hash($raw, PASSWORD_BCRYPT);
    $db->prepare('DELETE FROM `Session` WHERE selector=?')->execute([$selector]);
    $db->prepare('INSERT INTO `Session` (selector,token,userId,created,expires) VALUES (?,?,3,NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY))')->execute([$selector,$hash]);
    $out[$kind] = $selector . ':' . base64_encode($raw);
}
foreach ([1,2] as $id) {
    $path='/opt/chronos/webroot/systems/'.$id.'/.git';
    if (!is_dir($path)) {mkdir($path,0700);file_put_contents($path.'/HEAD',"ref: refs/heads/master\n");}
}
file_put_contents('/opt/chronos/webroot/data/log/6.log', "Zürich & XML\n");
echo json_encode($out);
'''
        result = subprocess.run(["docker", "exec", "-i", FIXTURE_PROJECT + "-web-1", "php"], input=script, text=True, capture_output=True, timeout=20)
        self.assertEqual(0, result.returncode, result.stderr)
        values = json.loads(result.stdout)
        template_client = Client()
        template_client.login("owner", remember=True)
        template = next(cookie for cookie in template_client.cookies if cookie.name == "rememberMe")
        for kind, wire_value in values.items():
            with self.subTest(kind=kind):
                cookie = copy.copy(template)
                cookie.value = urllib.parse.quote(wire_value, safe="")
                client = Client()
                client.cookies.set_cookie(cookie)
                self.assertEqual(3, client.session()["user"]["id"])
                # Exercise the unchanged legacy home controller after removing
                # the session again, proving compatibility beyond the v3 wrapper.
                client.cookies.clear()
                client.cookies.set_cookie(copy.copy(cookie))
                response = client.opener.open(BASE_URL + "/", timeout=20)
                with response:
                    body = response.read(2 * 1024 * 1024).decode("utf-8")
                    self.assertEqual(200, response.code)
                self.assertTrue("Owner Fixture (owner)" in body and "Log out" in body, "Legacy home did not restore the owner account.")
                self.assertNotIn("Invalid class name", body)

    def test_11_v1_agent_envelope_and_cdl_survive_new_boundary(self):
        status, body, _ = Client().request("GET", "/api/v1/job/id=90")
        self.assertEqual(200, status)
        self.assertEqual(200, body["status"]["code"])
        self.assertEqual(90, body["response"]["id"])
        self.assertIn("<chronos>", body["response"]["cdl"])
        self.assertNotIn("data", body)



    def test_13_workflow_scope_metadata_actions_logs_and_cdl(self):
        owner, member, outsider = [self.clients[key] for key in ("owner", "member", "outsider")]
        for role, client in self.clients.items():
            allowed = role != "outsider"
            for path in ("/api/v3/experiments/1", "/api/v3/evaluations/1", "/api/v3/jobs/1", "/api/v3/jobs/1/cdl", "/api/v3/experiments/1/run-options", "/api/v3/projects/1/events"):
                self.data(client, path, status=200 if allowed else 403)
        self.assertEqual([1], [item["id"] for item in self.data(member,"/api/v3/projects/1/experiments")["items"]])
        self.assertEqual([2], [item["id"] for item in self.data(member,"/api/v3/projects/1/experiments?archived=true")["items"]])
        self.data(owner,"/api/v3/projects/1/experiment-options?copy=3",status=403)
        global_jobs=self.data(owner,"/api/v3/jobs?status=all")
        self.assertEqual(15,global_jobs["total"])
        self.assertEqual(5,global_jobs["pageSize"])
        self.data(owner,"/api/v3/jobs?all=true",status=403)
        self.assertEqual(6,self.data(owner,"/api/v3/evaluations/1/jobs?status=all")["total"])
        self.assertEqual(4,self.data(owner,"/api/v3/evaluations/1/jobs?status=active")["total"])
        self.assertEqual(1,self.data(owner,"/api/v3/jobs?status=finished&search=run%200")["page"])
        before=self.data(owner,"/api/v3/jobs/1")
        self.assertIsNone(before["previousId"])
        self.assertEqual(2,before["nextId"])
        if FIXTURE_PROJECT:
            utf8=self.data(owner,"/api/v3/jobs/6/log?offset=1&limit=1")
            self.assertEqual("ü",utf8["text"])
            self.assertEqual(3,utf8["nextOffset"])
        self.mutate(outsider,"PATCH","/api/v3/jobs/1",{"status":-1},status=403)
        self.assertEqual(before,self.data(owner,"/api/v3/jobs/1"))
        self.mutate(member,"PATCH","/api/v3/evaluations/1",{"starred":True})
        starred=self.data(owner,"/api/v3/projects/1/evaluations?state=running-or-starred")
        self.assertIn(1,[item["id"] for item in starred["items"]])
        self.mutate(owner,"PATCH","/api/v3/evaluations/1",{"archived":True},status=409)
        aborted=self.mutate(member,"POST","/api/v3/evaluations/1/actions",{"action":"abort"})["data"]
        self.assertEqual(4,aborted["changedJobs"])
        rescheduled=self.mutate(member,"POST","/api/v3/evaluations/1/actions",{"action":"reschedule"})["data"]
        self.assertEqual(5,rescheduled["changedJobs"])
        updated=self.mutate(owner,"PATCH","/api/v3/jobs/1",{"status":3,"progress":88,"phases":2})["data"]
        self.assertEqual("finished",updated["statusName"])
        self.assertEqual(88,updated["progress"])
        self.assertEqual(2,updated["phases"])
        self.assertEqual(3,updated["owner"]["id"])
        self.mutate(owner,"PATCH","/api/v3/jobs/1",{"progress":101},status=422)
        log=self.data(owner,"/api/v3/jobs/5/log?offset=0&limit=10")
        self.assertEqual(10,log["nextOffset"])
        self.assertFalse(log["complete"])
        self.mutate(outsider,"DELETE","/api/v3/jobs/5/log",status=403)
        self.mutate(owner,"DELETE","/api/v3/jobs/6/log")
        recovered=self.data(owner,"/api/v3/jobs/6/log?offset=9999")
        self.assertEqual("",recovered["text"])
        self.assertEqual(0,recovered["nextOffset"])
        self.assertIn("<chronos>",self.data(owner,"/api/v3/jobs/1/cdl")["xml"])
        self.assertIn("scheduled",self.data(owner,"/api/v3/status")["counts"])

    def test_14_new_schema_experiment_copy_run_validation_and_atomic_jobs(self):
        owner=self.clients["owner"]
        system=next(item for item in self.data(owner,"/api/v3/systems")["items"] if item["name"]=="API Local System")
        sid=system["id"]
        groups=[{"id":"base","title":"Base","depends":"","dependsValue":"","elements":[
            {"id":"qty","type":"integer","name":"Quantity","parameter":"quantity","default":0},
            {"id":"flag","type":"boolean","name":"Enabled","parameter":"enabled","default":False},
            {"id":"label-field","type":"string","name":"Label","parameter":"label","default":""},
            {"id":"sizes","type":"interval","name":"Size","parameter":"size","default-min":10,"default-max":20,"default-step":10},
            {"id":"software","type":"software-versions-list","name":"Version","parameter":"version"}]}]
        self.mutate(owner,"PUT",f"/api/v3/systems/{sid}/parameters",{"groups":groups})
        self.mutate(owner,"PUT",f"/api/v3/systems/{sid}/environments",{"environments":["local fixture"]})
        project=self.mutate(owner,"POST","/api/v3/projects",{"name":"Workflow Project","systemId":sid},status=201)["data"]
        pid=project["id"]
        options=self.data(owner,f"/api/v3/projects/{pid}/experiment-options")
        self.assertEqual(groups,options["groups"])
        body={"name":"Unicode workflow","description":"Ampersand & XML <value>","runs":2,"distribution":"alter","warmUp":False,"environment":"system-local fixture","parameters":{"quantity":0,"enabled":False,"label":"Zürich & XML <value>","version-version-0":"main"}}
        count=self.data(owner,f"/api/v3/projects/{pid}/experiments")["total"]
        invalid=copy.deepcopy(body);invalid["parameters"]["size-step"]=-1
        self.mutate(owner,"POST",f"/api/v3/projects/{pid}/experiments",invalid,status=422)
        self.assertEqual(count,self.data(owner,f"/api/v3/projects/{pid}/experiments")["total"])
        experiment=self.mutate(owner,"POST",f"/api/v3/projects/{pid}/experiments",body,status=201)["data"]
        eid=experiment["id"]
        self.assertEqual(2,experiment["phases"])
        self.mutate(owner,"PUT",f"/api/v3/systems/{sid}/parameters",{"groups":[]})
        copied=self.data(owner,f"/api/v3/projects/{pid}/experiment-options?copy={eid}")
        self.assertEqual(groups,copied["groups"])
        copy_body=copy.deepcopy(body);copy_body["name"]="Copied snapshot";copy_body["sourceExperimentId"]=eid
        copy_experiment=self.mutate(owner,"POST",f"/api/v3/projects/{pid}/experiments",copy_body,status=201)["data"]
        self.assertEqual(groups,self.data(owner,f"/api/v3/experiments/{copy_experiment['id']}/parameters")["groups"])
        self.assertEqual(0,copied["values"]["quantity"])
        self.assertFalse(copied["values"]["enabled"])
        self.assertFalse(copied["defaults"]["warmUp"])
        before=self.data(owner,f"/api/v3/experiments/{eid}/evaluations")["total"]
        self.mutate(owner,"POST",f"/api/v3/experiments/{eid}/evaluations",{"environment":"cem-invalid"},status=422)
        self.assertEqual(before,self.data(owner,f"/api/v3/experiments/{eid}/evaluations")["total"])
        evaluation=self.mutate(owner,"POST",f"/api/v3/experiments/{eid}/evaluations",{"environment":"system-local fixture","versions":{"version":["v2"]}},status=201)["data"]
        self.assertEqual(4,evaluation["counts"]["total"])
        jobs=self.data(owner,f"/api/v3/evaluations/{evaluation['id']}/jobs")
        self.assertEqual(4,jobs["total"])
        for job in jobs["items"]:
            self.assertEqual(0,job["configuration"]["parameters"]["quantity"])
            self.assertEqual("false",job["configuration"]["parameters"]["enabled"])
            self.assertEqual("v2",job["configuration"]["parameters"]["version"])
            cdl=self.data(owner,f"/api/v3/jobs/{job['id']}/cdl")["xml"]
            self.assertIn("Zürich &amp; XML &lt;value&gt;",cdl)
        self.mutate(owner,"PATCH",f"/api/v3/experiments/{eid}",{"archived":True},status=409)
        self.mutate(owner,"POST",f"/api/v3/evaluations/{evaluation['id']}/actions",{"action":"abort"})
        self.mutate(owner,"PATCH",f"/api/v3/experiments/{eid}",{"archived":True})
        self.mutate(owner,"POST",f"/api/v3/experiments/{eid}/evaluations",{"environment":"system-local fixture"},status=409)

    def test_15_admin_settings_revisions_environments_nodes_and_roles(self):
        admin, owner = self.clients["admin"],self.clients["owner"]
        for path in ("/api/v3/admin/settings","/api/v3/admin/storage","/api/v3/cem/nodes"):
            self.data(owner,path,status=403)
        sections=self.data(admin,"/api/v3/admin/settings")["sections"]
        for section in sections:
            for setting in section["settings"]:
                if setting["secret"]: self.assertIsNone(setting["value"])
        other=next(section for section in sections if section["name"]=="other")
        changed=self.mutate(admin,"PATCH","/api/v3/admin/settings/other",{"revision":other["revision"],"values":{"rowsPerPage":"7"}})["data"]
        self.assertNotEqual(other["revision"],changed["revision"])
        self.assertEqual(7,self.data(owner,"/api/v3/projects")["pageSize"])
        self.mutate(admin,"PATCH","/api/v3/admin/settings/other",{"revision":other["revision"],"values":{"rowsPerPage":"8"}},status=409)
        self.mutate(admin,"PATCH","/api/v3/admin/settings/other",{"revision":changed["revision"],"values":{"rowsPerPage":"5"}})
        environments=self.data(admin,"/api/v3/admin/environments")["items"]
        self.mutate(admin,"POST","/api/v3/admin/environments",{"name":"extra fixture"},status=201)
        self.mutate(admin,"POST","/api/v3/admin/environments",{"name":"extra fixture"},status=409)
        self.mutate(admin,"DELETE","/api/v3/admin/environments/extra%20fixture")
        nodes=self.data(admin,"/api/v3/cem/nodes?includeMissing=true")
        self.assertTrue(nodes)
        self.data(admin,"/api/v3/cem/nodes/fixture-warning")
        self.data(admin,"/api/v3/admin/maintenance",status=403)
        self.data(self.clients["superadmin"],"/api/v3/admin/maintenance")

    def test_16_results_and_binary_downloads_remain_project_scoped(self):
        owner,outsider=self.clients["owner"],self.clients["outsider"]
        self.data(outsider,"/api/v3/evaluations/2/results",status=403)
        result=self.data(owner,"/api/v3/evaluations/2/results")
        self.assertEqual(6,result["finishedJobs"])
        self.assertTrue(result["configurations"])
        self.assertTrue(result["charts"])
        for path in ("/api/v3/jobs/7/download","/api/v3/evaluations/2/download"):
            with owner.opener.open(BASE_URL+path,timeout=20) as response:
                self.assertEqual(200,response.code)
                self.assertIn("zip",response.headers.get("Content-Type",""))
                self.assertTrue(response.read(4).startswith(b"PK"))



    def test_17_experiment_result_configuration_scope_and_selected_deletion(self):
        owner,member,outsider=[self.clients[key] for key in ("owner","member","outsider")]
        document=self.data(member,"/api/v3/experiments/1/result-configurations")
        document["elements"]["experiment-1-api"]={"name":"API scoped result","all":[],"job":[]}
        saved=self.mutate(member,"PUT","/api/v3/experiments/1/result-configurations",document)["data"]
        self.assertIn("experiment-1-api",saved["elements"])
        self.data(outsider,"/api/v3/experiments/1/result-configurations",status=403)
        self.mutate(owner,"PATCH","/api/v3/experiments/1",{"resultId":"experiment-1-api"})
        without=copy.deepcopy(saved);del without["elements"]["experiment-1-api"]
        self.mutate(member,"PUT","/api/v3/experiments/1/result-configurations",without,status=409)
        forged=copy.deepcopy(saved);forged["elements"]["experiment-2-foreign"]={"name":"Foreign","all":[],"job":[]}
        self.mutate(member,"PUT","/api/v3/experiments/1/result-configurations",forged,status=403)
        changed_system=copy.deepcopy(saved);system_id=next(key for key in changed_system["elements"] if key.startswith("system-"));changed_system["elements"][system_id]["name"]="Forbidden change"
        self.mutate(member,"PUT","/api/v3/experiments/1/result-configurations",changed_system,status=403)
        self.assertNotIn("experiment-1-api",self.data(owner,"/api/v3/experiments/2/result-configurations")["elements"])
        self.mutate(owner,"PATCH","/api/v3/experiments/1",{"resultId":system_id})
        self.mutate(member,"PUT","/api/v3/experiments/1/result-configurations",without)


def guard_target(base_url):
    parsed = urllib.parse.urlsplit(base_url)
    if parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1", "localhost") or parsed.port not in (8088, 8089) or parsed.path not in ("", "/") or parsed.query or parsed.fragment or parsed.username or parsed.password:
        raise SystemExit("Refusing API tests outside the explicit loopback fixture ports.")
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    with opener.open(base_url.rstrip("/") + "/healthz.txt", timeout=5) as response:
        if response.read(128) != b"chronos-test-ready\n":
            raise SystemExit("Refusing API tests without the exact synthetic fixture marker.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:8089")
    parser.add_argument("--fixture-project", choices=("chronos-v3-api-verification", "chronos-v3-regression"))
    options, remaining = parser.parse_known_args()
    BASE_URL = options.base_url.rstrip("/")
    guard_target(BASE_URL)
    FIXTURE_PROJECT = options.fixture_project
    if FIXTURE_PROJECT and ((BASE_URL.endswith(":8089")) != (FIXTURE_PROJECT == "chronos-v3-api-verification")):
        raise SystemExit("Fixture project must match the guarded loopback port.")
    unittest.main(argv=[__file__, *remaining], verbosity=2)
