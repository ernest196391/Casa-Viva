#!/usr/bin/env python3
"""Read-only Hostinger readiness audit for CV-AUTO-002. Never enables service.
No output from privileged commands, credentials or environment is collected.
This is a dependency audit, not a certificate of kernel confinement.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess

REQUIRED_PROFILES=("cv-release-pilot-bootstrap (enforce)",
                   "cv-release-pilot-candidate (enforce)")
UNIT="cv-test-only-candidate.service"
BINARIES=("/usr/bin/git","/usr/bin/python3.12","/usr/bin/aa-exec",
          "/usr/local/lib/control-os/bwrap-dev")
SOURCE_REPO=Path("/root/Casa-Viva")
PROFILE_INDEX=Path("/sys/kernel/security/apparmor/profiles")

def inspect(*, exists=None, read=None, run=None):
    """Injectable, read-only probe. Fail closed on unknown or errors."""
    exists=exists or (lambda p: Path(p).is_file())
    read=read or (lambda p: Path(p).read_text())
    run=run or (lambda argv: subprocess.run(argv,check=False,timeout=8,
            stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,text=True).returncode)
    results={}
    for binary in BINARIES:
        results["binary:"+binary]=bool(exists(binary))
    try:
        text=read(PROFILE_INDEX)
    except (OSError,ValueError):
        text=""
    for name in REQUIRED_PROFILES:
        results["apparmor:"+name]=name in text.splitlines()
    # A local Git clone must exist, but the audit never fetches or updates it.
    results["local_git_objects"]=bool(exists(SOURCE_REPO/".git/HEAD") or
                                      exists(SOURCE_REPO/".git"))
    # systemd-analyze verifies syntax only. It does not start the unit or
    # prove that NoNewPrivileges/cgroups/AppArmor are effective at runtime.
    unit=Path(__file__).resolve().parent/"sandbox"/UNIT
    try:
        results["systemd_unit_syntax"]=unit.is_file() and run(
            ["/usr/bin/systemd-analyze","verify",str(unit)])==0
    except (OSError,ValueError,subprocess.TimeoutExpired):
        results["systemd_unit_syntax"]=False
    return {"ready_for_reversible_host_pilot":all(results.values()),
            "checks":results,"runtime_certified":False,"activation":False}

if __name__=="__main__":
    print(json.dumps(inspect(),sort_keys=True))
