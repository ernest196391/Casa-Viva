#!/usr/bin/env python3
"""Independent rollback of a locally owned isolated pilot; never production."""
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import importlib.util

def release_profiles(root,owner):
    spec=importlib.util.spec_from_file_location('lease',Path(__file__).resolve().parent/'github_test_only_apparmor_lease.py')
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
    mod.release(root,owner)

ENV={"PATH":"/usr/bin:/bin","HOME":"/nonexistent","LANG":"C"}
OWNER=re.compile(r"[0-9]{1,12}-[0-9]{1,20}")
def command(args):
    return subprocess.run(args,env=ENV,stdin=subprocess.DEVNULL,capture_output=True,text=True,timeout=10,check=True).stdout.strip()

def cleanup(owner,run=command):
    if not OWNER.fullmatch(owner):raise ValueError("invalid pilot owner")
    root=Path("/tmp/cv-autonomous-"+owner)
    if root.resolve()!=root:raise ValueError("unsafe pilot directory")
    info=root.stat()
    if info.st_uid!=0 or info.st_mode&0o077 or not stat.S_ISDIR(info.st_mode):raise ValueError("unsafe pilot directory")
    if not (root/"attempt.json").is_file():return {"ok":True,"state":"not_started","production_authorized":False}
    unit="cv-test-only-cert-"+owner+".service"
    name="cv-e2e-pg-"+owner
    # Restrict cleanup to the exact reserved instance and Docker ownership label.
    state=run(["/usr/bin/systemctl","show","-p","LoadState","-p","ActiveState",unit])
    if "LoadState=not-found" not in state and "ActiveState=inactive" not in state:
        run(["/usr/bin/systemctl","stop",unit])
    state=run(["/usr/bin/systemctl","show","-p","LoadState","-p","ActiveState",unit])
    if "LoadState=not-found" not in state and "ActiveState=inactive" not in state:raise ValueError("unit cleanup unconfirmed")
    ids=run(["/usr/bin/docker","ps","-aq","--no-trunc","--filter","name=^/"+name+"$","--filter","label=com.casaviva.fixture="+owner]).splitlines()
    if len(ids)>1 or any(not re.fullmatch("[0-9a-f]{64}",i) for i in ids):raise ValueError("invalid Docker identity")
    if ids:run(["/usr/bin/docker","rm","-f","-v",ids[0]])
    if run(["/usr/bin/docker","ps","-aq","--filter","name=^/"+name+"$"]):raise ValueError("container cleanup unconfirmed")
    release_profiles(root,owner)
    return {"ok":True,"state":"resources_removed","profile_cleanup":"confirmed","production_authorized":False}

if __name__=="__main__":
    try:
        if os.geteuid()!=0 or len(sys.argv)!=2:raise ValueError("operator required")
        result=cleanup(sys.argv[1])
    except Exception:result={"ok":False,"state":"cleanup_unconfirmed","production_authorized":False}
    print(json.dumps(result,sort_keys=True))
    sys.exit(0 if result["ok"] else 4)
