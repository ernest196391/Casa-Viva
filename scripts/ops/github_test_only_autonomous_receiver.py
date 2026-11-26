#!/usr/bin/env python3
"""Bounded unattended *fixture* receiver. No production DB, no deployment.
Only a single explicitly armed comment and immutable reviewed revision.
No remote executable fetch. A reserved attempt is never replayed after a crash.
"""
import fcntl
import json
import os
from pathlib import Path
import re
import stat
import time
import sys
import importlib.util

HERE=Path(__file__).resolve().parent
def load():
    s=importlib.util.spec_from_file_location("integral",HERE/"github_test_only_e2e_certification.py")
    m=importlib.util.module_from_spec(s);s.loader.exec_module(m);return m

def private(path):
    p=Path(path).absolute()
    if not p.is_relative_to("/tmp") or p==Path("/tmp") or p.resolve()!=p:
        raise ValueError("private fixture directory required")
    st=p.stat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid!=os.geteuid() or st.st_mode&0o077:
        raise ValueError("unsafe directory")
    return p

def atomic(path,body):
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,"w") as f:
        json.dump(body,f,sort_keys=True);f.flush();os.fsync(f.fileno())
    fd=os.open(path.parent,os.O_RDONLY|os.O_DIRECTORY)
    try:os.fsync(fd)
    finally:os.close(fd)

def tick(directory,arm,execute,now=None):
    root=private(directory)
    now=time.time() if now is None else now
    if set(arm)!={"comment_id","sha","armed_at","expires_at"}:
        raise ValueError("invalid arm")
    cid=arm["comment_id"];sha=arm["sha"];start=arm["armed_at"];end=arm["expires_at"]
    if type(cid) is not int or cid<=0 or not isinstance(sha,str) or not re.fullmatch("[0-9a-f]{40}",sha):
        raise ValueError("invalid identity")
    if type(start) is not int or type(end) is not int or not start<end<=start+900:
        raise ValueError("invalid lifetime")
    if not start<=now<end:return {"state":"expired","production_authorized":False}
    fd=os.open(root/"receiver.lock",os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
    try:
        try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:return {"state":"busy","production_authorized":False}
        # One attempt per armed directory, even if the caller changes the ID.
        if (root/"attempt.json").exists():return {"state":"already_reserved","production_authorized":False}
        if end-now<240:return {"state":"insufficient_time","production_authorized":False}
        atomic(root/"attempt.json",{"comment_id":cid,"sha":sha,"state":"reserved"})
        try:
            report=execute(cid,sha)
            ok=type(report) is dict and report.get("ok") is True and report.get("production_authorized") is False
        except Exception:
            ok=False
        result={"state":"passed" if ok else "blocked","comment_id":cid,"sha":sha,"production_authorized":False}
        if ok:result["durable_rows"]=report.get("durable_rows",0)
        atomic(root/"terminal.json",result)
        return result
    finally:os.close(fd)

def execute_integral(repo, image, token, cid, sha):
    m=load()
    env={"CV_GITHUB_CONTINGENCY_TOKEN":token,"CV_GITHUB_CONTINGENCY_ENABLED":"1",
         "CV_GITHUB_CONTINGENCY_ISSUE":"3","CV_GITHUB_TEST_ONLY_ENABLED":"1"}
    with m.DockerFixtureDB(image) as db:
        def launch(s,b):
            if b<45:return 125
            result=m.cert.run(repo,s,budget=b)
            return 0 if result.get("ok") is True and result.get("cleanup_confirmed") is True else 125
        result=m.run(db.api,m.ghmod.github_api,env,cid,sha,launch)
        result["durable_rows"]=int(db.sql("select count(*) from public.ops_tasks where result->>'github_report_id' is not null"))
        result["ok"]=result["ok"] and result["durable_rows"]==1
    return result

def main(argv):
    import subprocess
    if os.geteuid()!=0 or len(argv)!=2 or argv[0]!="--tick":
        raise ValueError("isolated operator invocation required")
    root=private(argv[1])
    # Local root-owned arm, never accept configuration from a mailbox message.
    fd=os.open(root/"arm.json",os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    with os.fdopen(fd,"rb") as f:
        st=os.fstat(f.fileno())
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o077:
            raise ValueError("invalid arm file")
        raw=f.read(4097)
    if len(raw)>4096:raise ValueError("oversized arm")
    config=json.loads(raw)
    if set(config)!={"arm","repo","image","scope"} or config["scope"]!="isolated_fixture":
        raise ValueError("invalid scope")
    repo=Path(config["repo"]).resolve(strict=True)
    if not repo.is_relative_to(root) or repo==root or HERE!=repo/"scripts/ops":
        raise ValueError("reviewed local repository required")
    image=config["image"]
    if image!="sha256:ef257d85f76e48da1c64832459b59fcaba1a4dac97bf5d7450c77753542eee94":
        raise ValueError("unexpected fixture image")
    def execute(cid,sha):
        env={"PATH":"/usr/bin:/bin","HOME":"/nonexistent","GIT_CONFIG_NOSYSTEM":"1"}
        head=subprocess.run(["/usr/bin/git","-C",str(repo),"rev-parse","HEAD"],env=env,check=True,capture_output=True,text=True,timeout=5).stdout.strip()
        dirty=subprocess.run(["/usr/bin/git","-C",str(repo),"status","--porcelain","--untracked-files=all"],env=env,check=True,capture_output=True,text=True,timeout=5).stdout
        if head!=sha or dirty:raise ValueError("revision mismatch")
        token=os.environ.pop("CV_GITHUB_CONTINGENCY_TOKEN","")
        if not token:raise ValueError("mailbox credential missing")
        return execute_integral(repo,image,token,cid,sha)
    return tick(root,config["arm"],execute)

if __name__=="__main__":
    try: result=main(sys.argv[1:])
    except Exception:result={"state":"blocked","production_authorized":False}
    print(json.dumps(result,sort_keys=True))
    sys.exit(0 if result["state"] in ("passed","expired","already_reserved","insufficient_time","busy") else 4)
