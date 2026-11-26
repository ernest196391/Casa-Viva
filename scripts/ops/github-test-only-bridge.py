#!/usr/bin/env python3
"""CV-AUTO-002: GitHub intake for read-only test requests. OFF by default.

Reuses the existing private contingency issue, credential, GitHub adapter and
Control OS database adapter. It never invokes a shell or modifies production.
The database RPC is intentionally a required, separately certified contract.
"""
from __future__ import annotations
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import sys
import time
import importlib.util

HERE = Path(__file__).resolve().parent
PREFIX = "CV-TEST-ONLY-V1 "
RESULT_PREFIX = "CV-TEST-ONLY-RECEIPT-V1 "
TERMINAL_PREFIX = "CV-TEST-ONLY-RESULT-V1 "
OWNER_ID = 175450950

def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate key")
        result[key] = value
    return result
OWNER = "ernest196391"
REPO = "ernest196391/casa-viva-centro-control"
ALLOWED_REPOS = {"casa-viva": "ernest196391/Casa-Viva"}
SHA = re.compile(r"^[0-9a-f]{40}$")
NONCE = re.compile(r"^[A-Za-z0-9_-]{16,64}$")
ALLOWED_PROFILES = {"ops-python-contract"}  # no user-selected commands
MAX_REQUEST_SECONDS = 900


def _load(name, filename):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def parse_comment(comment, now):
    """Fail closed on edited, stale, unauthorized, extended or ambiguous input."""
    try:
        user = comment["user"]
        if (user.get("login") != OWNER or user.get("id") != OWNER_ID or user.get("type") != "User"
                or comment.get("author_association") != "OWNER"
                or comment["created_at"] != comment["updated_at"]):
            return None
        cid = comment["id"]
        body = comment["body"]
        if type(cid) is not int or cid <= 0 or type(body) is not str or len(body) > 600 or not body.startswith(PREFIX):
            return None
        obj = json.loads(body[len(PREFIX):], object_pairs_hook=unique_object)
        if type(obj) is not dict or set(obj) != {"nonce", "expires_at", "repo", "sha", "profile"}:
            return None
        if not NONCE.fullmatch(obj["nonce"]) or obj["repo"] not in ALLOWED_REPOS or not SHA.fullmatch(obj["sha"]) or obj["profile"] not in ALLOWED_PROFILES:
            return None
        if not all(isinstance(v,str) and v.endswith("Z") for v in (comment["created_at"],obj["expires_at"])):
            return None
        created = dt.datetime.fromisoformat(comment["created_at"].replace("Z", "+00:00")).timestamp()
        expiry = dt.datetime.fromisoformat(obj["expires_at"].replace("Z", "+00:00")).timestamp()
        if not (created <= now < expiry <= created + MAX_REQUEST_SECONDS):
            return None
        return {"comment_id": cid, "nonce": obj["nonce"], "expires_at": obj["expires_at"],
                "repo": obj["repo"], "sha": obj["sha"], "profile": obj["profile"],
                "fingerprint": hashlib.sha256((str(cid) + ":" + obj["nonce"]).encode()).hexdigest()}
    except (KeyError, TypeError, ValueError, AttributeError, OverflowError):
        return None


def enqueue_request(request, enqueue_rpc):
    """Only the transactional RPC may create a task. Never POST ops_tasks directly.

    The exact RPC signature/DB constraints must be installed and certified on a
    disposable Postgres fixture before this adapter can be enabled on VPS.
    """
    response = enqueue_rpc("rpc/ops_enqueue_github_test_only", {
        "p_comment_id": request["comment_id"],
        "p_nonce": request["nonce"],
        "p_repo": request["repo"],
        "p_sha": request["sha"],
        "p_profile": request["profile"],
        "p_expires_at": request["expires_at"],
        "p_fingerprint": request["fingerprint"],
    })
    if not isinstance(response, dict) or response.get("status") not in ("queued", "duplicate"):
        raise RuntimeError("Control OS did not confirm a transactional receipt")
    task_id = response.get("task_public_id")
    if type(task_id) is not int or task_id <= 0:
        raise RuntimeError("Control OS receipt missing task public id")
    return {"status": response["status"], "task_public_id": task_id, "comment_id": request["comment_id"]}


def receive_once(env=None, now=None, gh=None, queue=None):
    env = os.environ if env is None else env
    now = time.time() if now is None else now
    if env.get("CV_GITHUB_TEST_ONLY_ENABLED") != "1":
        return "disabled"
    existing = _load("cv_auto_contingency", "github_contingency.py")
    cfg = existing.config(env)
    if not cfg or cfg[0] != 3:
        return "blocked"
    gh = gh or existing.github_api
    issue, token = cfg
    root = "/repos/" + REPO
    repository = gh(token, "GET", root)
    if repository.get("private") is not True or repository.get("full_name") != REPO:
        return "blocked"
    target = gh(token, "GET", f"{root}/issues/{issue}")
    if target.get("state") != "open" or target.get("title") != "CV contingency — diagnostic mailbox" or "pull_request" in target:
        return "blocked"
    since = dt.datetime.fromtimestamp(now - 900, dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    comments = gh(token, "GET", f"{root}/issues/{issue}/comments?per_page=100&since={since}")
    if not isinstance(comments, list) or len(comments) >= 100:
        return "blocked"
    valid = [p for comment in comments if (p := parse_comment(comment, now))]
    if not valid:
        return "idle"
    requests = sorted(valid, key=lambda x: x["comment_id"])
    if queue is None:
        worker = _load("cv_auto_dev_worker", "dev-worker.py")
        worker.load_credentials()
        queue = lambda path, payload: worker.api("POST", path, payload)
    # Process every bounded request, including duplicates, so an old valid
    # comment cannot starve later requests. Only terminal reporter posts
    # durable results; repeated polling never floods the mailbox with receipts.
    errors = 0
    for request in requests:
        try:
            enqueue_request(request, queue)
        except Exception:
            errors += 1
    return "blocked" if errors else "queued"


if __name__ == "__main__":
    try:
        print(receive_once())
    except Exception:
        print("blocked")  # redact provider errors and credentials
        sys.exit(1)
