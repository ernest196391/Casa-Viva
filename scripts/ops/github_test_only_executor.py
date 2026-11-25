"""Fixed test-only profile. No fetch, checkout, commit, push or deployment.
Only locally available Git objects are eligible. Never execute candidate code
in the privileged worker. A dedicated, certified launcher is required.
"""
import datetime as dt
import re
import time

PROFILE = "ops-python-contract"
SHA = re.compile(r"[0-9a-f]{40}")
TESTS = ("scripts/ops/tests/github-test-only-bridge.test.py",)
WORKER = "cv-dev-worker-v1"

def execute_claim(claim, launch, now=None):
    now = time.time() if now is None else now
    try:
        if not isinstance(claim, dict) or claim.get("profile") != PROFILE:
            return "blocked"
        sha = claim.get("sha")
        if not isinstance(sha, str) or not SHA.fullmatch(sha):
            return "blocked"
        lease = dt.datetime.fromisoformat(claim["lease"].replace("Z","+00:00"))
        if lease.tzinfo is None:
            return "blocked"
        budget = min(90, int(lease.timestamp() - now) - 10)
        if budget < 5:
            return "blocked"
        # The trusted launcher owns exact object extraction, cgroups and
        # containment; no request-provided path, command, environment or flags.
        rc = launch(sha, budget)
        if type(rc) is not int:
            return "blocked"
        return {0:"passed",124:"timeout",125:"blocked"}.get(rc,"failed")
    except Exception:
        return "blocked"

def worker_once(api, launch):
    claim = api("POST","rpc/ops_claim_github_test_only",{"p_worker":WORKER})
    if claim is None:
        return False
    if not isinstance(claim,dict) or type(claim.get("run_id")) is not int or not isinstance(claim.get("task_id"),str):
        raise RuntimeError("invalid claim receipt")
    outcome = execute_claim(claim,launch)
    receipt = api("POST","rpc/ops_finish_github_test_only",{
        "p_task_id":claim["task_id"],"p_run_id":claim["run_id"],
        "p_worker":WORKER,"p_outcome":outcome})
    if not isinstance(receipt,dict) or receipt.get("outcome") != outcome or receipt.get("run_id") != claim["run_id"]:
        raise RuntimeError("invalid terminal receipt")
    return True
