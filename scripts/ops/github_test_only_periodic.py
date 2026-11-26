"""Offline-testable periodic orchestration; no live activation or production DB.
Adapters must use certified isolated fixture execution and exact-owner cleanup.
"""
import fcntl
import os
import stat
import time
from pathlib import Path
import github_test_only_admission as ledger

def tick(root, approved_sha, discover, execute, cleanup, now=None, limit=20):
    root=Path(root)
    db=ledger.connect(root)
    fd=None
    try:
        fd=os.open(root/"execution.lock",os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW|os.O_NONBLOCK,0o600)
        st=os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=os.geteuid() or st.st_mode&0o077 or st.st_nlink!=1:
            raise ValueError("unsafe execution lock")
        try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:return "busy"
        # Recover only after independently confirmed cleanup of the exact owner.
        for cid, in db.execute("SELECT cid FROM attempts WHERE state='reserved'"):
            if cleanup(cid) is not True:return "cleanup_unconfirmed"
        ledger.recover(db)
        if os.path.lexists(root/"STOP"):return "stopped"
        requests=discover()
        if not isinstance(requests,list) or len(requests)>=100:return "blocked"
        for request in sorted(requests,key=lambda r:r["comment_id"]):
            stamp=int(time.time()) if now is None else now
            state=ledger.reserve(db,root,request,approved_sha,stamp,limit)
            if state in ("stopped","quota","busy"):return state
            if state!="reserved":continue
            cid=request["comment_id"]
            outcome="blocked"
            try:
                if not os.path.lexists(root/"STOP"):
                    result=execute(request)
                    if isinstance(result,dict) and result.get("ok") is True and result.get("production_authorized") is False and result.get("durable_rows")==1:
                        outcome="passed"
            except Exception:pass
            # Never release the reservation on uncertain cleanup.
            if cleanup(cid) is not True:return "cleanup_unconfirmed"
            if not ledger.finish(db,cid,outcome):raise RuntimeError("reservation lost")
            return outcome
        return "idle"
    finally:
        if fd is not None:os.close(fd)
        db.close()
