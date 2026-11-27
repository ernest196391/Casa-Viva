"""Publish bounded, exact-main CI evidence; no deployment."""
import json
import os
import re
import urllib.request
from github_release_gate import CHECKS, REPO, verified_checks

def certify(api, token, sha):
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        return False
    path = f"/repos/{REPO}/branches/main"
    def current():
        b = api(token, "GET", path)
        return b.get("protected") is True and b.get("commit", {}).get("sha") == sha
    return current() and verified_checks(api, token, sha) and current()

def api(token, method, path):
    req = urllib.request.Request("https://api.github.com" + path, method=method,
        headers={"Authorization": "Bearer " + token, "Accept": "application/vnd.github+json"})
    with urllib.request.urlopen(req, timeout=20) as response:
        return json.load(response)

if __name__ == "__main__":
    sha = os.environ["TARGET_SHA"]
    ok = certify(api, os.environ["GH_TOKEN"], sha)
    print(json.dumps({"sha": sha, "certified": ok, "workflow_ids": sorted(CHECKS)}))
    raise SystemExit(0 if ok else 1)
