#!/usr/bin/env python3
"""Exclusive cooperative lease for fixed temporary AppArmor profile names.
All participating pilots serialize with a root-owned lock. Refuse pre-existing
profiles and never replace them. The production profile is not in the payload.
"""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess

NAMES={"cv-release-pilot-bootstrap","cv-release-pilot-candidate"}
INDEX=Path("/sys/kernel/security/apparmor/profiles")
LOCK=Path("/run/cv-autonomous-apparmor.lock")
LEASE=Path("/run/cv-autonomous-apparmor.owner")
ENV={"PATH":"/usr/bin:/bin","HOME":"/nonexistent","LANG":"C"}
OWNER=re.compile(r"[0-9]{1,12}-[0-9]{1,20}")

def command(args):
    return subprocess.run(args,env=ENV,stdin=subprocess.DEVNULL,capture_output=True,text=True,timeout=8,check=True).stdout

def profiles():
    return {line.split(" (",1)[0] for line in INDEX.read_text().splitlines()}

def read_private(path):
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    with os.fdopen(fd,"rb") as f:
        s=os.fstat(f.fileno())
        if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode&0o077:raise ValueError("unsafe ownership file")
        b=f.read(65537)
    if len(b)>65536:raise ValueError("oversized file")
    return b

def put(path,data):
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,"wb") as f:f.write(data);f.flush();os.fsync(f.fileno())

def lock():
    fd=os.open(LOCK,os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
    s=os.fstat(fd)
    if s.st_uid!=0 or not stat.S_ISREG(s.st_mode) or s.st_mode&0o077:
        os.close(fd);raise ValueError("unsafe global lock")
    try:fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
    except BaseException:os.close(fd);raise
    return fd

def payload(source):
    raw=source.read_text()
    header,marker,body=raw.partition("# CV_RELEASE_PILOT_BEGIN")
    if not marker:raise ValueError("missing profile marker")
    result="\n".join(header.splitlines()[:5])+"\n"+marker+body
    if set(re.findall(r"(?m)^profile ([\w-]+) ",result))!=NAMES:raise ValueError("unexpected profile set")
    if "profile cv-dev-worker-bwrap" in result:raise ValueError("production profile forbidden")
    return result.encode()

def acquire(root,owner,run=command,observe=profiles):
    if not OWNER.fullmatch(owner):raise ValueError("invalid owner")
    fd=lock()
    try:
        if LEASE.exists() or LEASE.is_symlink() or observe()&NAMES:raise ValueError("profiles already reserved")
        p=root/"pilot.profile"
        data=payload(root/"source/scripts/ops/sandbox/dev-worker-sandbox.profile")
        put(p,data)
        run(["/usr/sbin/apparmor_parser","-Q","-T",str(p)])
        put(LEASE,json.dumps({"owner":owner,"sha256":hashlib.sha256(data).hexdigest()}).encode())
        run(["/usr/sbin/apparmor_parser","-a","-T",str(p)])
        if not NAMES<=observe():raise ValueError("profile load unconfirmed")
    finally:os.close(fd)

def release(root,owner,run=command,observe=profiles):
    if not OWNER.fullmatch(owner):raise ValueError("invalid owner")
    fd=lock()
    try:
        if not LEASE.exists() and not LEASE.is_symlink():
            if observe()&NAMES:raise ValueError("unowned profiles present")
            return
        lease=json.loads(read_private(LEASE))
        if lease.get("owner")!=owner:raise ValueError("foreign profile lease")
        p=root/"pilot.profile"
        data=read_private(p)
        if hashlib.sha256(data).hexdigest()!=lease.get("sha256") or data!=payload(root/"source/scripts/ops/sandbox/dev-worker-sandbox.profile"):
            raise ValueError("profile digest mismatch")
        active=observe()&NAMES
        if active and active!=NAMES:raise ValueError("partial profile set; operator review required")
        if active:run(["/usr/sbin/apparmor_parser","-R",str(p)])
        if observe()&NAMES:raise ValueError("profile cleanup unconfirmed")
        LEASE.unlink()
    finally:os.close(fd)
