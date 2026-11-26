#!/usr/bin/env python3
"""Operator-only exact launcher certification. No DB, GitHub API or live worker."""
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time
import sys

HERE=Path(__file__).resolve().parent
UNIT=re.compile(r"cv-test-only-cert-[0-9]+-[0-9]+\.service")
ENV={"PATH":"/usr/bin:/bin","HOME":"/nonexistent","LANG":"C"}
CAPS="CAP_CHOWN CAP_SETUID CAP_SETGID CAP_DAC_OVERRIDE CAP_KILL"

def command(unit,repo,sha,budget=90):
    if type(budget) is not int or not 25<=budget<=90:raise ValueError('invalid budget')
    if not UNIT.fullmatch(unit) or not re.fullmatch("[0-9a-f]{40}",sha):
        raise ValueError("invalid certification request")
    return ["/usr/bin/systemd-run","--wait","--pipe","--collect","--quiet",
        "--unit="+unit,"--service-type=exec","--property=NoNewPrivileges=yes",
        "--property=CapabilityBoundingSet="+CAPS,"--property=AmbientCapabilities=",
        "--property=MemoryMax=256M","--property=MemorySwapMax=0","--property=CPUQuota=50%",
        "--property=TasksMax=32","--property=RuntimeMaxSec="+str(budget-17),"--property=TimeoutStopSec=5",
        "--property=KillMode=control-group","--property=UMask=0077",
        "--property=PrivateNetwork=yes","--property=ProtectSystem=strict",
        "--property=ProtectHome=yes","--property=LimitCORE=0","--property=LimitFSIZE=33554432",
        "--property=TemporaryFileSystem=/var/lib/control-os-dev/work:rw,size=64M,nr_inodes=4096,mode=0755",
        "--property=ReadWritePaths=/var/lib/control-os-dev/work /tmp",
        "/usr/bin/python3.12","-I","-B",str(__file__), "--child",unit,str(repo),sha,str(budget-20)]

def child(unit,repo,sha,budget='70'):
    if not budget.isdigit() or not 5<=int(budget)<=70:return 125
    if not UNIT.fullmatch(unit) or not re.fullmatch("[0-9a-f]{40}",sha):return 125
    repo=Path(repo)
    if not repo.is_absolute() or not repo.is_relative_to("/tmp") or repo.resolve()!=repo:return 125
    if not (repo/".git").is_dir():return 125
    if Path("/proc/self/cgroup").read_text().strip()!="0::/system.slice/"+unit:return 125
    sys.path.insert(0,str(HERE))
    import github_test_only_launcher as launcher
    launcher.REPO=repo
    os.environ.clear()
    os.environ.update(ENV,CV_TEST_ONLY_SANDBOX_CERTIFIED="1")
    return launcher._launch_exact(sha,int(budget),unit)

def run(repo,sha,budget=90,owner=None):
    if type(budget) is not int or not 25<=budget<=90:raise ValueError('invalid budget')
    deadline=time.monotonic()+budget
    def remaining(cap):
        left=deadline-time.monotonic()
        if left<=0:raise subprocess.TimeoutExpired('certification',budget)
        return min(cap,left)
    if os.geteuid()!=0:raise ValueError("operator required")
    repo=Path(repo).resolve(strict=True)
    if not repo.is_relative_to("/tmp") or not (repo/".git").is_dir():raise ValueError("temporary repository required")
    if owner is not None and not re.fullmatch(r"[0-9]{1,12}-[0-9]{1,20}",owner):raise ValueError("invalid fixture owner")
    unit="cv-test-only-cert-"+(owner or f"{os.getpid()}-{time.monotonic_ns()}")+".service"
    rc=125;clean=False
    try:
        result=subprocess.run(command(unit,repo,sha,budget),env=ENV,stdin=subprocess.DEVNULL,
                              capture_output=True,timeout=remaining(budget-15),check=False)
        rc=result.returncode
    except (OSError,subprocess.TimeoutExpired):pass
    finally:
        try:
            subprocess.run(["/usr/bin/systemctl","stop",unit],env=ENV,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=remaining(10),check=False)
            state=subprocess.run(["/usr/bin/systemctl","show","-p","LoadState","-p","ActiveState",unit],env=ENV,capture_output=True,text=True,timeout=remaining(5),check=False)
            fields=dict(x.split("=",1) for x in state.stdout.splitlines() if "=" in x)
            clean=fields.get("LoadState")=="not-found" or fields.get("ActiveState")=="inactive"
        except (OSError,subprocess.TimeoutExpired):pass
    return dict(ok=rc==0 and clean,launcher_exit=rc,cleanup_confirmed=clean,sha=sha,
                scope="exact_launcher_fixture",production_authorized=False)

if __name__=="__main__":
    if len(sys.argv)==6 and sys.argv[1]=="--child":
        sys.exit(child(*sys.argv[2:]))
    if len(sys.argv)!=4 or sys.argv[1]!="--certify":sys.exit(2)
    try:report=run(sys.argv[2],sys.argv[3])
    except (OSError,ValueError):report=dict(ok=False,production_authorized=False,reason="preconditions")
    print(json.dumps(report,sort_keys=True))
    sys.exit(0 if report["ok"] else 4)
