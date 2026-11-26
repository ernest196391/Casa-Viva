"""Offline cross-component contract: real receiver, executor and reporter.
Transport and database are fixtures; no production credentials or network.
"""
import datetime as dt
import importlib.util
import json
from pathlib import Path
import unittest
P=Path(__file__).resolve().parents[1]
def load(name,file):
    s=importlib.util.spec_from_file_location(name,P/file)
    m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
rx=load("rx","github-test-only-bridge.py")
ex=load("ex","github_test_only_executor.py")
rp=load("rp","github_test_only_reporter.py")
class Flow(unittest.TestCase):
    def test_full_flow_and_restart(self):
        now=dt.datetime.now(dt.timezone.utc)
        stamp=lambda n:(now+dt.timedelta(seconds=n)).strftime("%Y-%m-%dT%H:%M:%SZ")
        sha="a"*40
        req=dict(id=71,user=dict(login=rx.OWNER,id=rx.OWNER_ID,type="User"),author_association="OWNER",created_at=stamp(-5),updated_at=stamp(-5),body=rx.PREFIX+json.dumps(dict(nonce="z"*20,expires_at=stamp(300),repo="casa-viva",sha=sha,profile=ex.PROFILE)))
        env=dict(CV_GITHUB_TEST_ONLY_ENABLED="1",CV_GITHUB_CONTINGENCY_ENABLED="1",CV_GITHUB_CONTINGENCY_ISSUE="3",CV_GITHUB_CONTINGENCY_TOKEN="fixture")
        rows=[]; comments=[req]; launches=[]
        def api(verb,path,data=None):
            if path.endswith("ops_enqueue_github_test_only"):
                if rows:return dict(status="duplicate",task_public_id=1)
                rows.append(dict(id="fixture",public_id=1,source_ref=str(data["p_comment_id"]),payload={"test_only":{"sha":data["p_sha"]}},result=None))
                return dict(status="queued",task_public_id=1)
            if path.endswith("ops_claim_github_test_only"):
                if not rows or rows[0]["result"] is not None:return None
                return dict(task_id="fixture",run_id=2,sha=sha,profile=ex.PROFILE,lease=stamp(120))
            if path.endswith("ops_finish_github_test_only"):
                self.assertEqual(data["p_run_id"],2)
                rows[0]["result"]=dict(outcome=data["p_outcome"],run_id=2)
                return rows[0]["result"].copy()
            if path.endswith("ops_ack_github_test_only"):
                rows[0]["result"]["github_report_id"]=data["p_comment_id"];return True
            if verb=="GET":return [r for r in rows if r["result"] and "github_report_id" not in r["result"]]
            self.fail(path)
        def gh(token,verb,path,data=None):
            self.assertEqual(token,"fixture")
            if path=="/repos/"+rx.REPO:return dict(private=True,full_name=rx.REPO)
            if path.endswith("/issues/3"):return dict(state="open",title="CV contingency — diagnostic mailbox")
            if verb=="GET":return comments[:]
            comments.append(dict(id=72,body=data["body"],user={"id":rx.OWNER_ID},created_at=stamp(0),updated_at=stamp(0)))
            raise TimeoutError("simulated lost reply")
        queue=lambda path,data:api("POST",path,data)
        self.assertEqual(rx.receive_once(env,now.timestamp(),gh,queue),"queued")
        self.assertEqual(rx.receive_once(env,now.timestamp(),gh,queue),"queued")
        self.assertEqual(len(rows),1)
        self.assertTrue(ex.worker_once(api,lambda s,b:launches.append(s) or 0))
        self.assertFalse(ex.worker_once(api,lambda *_:self.fail("reexecution")))
        with self.assertRaises(TimeoutError):rp.deliver(api,gh,"fixture",rx.OWNER_ID)
        self.assertTrue(rp.deliver(api,gh,"fixture",rx.OWNER_ID))
        self.assertFalse(rp.deliver(api,gh,"fixture",rx.OWNER_ID))
        self.assertEqual(launches,[sha])
        self.assertEqual(len(comments),2)
        self.assertEqual(rows[0]["result"]["github_report_id"],72)
        self.assertEqual(json.loads(comments[1]["body"][len(rp.PREFIX):]),dict(comment_id=71,task_public_id=1,sha=sha,outcome="passed"))
if __name__=="__main__":unittest.main()
