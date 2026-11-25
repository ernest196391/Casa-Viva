#!/usr/bin/env python3
"""One-shot isolated integration coordinator; no production DB or live worker.
The operator supplies an existing private mailbox token through environment.
Only one explicitly selected owner request is accepted. No polling daemon.
"""
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

HERE=Path(__file__).resolve().parent
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,HERE/path)
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);return mod
rx=load("intake","github-test-only-bridge.py")
ex=load("executor","github_test_only_executor.py")
rp=load("reporter","github_test_only_reporter.py")
ghmod=load("transport","github_contingency.py")
cert=load("cert","github_test_only_launcher_certification.py")

def run(api,gh,env,comment_id,sha,launch,now=None):
    if type(comment_id) is not int or comment_id<=0 or not re.fullmatch("[0-9a-f]{40}",sha):
        raise ValueError("invalid fixture identity")
    now=time.time() if now is None else now
    token=env["CV_GITHUB_CONTINGENCY_TOKEN"]
    root="/repos/"+rx.REPO
    path=root+"/issues/3/comments"
    # Scope all writes to the one selected request; never drain the real mailbox.
    def scoped(t,verb,p,body=None):
        if t!=token:raise ValueError("token mismatch")
        if verb=="GET" and p.startswith(path+"?") and "&since=" in p:
            comment=gh(t,"GET",root+"/issues/comments/"+str(comment_id))
            request=rx.parse_comment(comment,now)
            if not request or request["comment_id"]!=comment_id or request["sha"]!=sha or comment.get("issue_url")!="https://api.github.com"+root+"/issues/3":
                raise ValueError("request mismatch")
            return [comment]
        if verb=="POST":
            if p!=path or not isinstance(body,dict) or set(body)!={"body"}:raise ValueError("write outside fixture")
            raw=body["body"]
            if not raw.startswith(rp.PREFIX):raise ValueError("invalid result")
            result=json.loads(raw[len(rp.PREFIX):])
            if result.get("comment_id")!=comment_id or result.get("sha")!=sha:raise ValueError("result mismatch")
        return gh(t,verb,p,body) if body is not None else gh(t,verb,p)
    for _ in range(2):
        if rx.receive_once(env,now,scoped,lambda p,d:api("POST",p,d))!="queued":raise ValueError("intake blocked")
    calls=[]
    outcomes=[]
    def execute(s,b):
        if s!=sha or calls:raise ValueError("unexpected claim")
        calls.append(s)
        rc=launch(s,b)
        outcomes.append(rc)
        return rc
    if not ex.worker_once(api,execute):raise ValueError("missing claim")
    if ex.worker_once(api,execute):raise ValueError("second claim")
    if not rp.deliver(api,scoped,token,rx.OWNER_ID):raise ValueError("missing acknowledgement")
    if rp.deliver(api,scoped,token,rx.OWNER_ID):raise ValueError("duplicate acknowledgement")
    return {"ok":outcomes==[0],"scope":"isolated_e2e","requests":1,"launches":len(calls),"acknowledged":True,"production_authorized":False}

# A private, temporary PostgreSQL cluster, never an existing server.
class FixtureDB:
    RPC={
        "ops_enqueue_github_test_only":("p_comment_id","p_nonce","p_repo","p_sha","p_profile","p_expires_at","p_fingerprint"),
        "ops_claim_github_test_only":("p_worker",),
        "ops_finish_github_test_only":("p_task_id","p_run_id","p_worker","p_outcome"),
        "ops_ack_github_test_only":("p_task_id","p_comment_id")}
    def __init__(self,root,bindir):
        self.root=Path(root).resolve(strict=True)
        self.bin=Path(bindir).resolve(strict=True)
        if not self.root.is_relative_to("/tmp") or self.root.stat().st_uid!=0 or self.root.stat().st_mode & 0o077:
            raise ValueError("private root required")
        if not re.fullmatch(r"/usr/lib/postgresql/[0-9]+/bin",str(self.bin)):
            raise ValueError("invalid postgres binaries")
        self.data=self.root/"pgdata"
        self.sock=self.root/"socket"
        self.started=False
    def pg(self,args,**kw):
        return subprocess.run(["/usr/sbin/runuser","-u","postgres","--",*map(str,args)],env={"PATH":"/usr/bin:/bin","HOME":"/nonexistent","LANG":"C"},check=True,capture_output=True,timeout=30,**kw)
    def __enter__(self):
        import pwd
        user=pwd.getpwnam("postgres")
        # Parent stays root-owned and non-writable; traversal only.
        self.root.chmod(0o711)
        for p in (self.data,self.sock):
            p.mkdir(mode=0o700)
            os.chown(p,user.pw_uid,user.pw_gid)
        try:
            self.pg([self.bin/"initdb","-D",self.data,"--auth-local=peer","--auth-host=reject","--no-locale"])
            self.pg([self.bin/"pg_ctl","-D",self.data,"-l",self.data/"server.log","-o",f"-k {self.sock} -c listen_addresses='' -c shared_buffers=16MB -c max_connections=5","-w","start"])
            self.started=True
            self.sql("create role anon; create role authenticated; create role service_role bypassrls;")
            for file in ("github-test-only-e2e.fixture.sql",):
                raw=(HERE/"sql"/file).read_text()
                for migration in ("github-test-only-enqueue.sql","github-test-only-lifecycle.sql","github-test-only-report.sql"):
                    raw=raw.replace("\\ir "+migration,(HERE/"sql"/migration).read_text())
                self.sql(raw)
            return self
        except BaseException:
            self.__exit__(None,None,None)
            raise
    def __exit__(self,*_):
        if self.started or (self.data/"postmaster.pid").exists():
            self.pg([self.bin/"pg_ctl","-D",self.data,"-m","fast","-w","stop"])
        self.started=False
    def sql(self,query):
        return self.pg([self.bin/"psql","-X","-qAt","-v","ON_ERROR_STOP=1","-h",self.sock,"-d","postgres"],input=query,text=True).stdout.strip()
    @staticmethod
    def literal(v):
        if type(v) is int:return str(v)
        if not isinstance(v,str):raise ValueError("invalid argument")
        return "'"+v.replace("'","''")+"'"
    def api(self,verb,path,data=None):
        if verb=="GET":
            if path!="ops_tasks?source=eq.github-test-only&status=in.(done,failed)&result->>github_report_id=is.null&select=id,public_id,source_ref,payload,result&order=public_id.asc&limit=1":raise ValueError("unknown query")
            return json.loads(self.sql("select coalesce(jsonb_agg(q),'[]'::jsonb) from (select id,public_id,source_ref,payload,result from public.ops_tasks where source='github-test-only' and status in ('done','failed') and result->>'github_report_id' is null order by public_id limit 1) q;"))
        if verb!="POST":raise ValueError("unknown method")
        name=path.removeprefix("rpc/")
        args=self.RPC[name]
        if set(args)!=set(data):raise ValueError("invalid arguments")
        value=self.sql("select to_jsonb(public."+name+"("+",".join(self.literal(data[k]) for k in args)+"));")
        return json.loads(value) if value else None

def main():
    import tempfile
    if os.geteuid()!=0 or len(sys.argv)!=6 or sys.argv[1]!="--certify":raise ValueError("operator invocation required")
    repo=Path(sys.argv[2]).resolve(strict=True)
    sha=sys.argv[3]
    cid=int(sys.argv[4])
    if not repo.is_relative_to("/tmp") or not re.fullmatch("[0-9a-f]{40}",sha):raise ValueError("invalid fixture")
    token=os.environ.pop("CV_GITHUB_CONTINGENCY_TOKEN")
    env={"CV_GITHUB_CONTINGENCY_TOKEN":token,"CV_GITHUB_CONTINGENCY_ENABLED":"1","CV_GITHUB_CONTINGENCY_ISSUE":"3","CV_GITHUB_TEST_ONLY_ENABLED":"1"}
    # No token in child environment; cert.run itself starts a clean unit.
    with tempfile.TemporaryDirectory(prefix="cv-e2e-pg-",dir="/tmp") as tmp:
        with FixtureDB(tmp,sys.argv[5]) as db:
            def launch(s,b):
                # Do not exceed the lease budget with an unbounded outer invocation.
                if b<85:return 125
                report=cert.run(repo,s)
                return 0 if report.get("ok") is True and report.get("cleanup_confirmed") is True else 125
            result=run(db.api,ghmod.github_api,env,cid,sha,launch)
            result["durable_rows"]=int(db.sql("select count(*) from public.ops_tasks where result->>'github_report_id' is not null"))
            result["ok"]=result["ok"] and result["durable_rows"]==1
    return result

if __name__=="__main__":
    try: report=main()
    except Exception:report={"ok":False,"reason":"integration_failed","production_authorized":False}
    print(json.dumps(report,sort_keys=True))
    sys.exit(0 if report["ok"] else 4)
