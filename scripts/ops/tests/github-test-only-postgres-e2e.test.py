"""Disposable CI PostgreSQL integration. No live credentials or network mailbox."""
import datetime as dt
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import unittest

P=Path(__file__).resolve().parents[1]
def load(name,file):
    s=importlib.util.spec_from_file_location(name,P/file)
    m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m
rx=load("rx","github-test-only-bridge.py")
ex=load("ex","github_test_only_executor.py")
rp=load("rp","github_test_only_reporter.py")
ENV=dict(PATH="/usr/bin:/bin",PGHOST="localhost",PGPORT="5432",PGUSER="postgres",PGPASSWORD="control_os_fixture",PGDATABASE="control_os_fixture")
def sql(query):
    p=subprocess.run(["psql","-X","-qAt","-v","ON_ERROR_STOP=1"],input=query,text=True,capture_output=True,env=ENV,timeout=10,check=True)
    return p.stdout.strip()
def literal(v):
    if type(v) is int:return str(v)
    if not isinstance(v,str):raise ValueError("fixture argument")
    return "'"+v.replace("'","''")+"'"
RPC={
 "ops_enqueue_github_test_only":("p_comment_id","p_nonce","p_repo","p_sha","p_profile","p_expires_at","p_fingerprint"),
 "ops_claim_github_test_only":("p_worker",),
 "ops_finish_github_test_only":("p_task_id","p_run_id","p_worker","p_outcome"),
 "ops_ack_github_test_only":("p_task_id","p_comment_id")}
def api(verb,path,data=None):
    if verb=="GET":
        return json.loads(sql("select coalesce(jsonb_agg(q),'[]'::jsonb) from (select id,public_id,source_ref,payload,result from public.ops_tasks where source='github-test-only' and status in ('done','failed') and result->>'github_report_id' is null order by public_id limit 1) q;"))
    name=path.removeprefix("rpc/")
    args=RPC[name]
    assert set(args)==set(data)
    val=sql("select to_jsonb(public."+name+"("+",".join(literal(data[k]) for k in args)+"));")
    return json.loads(val) if val else None
class RealDB(unittest.TestCase):
    def test_round_trip(self):
        now=dt.datetime.now(dt.timezone.utc)
        stamp=lambda n:(now+dt.timedelta(seconds=n)).strftime("%Y-%m-%dT%H:%M:%SZ")
        sha="a"*40
        request=dict(id=711,user=dict(login=rx.OWNER,id=rx.OWNER_ID,type="User"),author_association="OWNER",created_at=stamp(-5),updated_at=stamp(-5),body=rx.PREFIX+json.dumps(dict(nonce="R"*20,expires_at=stamp(300),repo="casa-viva",sha=sha,profile=ex.PROFILE)))
        env=dict(CV_GITHUB_TEST_ONLY_ENABLED="1",CV_GITHUB_CONTINGENCY_ENABLED="1",CV_GITHUB_CONTINGENCY_ISSUE="3",CV_GITHUB_CONTINGENCY_TOKEN="fixture")
        comments=[request];runs=[]
        def gh(token,verb,path,data=None):
            self.assertEqual(token,"fixture")
            if path=="/repos/"+rx.REPO:return dict(private=True,full_name=rx.REPO)
            if path.endswith("/issues/3"):return dict(state="open",title="CV contingency — diagnostic mailbox")
            if verb=="GET":return comments[:]
            comments.append(dict(id=712,body=data["body"],user={"id":rx.OWNER_ID},created_at=stamp(0),updated_at=stamp(0)))
            raise TimeoutError("lost response")
        for _ in range(2):self.assertEqual(rx.receive_once(env,now.timestamp(),gh,lambda p,d:api("POST",p,d)),"queued")
        self.assertTrue(ex.worker_once(api,lambda s,b:runs.append(s) or 0))
        self.assertFalse(ex.worker_once(api,lambda *_:self.fail("duplicate execution")))
        with self.assertRaises(TimeoutError):rp.deliver(api,gh,"fixture",rx.OWNER_ID)
        self.assertTrue(rp.deliver(api,gh,"fixture",rx.OWNER_ID))
        self.assertFalse(rp.deliver(api,gh,"fixture",rx.OWNER_ID))
        self.assertEqual(runs,[sha])
        self.assertEqual(len(comments),2)
        self.assertEqual(sql("select count(*) from public.ops_tasks where source_ref='711' and status='done' and result->>'github_report_id'='712'"),"1")
        self.assertEqual(sql("select count(*) from public.ops_dev_runs"),"1")
        self.assertEqual(sql("select count(*) from public.ops_events"),"4")
if __name__=="__main__":
    if os.environ.get("GITHUB_ACTIONS")!="true" or os.environ.get("PGDATABASE")!="control_os_fixture":
        raise SystemExit("disposable CI database required")
    unittest.main()
