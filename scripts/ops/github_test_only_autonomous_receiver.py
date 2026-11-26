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
        if end-now<180:return {"state":"insufficient_time","production_authorized":False}
        atomic(root/"attempt.json",{"comment_id":cid,"sha":sha,"state":"reserved"})
        try:
            report=execute(cid,sha)
            ok=type(report) is dict and report.get("ok") is True and report.get("production_authorized") is False
        except Exception:
            ok=False
        result={"state":"passed" if ok else "blocked","comment_id":cid,"sha":sha,"production_authorized":False}
        atomic(root/"terminal.json",result)
        return result
    finally:os.close(fd)

if __name__=="__main__":
    # Intentionally no live CLI until full cleanup evidence and deployment
    # preflight are certified. Scheduler integration uses this bounded tick.
    sys.exit("fixture receiver not armed for host execution")
