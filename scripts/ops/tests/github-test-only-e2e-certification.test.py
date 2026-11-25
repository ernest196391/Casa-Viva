import importlib.util
from pathlib import Path
import unittest
from unittest.mock import patch
import datetime as dt
import json
s=importlib.util.spec_from_file_location("e2e",Path(__file__).resolve().parents[1]/"github_test_only_e2e_certification.py")
m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
class ScopeTests(unittest.TestCase):
    def fixture(self,rc=0,wrong_id=False,wrong_issue=False):
        now=dt.datetime.now(dt.timezone.utc)
        stamp=lambda n:(now+dt.timedelta(seconds=n)).strftime("%Y-%m-%dT%H:%M:%SZ")
        sha="a"*40
        root="/repos/"+m.rx.REPO
        request=dict(id=8 if wrong_id else 7,user=dict(login=m.rx.OWNER,id=m.rx.OWNER_ID,type="User"),author_association="OWNER",created_at=stamp(-5),updated_at=stamp(-5),issue_url="https://api.github.com"+root+"/issues/"+("4" if wrong_issue else "3"),body=m.rx.PREFIX+json.dumps(dict(nonce="R"*20,expires_at=stamp(300),repo="casa-viva",sha=sha,profile=m.ex.PROFILE)))
        env=dict(CV_GITHUB_TEST_ONLY_ENABLED="1",CV_GITHUB_CONTINGENCY_ENABLED="1",CV_GITHUB_CONTINGENCY_ISSUE="3",CV_GITHUB_CONTINGENCY_TOKEN="fixture")
        rows=[];posts=[]
        def gh(token,verb,path,data=None):
            if path==root:return dict(private=True,full_name=m.rx.REPO)
            if path==root+"/issues/3":return dict(state="open",title="CV contingency — diagnostic mailbox")
            if path==root+"/issues/comments/7":return request
            if verb=="GET":return posts[:]
            item=dict(id=9,body=data["body"],user={"id":m.rx.OWNER_ID},created_at=stamp(0),updated_at=stamp(0));posts.append(item);return item
        def api(verb,path,data=None):
            if path.endswith("ops_enqueue_github_test_only"):
                if rows:return dict(status="duplicate",task_public_id=1)
                rows.append(dict(id="fixture",public_id=1,source_ref="7",payload={"test_only":{"sha":sha}},result=None));return dict(status="queued",task_public_id=1)
            if path.endswith("ops_claim_github_test_only"):
                if not rows or rows[0]["result"] is not None:return None
                return dict(task_id="fixture",run_id=2,sha=sha,profile=m.ex.PROFILE,lease=stamp(120))
            if path.endswith("ops_finish_github_test_only"):
                rows[0]["result"]=dict(outcome=data["p_outcome"],run_id=2);return rows[0]["result"].copy()
            if path.endswith("ops_ack_github_test_only"):
                rows[0]["result"]["github_report_id"]=9;return True
            if verb=="GET":return [r for r in rows if r["result"] and "github_report_id" not in r["result"]]
            self.fail(path)
        result=m.run(api,gh,env,7,sha,lambda s,b:rc,now.timestamp())
        self.assertEqual(len(posts),1)
        self.assertEqual(len(rows),1)
        return result
    def test_success(self):self.assertTrue(self.fixture()["ok"])
    def test_failure_not_success(self):self.assertFalse(self.fixture(rc=125)["ok"])
    def test_wrong_id(self):
        with self.assertRaises(ValueError):self.fixture(wrong_id=True)
    def test_wrong_issue(self):
        with self.assertRaises(ValueError):self.fixture(wrong_issue=True)
    def test_sql_literals(self):
        self.assertEqual(m.FixtureDB.literal("a'b"),"'a''b'")
        with self.assertRaises(ValueError):m.FixtureDB.literal(None)
    def test_live_root_rejected(self):
        with self.assertRaises(ValueError):m.FixtureDB("/","/usr/bin")
if __name__=="__main__":unittest.main()
