import unittest
from guardian_postmerge import certify, CHECKS

SHA = "a" * 40

class GuardianTests(unittest.TestCase):
    def test_cases(self):
        for case in ("success", "pr", "wrong_sha", "pending", "missing", "unprotected", "moved", "rerun"):
            with self.subTest(case=case):
                rows = [dict(workflow_id=w, head_sha=SHA, head_branch="main", event="push",
                    status="completed", conclusion="success", run_number=1, run_attempt=1) for w in CHECKS]
                if case == "pr": rows[0]["event"] = "pull_request"
                if case == "wrong_sha": rows[0]["head_sha"] = "b"*40
                if case == "pending": rows[0]["status"] = "in_progress"
                if case == "missing": rows.pop()
                if case == "rerun": rows.append(dict(rows[0], run_attempt=2, conclusion="failure"))
                calls = []
                def api(token, method, path):
                    if "/actions/" in path:
                        return {"workflow_runs": rows, "total_count": len(rows)}
                    calls.append(path)
                    return {"protected": case != "unprotected", "commit": {"sha": "b"*40 if case == "moved" and len(calls)>1 else SHA}}
                self.assertEqual(certify(api, "dummy", SHA), case == "success")
    def test_invalid_sha(self):
        self.assertFalse(certify(None, None, "main"))

if __name__ == "__main__":
    unittest.main()
