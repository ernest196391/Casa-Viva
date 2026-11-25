"""Trusted test-only launcher. Local Git objects only, no checkout or network.
Requires an independently provisioned dedicated systemd unit and sandbox.
Never install or start services from this module.
"""
import os
import re
import shutil
import stat
import subprocess
import tempfile
import time
from pathlib import Path
import importlib.util

HERE=Path(__file__).resolve().parent
REPO=Path("/root/Casa-Viva")
SHA=re.compile(r"[0-9a-f]{40}")
MAX_BLOB=2*1024*1024
MAX_TOTAL=32*1024*1024
MAX_FILES=1500
ENV={"PATH":"/usr/bin:/bin","HOME":"/nonexistent","GIT_CONFIG_NOSYSTEM":"1","GIT_CONFIG_GLOBAL":"/dev/null","GIT_NO_REPLACE_OBJECTS":"1","GIT_NO_LAZY_FETCH":"1","GIT_TERMINAL_PROMPT":"0"}
UNIT="cv-test-only-candidate.service"
# Reuse the existing E3 AppArmor stacked transition. Test bytes are never
# imported, opened, or compiled before the in-child mandatory preflight.
CONFINED_TEST_PREFIX = r"""
import os,sys,runpy
with open('/proc/self/attr/current','w') as f:
    f.write('stack cv-release-pilot-candidate')
with open('/proc/self/attr/current') as f:
    label=f.read().strip()
if label!='cv-release-pilot-bootstrap//&cv-release-pilot-candidate (enforce)':
    raise SystemExit(126)
with open('/proc/self/status') as f:
    fields=dict(line.split(':',1) for line in f if ':' in line)
if os.getuid()==0 or os.geteuid()==0 or fields.get('NoNewPrivs','').strip()!='1':
    raise SystemExit(126)
if any(int(fields.get(k,'1').strip(),16) for k in ('CapEff','CapPrm','CapInh','CapAmb','CapBnd')):
    raise SystemExit(126)
if sys.argv[1:]!=['--fixed-test']:
    raise SystemExit(126)
runpy.run_path('scripts/ops/tests/github-test-only-bridge.test.py',run_name='__main__')
"""

def load(name,file):
    spec=importlib.util.spec_from_file_location(name,HERE/file)
    mod=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

def git(args,timeout=10):
    return subprocess.run(["/usr/bin/git","--no-replace-objects","-C",str(REPO),*args],
        env=ENV,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,
        timeout=timeout,check=True).stdout

def extract(sha,workspace,deadline):
    def bounded_git(args):
        remaining=deadline-time.monotonic()
        if remaining<=0: raise TimeoutError('extraction deadline')
        return git(args,timeout=min(10,remaining))
    if not isinstance(sha,str) or not SHA.fullmatch(sha):
        raise ValueError("invalid revision")
    if bounded_git(["cat-file","-t",sha]).strip()!=b"commit":
        raise ValueError("not a commit")
    # Only the fixed test subtree; no hooks, archive filters, symlinks or submodules.
    listing=bounded_git(["ls-tree","-r","-l","-z",sha,"--","scripts/ops"])
    records=listing.split(b"\0")[:-1]
    if not records or len(records)>MAX_FILES or len(listing)>1024*1024:
        raise ValueError("tree size")
    total=0
    for record in records:
        meta,path=record.split(b"\t",1)
        mode,kind,oid,size=meta.split()
        if mode not in (b"100644",b"100755") or kind!=b"blob" or not size.isdigit():
            raise ValueError("nonregular object")
        size=int(size); total+=size
        if size>MAX_BLOB or total>MAX_TOTAL:
            raise ValueError("blob budget")
        rel=path.decode("ascii")
        parts=rel.split("/")
        if parts[:2]!=["scripts","ops"] or any(not re.fullmatch(r"[A-Za-z0-9_.-]+",p) or p in (".","..",".git") or p.startswith(".env") for p in parts):
            raise ValueError("unsafe path")
        data=bounded_git(["cat-file","blob",oid.decode("ascii")])
        if len(data)!=size: raise ValueError("blob mismatch")
        dst=workspace.joinpath(*parts)
        dst.parent.mkdir(parents=True,exist_ok=True)
        with dst.open("xb") as f: f.write(data)

def launch(sha,budget):
    # This gate is deliberately fail-closed until a trusted host unit exists.
    # A root-owned receipt is not a substitute for independently verified cgroups.
    if os.environ.get("CV_TEST_ONLY_SANDBOX_CERTIFIED")!="1":
        return 125
    box=load("test_only_box","dev-sandbox.py")
    worker=load("test_only_worker","dev-worker.py")
    limits=load("test_only_limits","github_release_candidate_limits.py")
    root=worker._sandbox_parent()
    ids=worker._sandbox_ids()
    if not shutil.rmtree.avoids_symlink_attacks:
        return 125
    # The current process must already be in the dedicated bounded unit.
    # Do not run a candidate in the shared worker cgroup.
    expected="/system.slice/"+UNIT
    if "0::"+expected not in Path("/proc/self/cgroup").read_text().splitlines():
        return 125
    cg=Path("/sys/fs/cgroup"+expected)
    if not limits.assess({k:(cg/k).read_text().strip() for k in ("memory.max","pids.max","cpu.max")}):
        return 125
    started=time.monotonic()
    workspace=Path(tempfile.mkdtemp(prefix="run-test-",dir=root))
    try:
        extract(sha,workspace,started+min(25,budget-15))
        remaining=min(60,int(budget-(time.monotonic()-started)))
        if remaining<5:return 124
        box.hand_over(workspace,ids["uid"],ids["gid"])
        argv=box.spec.build_bwrap_command(workspace,["/usr/bin/python3.12","-I","-S","-B","-c",CONFINED_TEST_PREFIX,"--fixed-test"],
            bwrap_binary=box.DEDICATED_BWRAP,work_root=box.WORK_ROOT,child_env=box.BOX_ENV,drop_capabilities=True)
        if box.spec.validate_command(argv,box.WORK_ROOT,bwrap_binary=box.DEDICATED_BWRAP,require_cap_drop=True):
            return 125
        rc,_=box._launch(["/usr/bin/aa-exec","-p","cv-release-pilot-bootstrap","--",*argv],cwd=str(workspace),env={},timeout=remaining,**ids)
        return rc
    except (TimeoutError,subprocess.TimeoutExpired):
        return 124
    finally:
        shutil.rmtree(workspace)
