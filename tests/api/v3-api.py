#!/usr/bin/env python3
"""Real v3 HTTP contracts against explicitly identified synthetic loopback fixtures.

Only explicitly named disposable Docker fixtures may receive synthetic remember
records. Start/reset the fixture stack before this sequential suite. No production
configuration or third-party Python package is used.
"""
from __future__ import annotations

import argparse
import subprocess
import time
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
INCLUDE_DEMO_RESET = False
EXPECT_BUILT_UI = False


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
        if EXPECT_BUILT_UI or FIXTURE_PROJECT == "chronos-v3-ui-regression":
            self.assertRegex(meta["uiBuildId"], r"^[a-f0-9]{40}$")
        else:
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
        owner.login("owner")

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
        # A real authorized schema write must use this system's independent
        # repository. The fixture Git adapter is inert and never contacts a remote.
        saved=self.mutate(owner,"PUT",f"/api/v3/systems/{system['id']}/parameters",{"groups":[]})["data"]
        self.assertEqual([],saved["groups"])
        # Exercise actual GD decode/re-encode, including the PHP 8.5 path.
        png="iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWNgYGAAAAAEAAGjChXjAAAAAElFTkSuQmCC"
        logo=self.mutate(owner,"PUT",f"/api/v3/systems/{system['id']}/logo",{"dataUrl":"data:image/png;base64,"+png})["data"]
        self.assertEqual(f"/api/v3/systems/{system['id']}/logo",logo["logoUrl"])
        with owner.opener.open(BASE_URL+logo["logoUrl"],timeout=10) as response:
            self.assertEqual("image/png",response.headers.get_content_type())
            self.assertTrue(response.read(1024).startswith(b"\x89PNG\r\n\x1a\n"))

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
require '/opt/chronos/vendor/autoload.php';
$db = new PDO('mysql:host=db;dbname=chronos_test', DB_USER, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$out = [];
foreach (['binary' => str_repeat("\0", 33), 'legacy' => str_repeat('L', 33)] as $kind => $raw) {
    $selector = base64_encode($kind === 'binary' ? 'nul-token' : 'old-token');
    $hash = $kind === 'binary' ? \Chronos\Infrastructure\Security\RememberToken::hash($raw) : password_hash($raw, PASSWORD_BCRYPT);
    $db->prepare('DELETE FROM `Session` WHERE selector=?')->execute([$selector]);
    $db->prepare('INSERT INTO `Session` (selector,token,userId,created,expires) VALUES (?,?,3,NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY))')->execute([$selector,$hash]);
    $out[$kind] = $selector . ':' . base64_encode($raw);
}
if (getenv('CHRONOS_TEST_V3') !== '1' || !is_file('/opt/chronos/webroot/systems/1/.git/chronos-fixture-commits') || !is_file('/opt/chronos/webroot/systems/2/.git/chronos-fixture-commits')) {exit(54);}
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
                # Exercise raw legacy Auth through the preserved agent v1
                # handler, without an existing PHP session or the v3 wrapper.
                # Its event actor proves remember restoration happened in v1.
                client.cookies.clear()
                client.cookies.set_cookie(copy.copy(cookie))
                status,agent,_=client.request("PATCH","/api/v1/job/id=90",raw=b"status=0",headers={"Content-Type":"application/x-www-form-urlencoded"})
                self.assertEqual(200,status,agent)
                self.assertEqual(200,agent["status"]["code"],agent)
                self.assertEqual(3,client.session()["user"]["id"])
                events=self.data(client,"/api/v3/jobs/90/events?pageSize=1&sort=-id")["items"]
                self.assertEqual("Owner Fixture",events[0]["actorName"])

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
        self.mutate(owner,"PUT",f"/api/v3/systems/{sid}/settings",{"defaultValues":{"phase_warmUp":"unchecked","environment":"system-local fixture"}})
        project=self.mutate(owner,"POST","/api/v3/projects",{"name":"Workflow Project","systemId":sid},status=201)["data"]
        pid=project["id"]
        options=self.data(owner,f"/api/v3/projects/{pid}/experiment-options")
        self.assertEqual(groups,options["groups"])
        self.assertFalse(options["defaults"]["warmUp"])
        self.assertEqual("system-local fixture",options["defaults"]["environment"])
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



    def fixture(self, action, input_value=""):
        if FIXTURE_PROJECT is None:
            self.skipTest("Supply --fixture-project for guarded session fixture preparation.")
        result=subprocess.run(["docker","exec","-i",FIXTURE_PROJECT+"-web-1","php","/opt/chronos/tests/fixtures/session-revocation.php",action],input=input_value,text=True,capture_output=True,timeout=30)
        self.assertEqual(0,result.returncode,result.stderr)

    def test_18_password_reset_revokes_other_active_php_sessions(self):
        first,second=Client(),Client()
        first.login("owner");second.login("owner")
        admin=Client();admin.login("admin")
        password="Revoked-test-2026!"
        self.mutate(admin,"PATCH","/api/v3/users/3",{"password":password,"passwordConfirmation":password})
        self.data(first,"/api/v3/profile",status=401)
        self.data(second,"/api/v3/profile",status=401)
        self.assertIsNone(first.session()["user"])
        self.assertIsNone(second.session()["user"])
        first.login("owner",password);second.login("owner",password)
        before=first.cookie("PHPSESSID")
        updated="Personal-test-2026!"
        self.mutate(first,"PATCH","/api/v3/profile",{"currentPassword":password,"password":updated,"passwordConfirmation":updated})
        self.assertNotEqual(before,first.cookie("PHPSESSID"))
        self.assertEqual(3,self.data(first,"/api/v3/profile")["id"])
        self.data(second,"/api/v3/profile",status=401)
        self.mutate(first,"PATCH","/api/v3/profile",{"currentPassword":updated,"password":PASSWORD,"passwordConfirmation":PASSWORD})
        superadmin=Client();superadmin.login("superadmin")
        member=Client();member.login("member")
        self.mutate(superadmin,"PATCH","/api/v3/users/4",{"role":1})
        self.data(member,"/api/v3/profile",status=401)
        self.mutate(superadmin,"PATCH","/api/v3/users/4",{"role":0})
        switched=Client();switched.login("admin")
        self.mutate(switched,"POST","/api/v3/session/impersonation",{"userId":3})
        admin_password="Admin-revoked-2026!"
        self.mutate(superadmin,"PATCH","/api/v3/users/2",{"password":admin_password,"passwordConfirmation":admin_password})
        self.data(switched,"/api/v3/profile",status=401)
        self.mutate(superadmin,"PATCH","/api/v3/users/2",{"password":PASSWORD,"passwordConfirmation":PASSWORD})
        legacy=Client();legacy.session()
        self.fixture("legacy-session",legacy.cookie("PHPSESSID"))
        self.data(legacy,"/api/v3/profile",status=401)
        self.assertIsNone(legacy.session()["user"])

    def test_19_demo_reset_revokes_same_credential_sessions_via_persistent_epoch(self):
        if not INCLUDE_DEMO_RESET:
            self.skipTest("Supply --include-demo-reset only on the disposable fixture stack.")
        owner,administrator=Client(),Client()
        owner.login("owner");administrator.login("superadmin")
        self.fixture("demo-snapshot")
        preset_hash=self.data(owner,"/api/v3/projects/1/parameter-presets")["schemaHash"]
        self.mutate(owner,"POST","/api/v3/projects/1/parameter-presets",{"name":"Old demo private draft","values":{"label":"Must not survive recycled IDs"},"schemaHash":preset_hash},status=201)
        # The Apache test runtime may revalidate changed PHP configuration on
        # its normal OPcache interval; wait for the advertised capability.
        deadline=time.monotonic()+10
        while not self.data(administrator,"/api/v3/admin/maintenance")["capabilities"]["reset-demo"]:
            self.assertLess(time.monotonic(),deadline,"The disposable demo fixture was not enabled.")
            time.sleep(0.2)
        review=self.mutate(administrator,"POST","/api/v3/admin/operations",{"action":"reset-demo"},status=201)["data"]
        self.assertTrue(review["canExecute"])
        done=self.mutate(administrator,"POST",f"/api/v3/admin/operations/{review['id']}/execute",{"confirmation":review["confirmation"]})["data"]
        self.assertTrue(done["signedOut"])
        self.data(owner,"/api/v3/profile",status=401)
        self.assertIsNone(administrator.session()["user"])
        restored=Client();self.assertEqual(3,restored.login("owner")[0]["user"]["id"])
        self.assertEqual([],self.data(restored,"/api/v3/projects/1/parameter-presets")["items"])
        self.assertEqual(1,Client().login("superadmin")[0]["user"]["id"])



    def test_20_result_paging_preserves_complete_chart_aggregates(self):
        owner=Client();owner.login("owner")
        full=self.data(owner,"/api/v3/evaluations/2/results?pageSize=100")
        first=self.data(owner,"/api/v3/evaluations/2/results?pageSize=1&sort=-id")
        self.assertEqual(6,full["jobPage"]["total"])
        self.assertEqual(1,len(first["jobs"]))
        self.assertEqual(full["charts"],first["charts"])
        self.assertEqual(full["totalJobs"],first["totalJobs"])
        self.assertEqual(full["activeJobs"],first["activeJobs"])
        absent=self.data(owner,"/api/v3/evaluations/2/results?pageSize=1&search=absent-synthetic-result")
        self.assertEqual([],absent["jobs"])
        self.assertEqual(0,absent["jobPage"]["total"])
        self.assertEqual(full["charts"],absent["charts"])
        self.assertEqual(full["activeJobs"],absent["activeJobs"])

    def test_21_extension_catalog_pages_aliases_and_trusted_processors(self):
        owner,member,outsider=Client(),Client(),Client();owner.login("owner");member.login("member");outsider.login("outsider")
        catalog=self.data(owner,"/api/v3/systems/1/extensions")
        self.assertEqual("ready",catalog["parameters"][1]["status"])
        self.assertEqual("bar",catalog["plots"][0]["renderer"])
        visible=self.data(member,"/api/v3/systems/1/extensions")
        self.assertEqual(["overview","live"],[p["id"] for p in visible["pages"]])
        self.data(outsider,"/api/v3/systems/1/extensions",status=403)
        blocked=self.data(outsider,"/api/v3/systems/2/extensions")
        self.assertEqual("blocked",blocked["parameters"][1]["status"])
        self.data(outsider,"/api/v3/systems/2/pages/live",status=409)
        page=self.data(member,"/api/v3/systems/1/pages/overview")
        self.assertEqual(["text","metrics","table","chart"],[b["type"] for b in page["blocks"]])
        self.assertIn("<value>",page["blocks"][0]["text"])
        self.data(member,"/api/v3/systems/1/pages/private",status=403)
        self.assertEqual("private",self.data(owner,"/api/v3/systems/1/pages/private")["id"])
        live=self.data(member,"/api/v3/systems/1/pages/live")
        self.assertEqual(4,live["blocks"][0]["items"][1]["value"])
        self.data(owner,"/api/v3/systems/1/pages/unknown",status=404)
        original=self.data(owner,"/api/v3/systems/1/parameters")
        groups=[{"id":"extension","title":"Extensions","elements":[{"id":"label","type":"custom-label","name":"Label","parameter":"label","default":""},{"id":"quantity","type":"custom-double","name":"Quantity","parameter":"quantity","default":0}]}]
        try:
            saved=self.mutate(owner,"PUT","/api/v3/systems/1/parameters",{"groups":groups})["data"]
            self.assertEqual("custom-double",saved["groups"][0]["elements"][1]["type"])
            project=self.mutate(owner,"POST","/api/v3/projects",{"name":"Extension Project","systemId":1},status=201)["data"]
            experiment=self.mutate(owner,"POST",f"/api/v3/projects/{project['id']}/experiments",{"name":"Extension run","runs":1,"distribution":"alter","warmUp":False,"environment":"system-local","parameters":{"label":"Zürich & <value>","quantity":3}},status=201)["data"]
            evaluation=self.mutate(owner,"POST",f"/api/v3/experiments/{experiment['id']}/evaluations",{"environment":"system-local"},status=201)["data"]
            jobs=self.data(owner,f"/api/v3/evaluations/{evaluation['id']}/jobs")["items"]
            self.assertEqual(6,jobs[0]["configuration"]["parameters"]["quantity"])
            self.assertEqual("Zürich & <value>",jobs[0]["configuration"]["parameters"]["label"])
        finally:
            self.mutate(owner,"PUT","/api/v3/systems/1/parameters",original)
        document=self.data(owner,"/api/v3/systems/1/result-configurations")
        changed=copy.deepcopy(document)
        changed["elements"]["system-extension"]={"name":"Custom extension","all":[{"id":"custom-bar","type":"custom-bar","name":"Custom bar","parameter":"metric"},{"id":"custom-score","type":"custom-score","name":"Custom score","parameter":"metric"}],"job":[]}
        try:
            self.mutate(owner,"PUT","/api/v3/systems/1/result-configurations",changed)
            results=self.data(owner,"/api/v3/evaluations/2/results?configurationId=system-extension")
            self.assertEqual(2,len(results["charts"]))
            self.assertTrue(all("error" not in chart for chart in results["charts"]))
            self.assertEqual(["bar","line"],[chart["renderer"] for chart in results["charts"]])
            self.assertGreater(results["charts"][1]["data"]["datasets"][0]["data"][0],0)
        finally:
            self.mutate(owner,"PUT","/api/v3/systems/1/result-configurations",document)



    def test_22_project_parameter_preset_policy_crud_and_schema_conflicts(self):
        owner,member,outsider=Client(),Client(),Client();owner.login("owner");member.login("member");outsider.login("outsider")
        path="/api/v3/projects/1/parameter-presets"
        before=self.data(owner,path);schema=before["schemaHash"]
        self.data(outsider,path,status=403)
        self.data(member,"/api/v3/projects/2/parameter-presets",status=403)
        body={"name":"Shared zero and false","values":{"quantity":0,"enabled":False,"hidden-label":"Zürich & <value>"},"schemaHash":schema}
        self.mutate(outsider,"POST",path,body,status=403)
        created=self.mutate(member,"POST",path,body,status=201)["data"]
        self.assertEqual(body["values"],created["values"])
        self.assertEqual(4,created["createdBy"])
        self.assertTrue(created["compatible"])
        endpoint=path+"/"+created["id"]
        self.mutate(owner,"POST",path,body,status=409)
        invalid=copy.deepcopy(body);invalid["name"]="Malformed";invalid["values"]={"nested":[{"unsafe":"object"}]}
        self.mutate(owner,"POST",path,invalid,status=422)
        self.assertEqual(len(before["items"])+1,len(self.data(owner,path)["items"]))
        changed={"name":"Renamed shared preset","values":{},"schemaHash":schema,"revision":created["revision"]}
        updated=self.mutate(owner,"PUT",endpoint,changed)["data"]
        self.assertEqual({},updated["values"])
        self.assertEqual(3,updated["updatedBy"])
        self.assertNotEqual(created["revision"],updated["revision"])
        self.mutate(member,"PUT",endpoint,changed,status=409)
        self.mutate(member,"DELETE",endpoint,{"revision":created["revision"]},status=409)
        original=self.data(owner,"/api/v3/systems/1/parameters")
        replacement=copy.deepcopy(original);replacement["groups"][0]["title"]="Changed preset schema"
        try:
            self.mutate(owner,"PUT","/api/v3/systems/1/parameters",replacement)
            stale=self.data(member,path)
            self.assertNotEqual(schema,stale["schemaHash"])
            self.assertFalse(next(item for item in stale["items"] if item["id"]==created["id"])["compatible"])
            self.mutate(member,"POST",path,{"name":"Old schema","values":{},"schemaHash":schema},status=409)
        finally:
            self.mutate(owner,"PUT","/api/v3/systems/1/parameters",original)
        self.assertTrue(self.mutate(member,"DELETE",endpoint,{"revision":updated["revision"]})["data"]["deleted"])
        self.assertEqual(before["items"],self.data(owner,path)["items"])


    def test_23_versioned_definition_create_copy_run_and_legacy_compatibility(self):
        # Fresh clients keep this case independent of optional demo/session resets.
        owner, admin, outsider = Client(), Client(), Client()
        owner.login("owner"); admin.login("admin"); outsider.login("outsider")
        system = self.mutate(admin, "POST", "/api/v3/systems", {
            "name": "Definition envelope fixture", "ownerId": 3,
        }, status=201)["data"]
        sid = system["id"]
        base_elements = [
            {"id": "user-runs", "type": "integer", "name": "User runs value", "parameter": "runs", "default": 99},
            {"id": "user-elements", "type": "string", "name": "Element metadata-like value", "parameter": "_v3Elements", "default": "Zürich & <value>"},
            {"id": "user-groups", "type": "string", "name": "Group metadata-like value", "parameter": "_v3Groups", "default": "retain this user field"},
            {"id": "user-values", "type": "boolean", "name": "Draft metadata-like value", "parameter": "_v3Values", "default": False},
            {"id": "user-format", "type": "string", "name": "Format", "parameter": "format", "default": "chronos-experiment"},
            {"id": "user-version", "type": "integer", "name": "Version", "parameter": "version", "default": 1},
            {"id": "quarter", "type": "percentage", "name": "Quarter", "parameter": "quarter", "dependency": "half", "default": 50},
            {"id": "half", "type": "percentage", "name": "Half", "parameter": "half", "dependency": "total", "default": 50},
            {"id": "total", "type": "integer", "name": "Total", "parameter": "total", "default": 100},
            {"id": "software", "type": "software-versions-list", "name": "Software", "parameter": "software"},
        ]
        groups = [{"id": "base", "title": "Definition values", "depends": "", "dependsValue": "", "elements": base_elements}]
        for index, (dependency, expected) in enumerate((("runs", "99"), ("run-distribution", "order"), ("phase_warmUp", "0"))):
            groups.append({"id": f"conditional-{index}", "title": f"Conditional {index}", "depends": dependency, "dependsValue": expected,
                           "elements": [{"id": f"detail-{index}", "type": "integer", "name": f"Detail {index}", "parameter": f"detail{index}", "default": index + 7}]})
        self.mutate(owner, "PUT", f"/api/v3/systems/{sid}/parameters", {"groups": groups})
        self.mutate(owner, "PUT", f"/api/v3/systems/{sid}/environments", {"environments": ["local"]})
        project = self.mutate(owner, "POST", "/api/v3/projects", {"name": "Definition snapshots", "systemId": sid}, status=201)["data"]
        path = f"/api/v3/projects/{project['id']}/experiments"
        parameters = {"runs": 99, "_v3Elements": "Zürich & <value>", "_v3Groups": "retain this user field", "_v3Values": False,
                      "software-numberOfVersions": 2, "software-version-0": "main", "software-version-1": "release", "quarter": 999, "half": 999}
        create = {"name": "Stored definition", "description": "Explicit metadata isolation", "runs": 2, "distribution": "order", "warmUp": False,
                  "environment": "system-local", "parameters": parameters}
        source = self.mutate(owner, "POST", path, create, status=201)["data"]
        eid = source["id"]
        original = self.data(owner, f"/api/v3/experiments/{eid}/parameters")
        self.assertEqual(groups, original["groups"])
        self.assertEqual(99, original["values"]["runs"])
        self.assertIs(original["values"]["_v3Values"], False)
        self.assertEqual("retain this user field", original["values"]["_v3Groups"])
        self.assertEqual("Zürich & <value>", original["values"]["_v3Elements"])
        self.assertEqual("chronos-experiment", original["values"]["format"])
        self.assertEqual(1, original["values"]["version"])
        self.data(outsider, f"/api/v3/experiments/{eid}/parameters", status=403)
        self.mutate(outsider, "POST", f"/api/v3/experiments/{eid}/evaluations", {"environment": "system-local"}, status=403)
        # Removing the current schema must not alter either stored snapshot or copy.
        self.mutate(owner, "PUT", f"/api/v3/systems/{sid}/parameters", {"groups": []})
        options = self.data(owner, f"/api/v3/projects/{project['id']}/experiment-options?copy={eid}")
        self.assertEqual(original["groups"], options["groups"])
        self.assertEqual(original["values"], options["values"])
        self.assertEqual({"runs": 2, "distribution": "order", "warmUp": False, "environment": "system-local"}, options["defaults"])
        copied = self.mutate(owner, "POST", path, dict(create, name="Copied definition", sourceExperimentId=eid, parameters=options["values"]), status=201)["data"]
        self.assertEqual(original, self.data(owner, f"/api/v3/experiments/{copied['id']}/parameters"))
        source_run = self.mutate(owner, "POST", f"/api/v3/experiments/{eid}/evaluations", {"environment": "system-local", "versions": {"software": ["patched"]}}, status=201)["data"]
        copy_run = self.mutate(owner, "POST", f"/api/v3/experiments/{copied['id']}/evaluations", {"environment": "system-local"}, status=201)["data"]
        for evaluation, expected_versions in ((source_run, ["patched"]), (copy_run, ["main", "release"])):
            jobs = self.data(owner, f"/api/v3/evaluations/{evaluation['id']}/jobs?pageSize=100")
            self.assertEqual(2 * len(expected_versions), evaluation["counts"]["total"])
            self.assertEqual(2 * len(expected_versions), jobs["total"])
            self.assertCountEqual([(version, run) for version in expected_versions for run in range(2)],
                                  [(job["configuration"]["parameters"]["software"], job["configuration"]["parameters"]["run"]) for job in jobs["items"]])
            for job in jobs["items"]:
                values = job["configuration"]["parameters"]
                self.assertEqual(99, values["runs"])
                self.assertEqual("false", values["_v3Values"])
                self.assertEqual("Zürich & <value>", values["_v3Elements"])
                self.assertEqual("retain this user field", values["_v3Groups"])
                self.assertEqual("chronos-experiment", values["format"])
                self.assertEqual(1, values["version"])
                self.assertEqual((100, "50", "25"), (values["total"], values["half"], values["quarter"]))
                self.assertEqual((7, 8, 9), (values["detail0"], values["detail1"], values["detail2"]))
                self.assertEqual(2, job["phases"])
                xml = self.data(owner, f"/api/v3/jobs/{job['id']}/cdl")["xml"]
                self.assertIn("<runs>99</runs>", xml)
                self.assertIn("Zürich &amp; &lt;value&gt;", xml)
        self.assertEqual(original, self.data(owner, f"/api/v3/experiments/{eid}/parameters"))
        self.assertEqual(original, self.data(owner, f"/api/v3/experiments/{copied['id']}/parameters"))

        # The seeded v2 experiment has only one saved descriptor although the
        # current system schema has all 12 controls. Running it must keep that shape.
        legacy_before = self.data(owner, "/api/v3/experiments/1/parameters")
        legacy_run = self.mutate(owner, "POST", "/api/v3/experiments/1/evaluations", {"environment": "system-local"}, status=201)["data"]
        legacy_jobs = self.data(owner, f"/api/v3/evaluations/{legacy_run['id']}/jobs?pageSize=100")
        self.assertEqual(1, legacy_jobs["total"])
        self.assertEqual({"quantity": 10, "run": 0}, legacy_jobs["items"][0]["configuration"]["parameters"])
        self.assertEqual(legacy_before, self.data(owner, "/api/v3/experiments/1/parameters"))
        legacy_project = self.mutate(owner, "POST", "/api/v3/projects", {"name": "Legacy copy compatibility", "systemId": 1}, status=201)["data"]
        legacy_options = self.data(owner, f"/api/v3/projects/{legacy_project['id']}/experiment-options?copy=1")
        legacy_values = dict(legacy_options["values"], **{"version-version-0": "legacy-copy"})
        legacy_copy = self.mutate(owner, "POST", f"/api/v3/projects/{legacy_project['id']}/experiments", {
            "name": "Copy of legacy definition", "sourceExperimentId": 1, "parameters": legacy_values,
            "runs": legacy_options["defaults"]["runs"], "distribution": legacy_options["defaults"]["distribution"],
            "warmUp": legacy_options["defaults"]["warmUp"], "environment": "system-local",
        }, status=201)["data"]
        self.assertEqual(legacy_options["groups"], self.data(owner, f"/api/v3/experiments/{legacy_copy['id']}/parameters")["groups"])
        copied_run = self.mutate(owner, "POST", f"/api/v3/experiments/{legacy_copy['id']}/evaluations", {"environment": "system-local"}, status=201)["data"]
        copied_jobs = self.data(owner, f"/api/v3/evaluations/{copied_run['id']}/jobs?pageSize=100")
        self.assertEqual(8, copied_jobs["total"])
        for job in copied_jobs["items"]:
            self.assertEqual(10, job["configuration"]["parameters"]["quantity"])
            self.assertEqual("legacy-copy", job["configuration"]["parameters"]["version"])
        self.assertEqual(legacy_before, self.data(owner, "/api/v3/experiments/1/parameters"))

    def test_24_new_login_replaces_only_the_current_browser_remember_identity(self):
        for remember_target in (False, True):
            with self.subTest(remember_target=remember_target):
                client = Client()
                client.login("owner", remember=True)
                previous = next(copy.copy(cookie) for cookie in client.cookies if cookie.name == "rememberMe")
                other_browser = Client()
                other_browser.login("owner", remember=True)
                independent = next(copy.copy(cookie) for cookie in other_browser.cookies if cookie.name == "rememberMe")
                # A failed attempt must not revoke the browser's valid account.
                self.mutate(client, "POST", "/api/v3/session", {
                    "username": "outsider", "password": "Incorrect-password-2026!", "remember": remember_target,
                }, status=401)
                self.assertEqual(previous.value, client.cookie("rememberMe"))
                self.assertEqual("owner", client.session()["user"]["username"])
                signed_in, _ = client.login("outsider", remember=remember_target)
                self.assertEqual("outsider", signed_in["user"]["username"])
                if remember_target:
                    self.assertIsNotNone(client.cookie("rememberMe"))
                    self.assertNotEqual(previous.value, client.cookie("rememberMe"))
                else:
                    self.assertIsNone(client.cookie("rememberMe"))
                # Closing the session must never silently restore the old account.
                for cookie in list(client.cookies):
                    if cookie.name == "PHPSESSID":
                        client.cookies.clear(cookie.domain, cookie.path, cookie.name)
                resumed = client.session()["user"]
                self.assertEqual("outsider" if remember_target else None, resumed["username"] if resumed else None)
                obsolete = Client()
                obsolete.cookies.set_cookie(previous)
                self.assertIsNone(obsolete.session()["user"])
                unaffected = Client()
                unaffected.cookies.set_cookie(independent)
                self.assertEqual("owner", unaffected.session()["user"]["username"])

    def test_25_cem_pagination_uses_the_configured_default_and_explicit_override(self):
        for role in ("admin", "superadmin"):
            # Earlier contracts deliberately invalidate every existing session.
            client = Client()
            client.login(role)
            self.assertEqual(5, self.data(client, "/api/v3/cem/nodes")["pageSize"])
            self.assertEqual(2, self.data(client, "/api/v3/cem/nodes?pageSize=2")["pageSize"])
        owner = Client()
        owner.login("owner")
        self.data(owner, "/api/v3/cem/nodes", status=403)


def guard_target(base_url):
    parsed = urllib.parse.urlsplit(base_url)
    if parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1", "localhost") or parsed.port not in (8088, 8089, 8090) or parsed.path not in ("", "/") or parsed.query or parsed.fragment or parsed.username or parsed.password:
        raise SystemExit("Refusing API tests outside the explicit loopback fixture ports.")
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    with opener.open(base_url.rstrip("/") + "/healthz.txt", timeout=5) as response:
        if response.read(128) != b"chronos-test-ready\n":
            raise SystemExit("Refusing API tests without the exact synthetic fixture marker.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:8089")
    parser.add_argument("--fixture-project", choices=("chronos-v3-api-verification", "chronos-v3-regression", "chronos-v3-session-verification", "chronos-v3-ui-regression"))
    parser.add_argument("--include-demo-reset", action="store_true")
    parser.add_argument("--expect-built-ui", action="store_true")
    options, remaining = parser.parse_known_args()
    BASE_URL = options.base_url.rstrip("/")
    guard_target(BASE_URL)
    FIXTURE_PROJECT = options.fixture_project
    INCLUDE_DEMO_RESET = options.include_demo_reset
    EXPECT_BUILT_UI = options.expect_built_ui
    expected_ports = {"chronos-v3-regression":8088,"chronos-v3-api-verification":8089,"chronos-v3-session-verification":8090,"chronos-v3-ui-regression":8089}
    if FIXTURE_PROJECT and urllib.parse.urlsplit(BASE_URL).port != expected_ports[FIXTURE_PROJECT]:
        raise SystemExit("Fixture project must match the guarded loopback port.")
    unittest.main(argv=[__file__, *remaining], verbosity=2)
