#!/usr/bin/env python3
import datetime as dt
import importlib.util
from pathlib import Path
import unittest

path = Path(__file__).resolve().parents[1] / "github-test-only-bridge.py"
spec = importlib.util.spec_from_file_location("github_test_only", path)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
NOW = 1795639200.0


def stamp(sec):
    return dt.datetime.fromtimestamp(sec, dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def sample():
    import json
    return dict(id=99, user={"login":"ernest196391","type":"User"},
      author_association="OWNER", created_at=stamp(NOW - 30),
      updated_at=stamp(NOW - 30),
      body=m.PREFIX + json.dumps({"nonce":"A" * 20, "expires_at":stamp(NOW + 300),
      "repo":"casa-viva","sha":"a"*40,"profile":"ops-python-contract"}))


class TestAdmission(unittest.TestCase):
    def test_valid(self):
        self.assertEqual(m.parse_comment(sample(), NOW)["comment_id"], 99)

    def test_reject_attacker(self):
        for field, val in [("user",{"login":"intruder","type":"User"}),
                           ("author_association","CONTRIBUTOR"),
                           ("updated_at",stamp(NOW - 1)), ("id",True)]:
            row=sample();row[field]=val
            self.assertIsNone(m.parse_comment(row,NOW),field)

    def test_reject_expiry(self):
        row=sample()
        self.assertIsNone(m.parse_comment(row,NOW+1000))
        row["body"]=row["body"].replace(stamp(NOW+300),stamp(NOW+10000))
        self.assertIsNone(m.parse_comment(row,NOW))

    def test_reject_unknown_fields(self):
        row=sample(); row["body"]=row["body"][:-1]+',"command":"rm -rf /"}'
        self.assertIsNone(m.parse_comment(row,NOW))

    def test_reject_bad_sha_and_profile(self):
        row=sample();row["body"]=row["body"].replace("a"*40,"main")
        self.assertIsNone(m.parse_comment(row,NOW))
        row=sample();row["body"]=row["body"].replace("ops-python-contract","arbitrary-shell")
        self.assertIsNone(m.parse_comment(row,NOW))

    def test_queue_contract(self):
        seen=[]
        def stub(path, payload):
            seen.append((path,payload))
            return {"status":"queued","task_public_id":91}
        receipt=m.enqueue_request(m.parse_comment(sample(),NOW),stub)
        self.assertEqual(receipt["task_public_id"],91)
        self.assertEqual(seen[0][0],"rpc/ops_enqueue_github_test_only")
        self.assertFalse(any("command" in x for x in seen[0][1]))

    def test_queue_failure_closed(self):
        with self.assertRaises(RuntimeError):
            m.enqueue_request(m.parse_comment(sample(),NOW),lambda *_: [])

    def test_disabled_no_network(self):
        self.assertEqual(m.receive_once(env={},now=NOW,gh=lambda *_: self.fail("network"),queue=lambda *_: self.fail("queue")),"disabled")

    def test_wrong_issue_no_network(self):
        env={"CV_GITHUB_TEST_ONLY_ENABLED":"1","CV_GITHUB_CONTINGENCY_ENABLED":"1","CV_GITHUB_CONTINGENCY_ISSUE":"1","CV_GITHUB_CONTINGENCY_TOKEN":"fixture"}
        self.assertEqual(m.receive_once(env=env,now=NOW,gh=lambda *_: self.fail("network")),"blocked")

    def test_receiver_mocked(self):
        env={"CV_GITHUB_TEST_ONLY_ENABLED":"1","CV_GITHUB_CONTINGENCY_ENABLED":"1","CV_GITHUB_CONTINGENCY_ISSUE":"3","CV_GITHUB_CONTINGENCY_TOKEN":"fixture"}
        calls=[]
        def gh(token,verb,path,body=None):
            calls.append((verb,path))
            if path == "/repos/"+m.REPO:return {"private":True,"full_name":m.REPO}
            if path.endswith("/issues/3"):return {"state":"open","title":"CV contingency — diagnostic mailbox"}
            if verb=="GET":return [sample()]
            return {}
        self.assertEqual(m.receive_once(env,NOW,gh,lambda *_:{"status":"duplicate","task_public_id":91}),"reported")
        self.assertEqual(calls[-1][0],"POST")


if __name__ == "__main__":
    unittest.main()
