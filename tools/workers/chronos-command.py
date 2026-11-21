#!/usr/bin/env python3
"""Claim and execute one Chronos command-adapter repetition."""

import argparse
import codecs
import hashlib
from http.client import HTTPException
import json
import os
from pathlib import Path
import platform
import re
import secrets
import signal
import subprocess
import sys
import tempfile
from urllib.parse import quote, urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener
from zipfile import ZIP_DEFLATED, ZipFile

from attempt_evidence import collect_driver_evidence, open_directory


ADAPTER = "chronos-command@1"
PROTOCOL = "attempt-v1"
MAX_OUTPUT = 1024 * 1024
CLEANUP_TIMEOUT = 90


class AgentError(RuntimeError):
    pass


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Agent:
    def __init__(self, server, token):
        url = urlsplit(server)
        if not url.hostname or url.username is not None or url.query or url.fragment:
            raise AgentError("Provide a Chronos base URL without credentials, query or fragment.")
        loopback = re.fullmatch(r"(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]+)?", url.netloc, re.IGNORECASE)
        if url.scheme != "https" and not (url.scheme == "http" and loopback):
            raise AgentError("Use HTTPS for Chronos, or HTTP on localhost, 127.0.0.1 or [::1].")
        self.server = server.rstrip("/")
        self.token = token
        self.opener = build_opener(NoRedirect(), ProxyHandler({})) if loopback else build_opener(NoRedirect())

    def request(self, method, job_id, action=None, fields=None, payload=None, content_type=None, **options):
        fields = None if fields is None else urlencode(fields).encode()
        if fields is not None:
            payload = fields
            content_type = "application/x-www-form-urlencoded"
        route = {"id": job_id, "protocol": PROTOCOL, **options}
        if action is not None:
            route["action"] = action
        path = "/".join(f"{key}={quote(str(value), safe='')}" for key, value in route.items())
        headers = {"X-Chronos-Attempt-Token": self.token}
        if content_type:
            headers["Content-Type"] = content_type
        request = Request(f"{self.server}/api/v1/job/{path}", payload, headers, method=method)
        try:
            with self.opener.open(request, timeout=30) as response:
                data = json.loads(response.read(4 * MAX_OUTPUT))
        except HTTPException as error:
            raise AgentError(f"Incomplete or invalid Chronos HTTP response: {error}") from error
        status = data.get("status") if isinstance(data, dict) else None
        if not isinstance(status, dict) or not isinstance(status.get("code"), int):
            raise AgentError("Invalid Chronos response envelope.")
        if status["code"] == 601:
            return None
        if status["code"] != 200:
            raise AgentError(status.get("message") or f"Chronos status {status['code']}.")
        return data.get("response", {})

    def upload(self, job_id, archive):
        boundary = secrets.token_hex(32)
        head = (
            f"--{boundary}\r\nContent-Disposition: form-data; name=\"result\"; "
            "filename=\"result.zip\"\r\nContent-Type: application/zip\r\n\r\n"
        )
        body = head.encode() + archive.read() + f"\r\n--{boundary}--\r\n".encode()
        self.request(
            "POST", job_id, "upload", payload=body,
            content_type=f"multipart/form-data; boundary={boundary}",
        )


def stage_artifact(entry, cache, directory):
    role = entry["role"]
    digest = entry["sha256"]
    size = entry["sizeBytes"]
    if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_.-]{0,99}", role) or not re.fullmatch(r"[a-f0-9]{64}", digest):
        raise AgentError("Invalid artifact identity.")
    source = cache / digest
    if not source.is_file() or source.is_symlink() or source.stat().st_size != size:
        raise AgentError(f"Missing artifact or incorrect size for role {role}.")
    target = directory / role
    sha256 = hashlib.sha256()
    remaining = size
    with source.open("rb") as incoming, target.open("xb") as staged:
        while remaining:
            chunk = incoming.read(min(remaining, MAX_OUTPUT))
            if not chunk:
                raise AgentError(f"Incomplete artifact for role {role}.")
            staged.write(chunk)
            sha256.update(chunk)
            remaining -= len(chunk)
        if incoming.read(1) or sha256.hexdigest() != digest:
            raise AgentError(f"SHA-256 mismatch for artifact role {role}.")
    target.chmod(0o500 if role == "driver" else 0o400)
    return target


def execute(agent, job, cache, timeout):
    job_id = job["id"]
    spec = job["specification"]
    if spec["adapter"] != ADAPTER or spec["schemaVersion"] != 1 or job["status"] != 1:
        raise AgentError("This worker requires a claimed command-adapter attempt awaiting its start.")
    observed = {"hostname": platform.node(), "os": platform.platform(), "architecture": platform.machine(),
                "python": platform.python_version()}
    verified = {}
    with tempfile.TemporaryDirectory(prefix="chronos-command-") as temporary:
        workdir = Path(temporary)
        artifacts = workdir / "artifacts"
        artifacts.mkdir()
        files = {}
        try:
            agent.request("PATCH", job_id, fields={"environment": json.dumps(observed)})
            for entry in spec["artifacts"]["artifacts"]:
                role = entry["role"]
                files[role] = str(stage_artifact(entry, cache, artifacts))
                verified[role] = {"sha256": entry["sha256"], "sizeBytes": entry["sizeBytes"]}
        except (AgentError, OSError) as error:
            agent.request("PATCH", job_id, fields={"status": -2, "reason": str(error), "artifacts": json.dumps(verified)})
            raise
        # A start acknowledgement authorizes exactly one driver invocation. An
        # ambiguous or refused start is left for the operator to inspect.
        agent.request("PATCH", job_id, fields={"status": 2, "artifacts": json.dumps(verified)})
        run_driver(agent, job_id, spec, job["attemptId"], workdir, files, timeout)


def driver_exit_code(executable, stdin, stdout, stderr, directory, timeout):
    with subprocess.Popen(
        [executable], stdin=stdin, stdout=stdout, stderr=stderr,
        cwd=directory, env={"PATH": os.defpath}, start_new_session=True,
    ) as process:
        try:
            try:
                return process.wait(timeout=timeout)
            except subprocess.TimeoutExpired:
                # Let the driver clean up external resources before stopping its group.
                process.terminate()
                try:
                    process.wait(timeout=CLEANUP_TIMEOUT)
                except subprocess.TimeoutExpired:
                    pass
                raise AgentError(f"Driver exceeded its {timeout}-second timeout.") from None
        except BaseException:
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.wait()
            raise


def append_stderr(agent, job_id, stream):
    stream.seek(0)
    decoder = codecs.getincrementaldecoder("utf-8")(errors="replace")
    remaining = MAX_OUTPUT
    while remaining:
        chunk = stream.read(min(64 * 1024, remaining))
        if not chunk:
            break
        remaining -= len(chunk)
        text = decoder.decode(chunk)
        if text:
            agent.request("POST", job_id, "appendLog", fields={"log": text})
    tail = decoder.decode(b"", final=True)
    if tail:
        agent.request("POST", job_id, "appendLog", fields={"log": tail})


def run_driver(agent, job_id, spec, attempt_id, workdir, files, timeout):
    driver_input = json.dumps({
        "repetitionId": job_id, "attemptId": attempt_id, "specification": spec, "artifactPaths": files,
    }).encode()
    driver_dir = workdir / "run"
    driver_dir.mkdir()
    errors = []
    result = None
    with open_directory(driver_dir) as directory, tempfile.TemporaryFile() as stdout, tempfile.TemporaryFile() as stderr:
        try:
            with tempfile.TemporaryFile() as stdin:
                stdin.write(driver_input)
                stdin.seek(0)
                exit_code = driver_exit_code(files["driver"], stdin, stdout, stderr, driver_dir, timeout)
            if exit_code != 0:
                raise AgentError(f"Driver exited with status {exit_code}.")
            if os.fstat(stdout.fileno()).st_size > MAX_OUTPUT or os.fstat(stderr.fileno()).st_size > MAX_OUTPUT:
                raise AgentError("Driver output exceeds the 1 MiB evidence limit.")
            stdout.seek(0)
            result = json.loads(stdout.read(MAX_OUTPUT))
            if not isinstance(result, dict):
                raise AgentError("Driver stdout must contain a JSON result object.")
        except (AgentError, OSError, ValueError) as error:
            errors.append(str(error))

        try:
            append_stderr(agent, job_id, stderr)
        except (AgentError, OSError, ValueError) as error:
            errors.append(f"Log upload failed: {error}")
        try:
            with tempfile.TemporaryFile() as archive:
                with ZipFile(archive, "w", compression=ZIP_DEFLATED) as bundle:
                    for name, stream in [("stdout.json", stdout), ("stderr.log", stderr)]:
                        stream.seek(0)
                        bundle.writestr(name, stream.read(MAX_OUTPUT))
                    collection_error = collect_driver_evidence(bundle, directory)
                    if collection_error:
                        errors.append(collection_error)
                archive.seek(0)
                agent.upload(job_id, archive)
        except (AgentError, OSError, ValueError) as error:
            errors.append(f"Archive upload failed: {error}")
    try:
        if not errors:
            agent.request("PATCH", job_id, fields={"status": 3, "result": json.dumps(result, allow_nan=False)})
            return
    except (AgentError, OSError, ValueError) as error:
        # A completion response can be lost after the server commits.
        if agent.request("GET", job_id)["status"] == 3:
            return
        errors.append(str(error))
    reason = " ".join(errors)
    agent.request("PATCH", job_id, fields={"status": -2, "reason": reason[:3000]})
    raise AgentError(reason)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("server")
    parser.add_argument("--worker", default=platform.node())
    parser.add_argument("--environment", default="default", help="agent environment name (default: default)")
    parser.add_argument("--supports", default="demoall")
    parser.add_argument("--cache", type=Path, required=True)
    parser.add_argument("--timeout", type=int, default=900)
    parser.add_argument("--job", type=int, help="Run a repetition already claimed by a CEM bootstrapper.")
    args = parser.parse_args()
    if args.timeout < 1:
        parser.error("--timeout must be positive")
    try:
        token = os.environ.get("CHRONOS_ATTEMPT_TOKEN", "") if args.job else secrets.token_hex(32)
        if not re.fullmatch(r"[a-f0-9]{64}", token):
            raise AgentError("For --job, provide the CEM claim token in CHRONOS_ATTEMPT_TOKEN.")
        agent = Agent(args.server, token)
        if args.job:
            job = agent.request("GET", args.job)
        else:
            options = dict(environment=args.environment, adapter=ADAPTER, worker=args.worker, supports=args.supports)
            try:
                job = agent.request("GET", "next", **options)
            except OSError:
                job = agent.request("GET", "next", **options)
        if job is None:
            print("No compatible repetition in queue.")
            return 0
        execute(agent, job, args.cache, args.timeout)
        print(f"Completed repetition {job['id']}, attempt {job['attemptId']}.")
        return 0
    except (AgentError, OSError, ValueError, KeyError) as error:
        print(str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
