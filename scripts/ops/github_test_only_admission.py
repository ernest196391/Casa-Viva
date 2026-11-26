"""Persistent admission for isolated fixtures only. No network or execution.
A reservation consumes quota even after a crash; never automatically replay.
"""
import sqlite3
import time
import re
from pathlib import Path
import os
import stat

def connect(root):
    root=Path(root)
    if not root.is_absolute() or root.resolve()!=root:
        raise ValueError("absolute private directory required")
    st=root.stat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid!=os.geteuid() or st.st_mode&0o077:
        raise ValueError("private state required")
    path=root/"admission.sqlite3"
    fd=os.open(path,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW|os.O_NONBLOCK,0o600)
    try:
        st=os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=os.geteuid() or st.st_mode&0o077 or st.st_nlink!=1:
            raise ValueError("unsafe ledger")
    finally:os.close(fd)
    db=sqlite3.connect(path,timeout=3,isolation_level=None)
    db.execute("PRAGMA synchronous=FULL")
    db.execute("CREATE TABLE IF NOT EXISTS attempts (cid INTEGER PRIMARY KEY, nonce TEXT UNIQUE NOT NULL, sha TEXT NOT NULL, reserved INTEGER NOT NULL, state TEXT NOT NULL CHECK(state IN ('reserved','passed','blocked','interrupted')))")
    return db

def reserve(db, root, request, approved_sha, now=None, limit=20):
    now=int(time.time()) if now is None else now
    if type(now) is not int or type(limit) is not int or not 1<=limit<=20:
        raise ValueError("invalid policy")
    if os.path.lexists(Path(root)/"STOP"):
        return "stopped"
    cid=request["comment_id"]; nonce=request["nonce"]; sha=request["sha"]
    import datetime as dt
    expiry=dt.datetime.fromisoformat(request["expires_at"].replace("Z","+00:00")).timestamp()
    if type(cid) is not int or not 0<cid<2**63 or not isinstance(nonce,str) or not re.fullmatch("[A-Za-z0-9_-]{16,64}",nonce) or not isinstance(sha,str) or not re.fullmatch("[0-9a-f]{40}",sha) or sha!=approved_sha or request.get("repo")!="casa-viva" or request.get("profile")!="ops-python-contract":
        return "blocked"
    if not 330<=expiry-now<=900:return "expired"
    db.execute("BEGIN IMMEDIATE")
    try:
        if os.path.lexists(Path(root)/"STOP"):return "stopped"
        if db.execute("SELECT 1 FROM attempts WHERE cid=? OR nonce=?",(cid,nonce)).fetchone():return "duplicate"
        if db.execute("SELECT 1 FROM attempts WHERE state='reserved'").fetchone():return "busy"
        if db.execute("SELECT count(*) FROM attempts WHERE reserved>=?",(now-86400,)).fetchone()[0]>=limit:return "quota"
        db.execute("INSERT INTO attempts VALUES (?,?,?,?,'reserved')",(cid,nonce,sha,now))
        db.commit()
        return "reserved"
    finally:
        if db.in_transaction:db.rollback()

def finish(db,cid,outcome):
    if outcome not in ("passed","blocked","interrupted"):raise ValueError("invalid outcome")
    with db:
        return db.execute("UPDATE attempts SET state=? WHERE cid=? AND state='reserved'",(outcome,cid)).rowcount==1

def recover(db):
    """Caller MUST hold global execution lock and confirm resource cleanup first."""
    with db:
        return db.execute("UPDATE attempts SET state='interrupted' WHERE state='reserved'").rowcount
