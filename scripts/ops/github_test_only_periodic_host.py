"""Root-only staged host adapters. No automatic installation or activation."""
import os
import sys
import json
import time
import shutil
import subprocess
from pathlib import Path
import stat
import re
HERE=Path(__file__).resolve().parent
sys.path.insert(0,str(HERE))
import github_test_only_periodic as periodic
import github_test_only_periodic_discovery as discovery
import github_test_only_autonomous_receiver as receiver
import github_test_only_autonomous_cleanup as cleanup_module
import github_test_only_apparmor_lease as lease
IMAGE="sha256:ef257d85f76e48da1c64832459b59fcaba1a4dac97bf5d7450c77753542eee94"
ENV={"PATH":"/usr/bin:/bin","HOME":"/nonexistent","GIT_CONFIG_NOSYSTEM":"1","GIT_CONFIG_GLOBAL":"/dev/null","GIT_NO_REPLACE_OBJECTS":"1"}
def read_private(path):
    return lease.read_private(path)
def owner_for(root,cid):
    path=root/("owner-"+str(cid)+".json")
    record=json.loads(read_private(path))
    owner=record["owner"]
    if set(record)!={"owner","cid"} or record["cid"]!=cid or not lease.OWNER.fullmatch(owner):
        raise ValueError("invalid owner record")
    return owner
def prepare(root,cid,source):
    owner=f"{os.getpid()}-{time.time_ns()}"
    receiver.atomic(root/("owner-"+str(cid)+".json"),{"cid":cid,"owner":owner})
    work=Path("/tmp/cv-autonomous-"+owner)
    work.mkdir(mode=0o700)
    shutil.copytree(source,work/"source",symlinks=True)
    receiver.atomic(work/"attempt.json",{"comment_id":cid,"state":"reserved"})
    return owner,work
def confirm_absent(owner,run=cleanup_module.command,observe=lease.profiles):
    if not lease.OWNER.fullmatch(owner):return False
    unit="cv-test-only-cert-"+owner+".service"
    state=run(["/usr/bin/systemctl","show","-p","LoadState","-p","ActiveState",unit])
    if "LoadState=not-found" not in state and "ActiveState=inactive" not in state:return False
    if run(["/usr/bin/docker","ps","-aq","--filter","name=^/cv-e2e-pg-"+owner+"$"]):return False
    if os.path.lexists(lease.LEASE) or observe()&lease.NAMES:return False
    return True

def clean(root,cid):
    path=root/("owner-"+str(cid)+".json")
    if not os.path.lexists(path):
        # Reservation precedes owner record and all external side effects.
        return True
    owner=owner_for(root,cid)
    work=Path("/tmp/cv-autonomous-"+owner)
    if not work.exists():
        # After reboot /tmp may be empty; independently inspect all resources.
        return confirm_absent(owner)
    if not (work/"attempt.json").is_file():
        # No external effects occur before attempt.json is persisted.
        return confirm_absent(owner)
    if cleanup_module.cleanup(owner).get("ok") is not True or not confirm_absent(owner):return False
    # Only the exact private work directory recorded before execution is removed.
    if work.resolve()!=work or not shutil.rmtree.avoids_symlink_attacks:raise ValueError("unsafe work cleanup")
    st=work.stat()
    if st.st_uid!=0 or st.st_mode&0o077 or not stat.S_ISDIR(st.st_mode):raise ValueError("unsafe work directory")
    shutil.rmtree(work)
    return True
def main(root=Path("/var/lib/cv-test-only-periodic")):
    if os.geteuid()!=0:raise ValueError("root required")
    root=Path(root)
    if root!=Path("/var/lib/cv-test-only-periodic"):raise ValueError("fixed state root required")
    cfg=json.loads(read_private(root/"approved.json"))
    if set(cfg)!={"sha","scope"} or cfg["scope"]!="isolated_fixture" or not re.fullmatch("[0-9a-f]{40}",cfg["sha"]):raise ValueError("invalid approval")
    sha=cfg["sha"]
    source=HERE.parents[1]
    if source!=Path("/opt/cv-test-only-periodic/source"):raise ValueError("fixed reviewed source required")
    def git(*args):
        return subprocess.run(["/usr/bin/git","-C",str(source),*args],env=ENV,check=True,capture_output=True,text=True,timeout=10).stdout.strip()
    if git("rev-parse","HEAD")!=sha or git("status","--porcelain","--untracked-files=all"):raise ValueError("reviewed source mismatch")
    token=read_private(Path(os.environ["CREDENTIALS_DIRECTORY"])/"mailbox").decode("ascii").strip()
    m=receiver.load()
    def discover():
        return discovery.discover(m.ghmod.github_api,token,sha)
    def execute(req):
        cid=req["comment_id"]
        receiver.remote_deadline(m,token,cid,sha,time.time()+900,time.time())
        if os.path.lexists(root/"STOP"):return {}
        owner,work=prepare(root,cid,source)
        if os.path.lexists(root/"STOP"):return {}
        lease.acquire(work,owner)
        return receiver.execute_integral(work/"source",IMAGE,token,cid,sha,owner)
    return periodic.tick(root,sha,discover,execute,lambda cid:clean(root,cid))
if __name__=="__main__":
    try:state=main()
    except Exception:state="blocked"
    print(json.dumps({"state":state,"production_authorized":False}))
    sys.exit(4 if state in ("blocked","cleanup_unconfirmed") else 0)
