#!/usr/bin/env python3
"""Install/rollback an inactive, reviewed bundle in a disposable private prefix.
Never touches systemd, production paths, credentials, or certification markers.
"""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import tempfile

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location("stage_contract",HERE/"stage-test-only-candidate.py")
stage_contract=importlib.util.module_from_spec(spec)
spec.loader.exec_module(stage_contract)
FILES=stage_contract.FILES

def private_prefix(path):
    path=Path(path).absolute()
    if not path.is_relative_to(Path("/tmp")) or path==Path("/tmp") or path.resolve()!=path:
        raise ValueError("require disposable nonsymlink prefix under /tmp")
    st=path.stat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid!=os.geteuid() or st.st_mode & 0o077:
        raise ValueError("require private owned prefix")
    return path

def read_regular(path):
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    with os.fdopen(fd,"rb") as stream:
        st=os.fstat(stream.fileno())
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=os.geteuid() or st.st_mode & 0o022:
            raise ValueError("unsafe artifact")
        data=stream.read(2*1024*1024+1)
        if len(data)>2*1024*1024:raise ValueError("oversized artifact")
        return data

def verify(bundle, expected):
    bundle=private_prefix(bundle)
    raw=read_regular(bundle/"MANIFEST.json")
    if hashlib.sha256(raw).hexdigest()!=expected:
        raise ValueError("manifest digest mismatch")
    manifest=json.loads(raw)
    if set(manifest)!={"activation","sha256"} or manifest["activation"] is not False or set(manifest["sha256"])!=set(FILES):
        raise ValueError("invalid manifest")
    data={}
    for name in FILES:
        p=bundle/name
        if p.resolve()!=p:raise ValueError("symlink artifact")
        body=read_regular(p)
        if hashlib.sha256(body).hexdigest()!=manifest["sha256"][name]:
            raise ValueError("artifact digest mismatch")
        data[name]=body
    return raw,data

def install(bundle,prefix,expected):
    prefix=private_prefix(prefix)
    raw,data=verify(bundle,expected)
    # Fixed name, no overwrite, no unit installation or activation.
    target=prefix/"cv-test-only-inactive"
    if target.exists() or target.is_symlink():raise ValueError("target occupied")
    if not shutil.rmtree.avoids_symlink_attacks:raise ValueError("unsafe cleanup platform")
    work=Path(tempfile.mkdtemp(prefix=".cv-install-",dir=prefix))
    try:
        for name,body in data.items():
            p=work/name
            p.parent.mkdir(parents=True,exist_ok=True)
            p.write_bytes(body);p.chmod(0o600)
        (work/"MANIFEST.json").write_bytes(raw)
        (work/"MANIFEST.json").chmod(0o600)
        verify(work,expected)
        if target.exists() or target.is_symlink():raise ValueError("target occupied")
        os.rename(work,target)
    finally:
        if work.exists():shutil.rmtree(work)
    return target

def rollback(prefix,expected):
    target=private_prefix(prefix)/"cv-test-only-inactive"
    verify(target,expected)
    if not shutil.rmtree.avoids_symlink_attacks:raise ValueError("unsafe cleanup platform")
    actual={str(p.relative_to(target)) for p in target.rglob("*") if not p.is_dir()}
    if actual!=set(FILES)|{"MANIFEST.json"}:raise ValueError("unexpected artifacts")
    shutil.rmtree(target)

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    parser.add_argument("action",choices=("install","rollback"))
    parser.add_argument("--prefix",required=True)
    parser.add_argument("--manifest-sha256",required=True)
    parser.add_argument("--bundle")
    args=parser.parse_args()
    if args.action=="install":
        if not args.bundle:parser.error("--bundle required")
        install(args.bundle,args.prefix,args.manifest_sha256)
    else:
        rollback(args.prefix,args.manifest_sha256)
    print(json.dumps({"action":args.action,"activation":False,"production_changed":False}))
