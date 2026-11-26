"""Explicit recovery only; no mailbox credential or discovery required."""
import sys
import os
from pathlib import Path
HERE=Path(__file__).resolve().parent
sys.path.insert(0,str(HERE))
import github_test_only_periodic_host as host
def main():
    root=Path("/var/lib/cv-test-only-periodic")
    if os.geteuid()!=0 or not os.path.lexists(root/"STOP"):return 4
    state=host.periodic.tick(root,"",lambda:[],lambda _:None,lambda cid:host.clean(root,cid))
    print("RECOVERY_STATE="+state)
    return 0 if state=="stopped" else 4
if __name__=="__main__":sys.exit(main())
