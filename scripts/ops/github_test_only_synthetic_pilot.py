#!/usr/bin/env python3
"""Operator-only fixed synthetic pilot, reusing CV-AUTO-001.
Never contacts GitHub, Supabase, production workers or the release gate.
No remote command input; no permanent systemd unit or profile installation.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import time

HERE=Path(__file__).resolve().parent
DIGEST="ad56c87fba9c5ccb77906f2ab143b7ca1fe53ca0b14beffbfac17e4e76ac777e"
CAPS="CAP_CHOWN CAP_SETUID CAP_SETGID CAP_DAC_READ_SEARCH CAP_DAC_OVERRIDE CAP_KILL CAP_SYS_PTRACE"
ENV={"PATH":"/usr/bin:/bin","LANG":"C","HOME":"/nonexistent"}
UNIT_RE=re.compile(r"cv-release-pilot-[0-9]+-[0-9]+\.service")

def command(unit):
    if not UNIT_RE.fullmatch(unit):raise ValueError("invalid unit")
    return ["/usr/bin/systemd-run","--wait","--pipe","--collect","--quiet",
            "--unit="+unit,"--service-type=exec",
            "--property=NoNewPrivileges=yes",
            "--property=CapabilityBoundingSet="+CAPS,
            "--property=AmbientCapabilities=",
            "--property=MemoryMax=256M","--property=MemorySwapMax=0",
            "--property=CPUQuota=50%","--property=TasksMax=32",
            "--property=RuntimeMaxSec=45","--property=TimeoutStopSec=5",
            "--property=KillMode=control-group","--property=UMask=0077",
            "--property=PrivateNetwork=yes","--property=ProtectSystem=strict",
            "--property=ProtectHome=yes","--property=LimitCORE=0",
            "--property=TemporaryFileSystem=/var/lib/control-os-dev/work:rw,size=64M,nr_inodes=4096,mode=0755",
            "--property=ReadWritePaths=/var/lib/control-os-dev/work /tmp",
            "--property=WorkingDirectory="+str(HERE),
            "/usr/bin/python3.12","-I","-B",str(HERE/"github_release_candidate_boundary.py"),"--synthetic-pilot"]

def validate_report(raw):
    if not isinstance(raw,str) or len(raw)>16384:raise ValueError("invalid transcript")
    r=json.loads(raw)
    if (type(r) is not dict or r.get("ok") is not True or r.get("functional_ok") is not True
        or r.get("scope")!="synthetic_only" or r.get("production_authorized") is not False
        or r.get("digest")!=DIGEST):raise ValueError("pilot failed")
    measurements=r.get("measurements"); hosts=r.get("host_measurements")
    if not isinstance(measurements,list) or not isinstance(hosts,list) or len(measurements)!=3 or len(hosts)!=3:
        raise ValueError("missing measurements")
    invocations=set()
    for m,h in zip(measurements,hosts):
        if (type(m) is not dict or type(h) is not dict
            or m.get("digest")!=DIGEST or m.get("nnp") is not True or m.get("caps_zero") is not True
            or m.get("label")!="cv-release-pilot-bootstrap//&cv-release-pilot-candidate (enforce)"
            or type(m.get("uid")) is not int or m["uid"]<=0
            or type(m.get("pid")) is not int or m["pid"]<=0
            or h.get("measurement")!=m or h.get("digest")!=DIGEST
            or h.get("namespace_pid")!=m["pid"] or h.get("uid")!=m["uid"]
            or h.get("production_authorized") is not False or h.get("scope")!="synthetic_only"
            or type(h.get("host_pid")) is not int or h["host_pid"]<=0
            or type(h.get("start_ticks")) is not int or h["start_ticks"]<=0
            or not isinstance(h.get("invocation"),str) or not re.fullmatch("[0-9a-f]{32}",h["invocation"])
            or h["invocation"]=="0"*32):raise ValueError("invalid host evidence")
        invocations.add(h["invocation"])
    if len(invocations)!=1:raise ValueError("changed invocation")
    return {"ok":True,"scope":"synthetic_only","cases":3,"digest":DIGEST,
            "host_observed":True,"production_authorized":False}

def run():
    # Explicit operator invocation only; the staged bundle must have been
    # verified independently against the reviewed Git revision.
    if os.geteuid()!=0:return {"ok":False,"reason":"operator_required","production_authorized":False}
    unit=f"cv-release-pilot-{os.getpid()}-{time.monotonic_ns()}.service"
    try:
        result=subprocess.run(command(unit),env=ENV,stdin=subprocess.DEVNULL,
                              capture_output=True,text=True,timeout=55,check=False)
        if result.returncode!=0:raise ValueError("unit failed")
        report=validate_report(result.stdout)
    except (OSError,ValueError,TypeError,KeyError,subprocess.TimeoutExpired):
        report={"ok":False,"reason":"pilot_failed","production_authorized":False}
    finally:
        # Only the unique transient pilot; never touch existing services.
        try:
            subprocess.run(["/usr/bin/systemctl","stop",unit],env=ENV,
                           stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,
                           timeout=10,check=False)
            state=subprocess.run(["/usr/bin/systemctl","show","-p","LoadState","-p","ActiveState",unit],
                                 env=ENV,capture_output=True,text=True,timeout=5,check=False)
            fields=dict(line.split("=",1) for line in state.stdout.splitlines() if "=" in line)
            clean=fields.get("LoadState")=="not-found" or fields.get("ActiveState")=="inactive"
        except (OSError,ValueError,subprocess.TimeoutExpired):
            clean=False
    if not clean:return {"ok":False,"reason":"cleanup_unconfirmed","production_authorized":False}
    return dict(report,cleanup_confirmed=True)

if __name__=="__main__":
    import sys
    if sys.argv[1:]!=["--synthetic-only"]:sys.exit(2)
    report=run()
    print(json.dumps(report,sort_keys=True))
    sys.exit(0 if report["ok"] else 4)
