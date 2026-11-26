#!/usr/bin/env bash
# Explicit isolated certification; never enables at boot or activates production.
set -Eeuo pipefail
umask 077
[[ $EUID == 0 && $# == 4 && $1 =~ ^[0-9a-f]{40}$ ]] || exit 2
REV=$1; A=$2; B=$3; C=$4
for id in "$A" "$B" "$C"; do [[ $id =~ ^[1-9][0-9]{0,18}$ ]] || exit 2; done
[[ $A != "$B" && $A != "$C" && $B != "$C" ]] || exit 2
HERE=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
STATE=/var/lib/cv-test-only-periodic
BASE=/opt/cv-test-only-periodic
TOKEN=/etc/control-os/test-only-periodic.mailbox
SRC="$BASE/source/scripts/ops"
SERVICE=cv-test-only-periodic.service
TIMER=cv-test-only-periodic.timer
INDEX=/sys/kernel/security/apparmor/profiles
bash "$HERE/install-isolated-periodic.sh" "$REV"
rollback() {
  rc=$?
  trap - EXIT INT TERM
  clean=0
  bash "$SRC/stop-isolated-periodic.sh" || clean=1
  rm -f -- "$TOKEN" "$STATE/approved.json" || clean=1
  if (( clean == 0 )); then
    rm -f "/run/systemd/system/$SERVICE" "/run/systemd/system/$TIMER" || clean=1
    systemctl daemon-reload || clean=1
  fi
  if grep -q '^cv-release-pilot-' "$INDEX"; then clean=1; fi
  grep -q '^cv-dev-worker-bwrap (enforce)$' "$INDEX" || clean=1
  [[ ! -e /run/cv-autonomous-apparmor.owner ]] || clean=1
  containers=$(docker ps -aq --filter name=cv-e2e-pg-) || clean=1
  [[ -z "$containers" ]] || clean=1
  for u in cv-ops-bridge.service cv-dev-worker.service; do systemctl is-active --quiet "$u" || clean=1; done
  echo "PERIODIC_EVIDENCE=$STATE"
  echo "PERIODIC_ROLLBACK_EXIT=$clean"
  (( clean == 0 )) || exit 4
  if (( rc == 0 )); then echo PERIODIC_CERTIFICATION_EXIT=0; fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
# Read existing credential without printing or exporting it.
# Validate all three remote requests before removing STOP.
 /usr/bin/python3.12 -I -B - "$STATE" "$TOKEN" "$REV" "$A" "$B" "$C" "$SRC" <<'PY'
import json,os,sys,time
from pathlib import Path
state,token,sha,a,b,c,src=sys.argv[1:]
sys.path.insert(0,src)
import github_test_only_autonomous_receiver as rx
m=rx.load()
values=[line.partition("=")[2].strip().strip('"').strip("'") for line in Path("/root/supervisor/.env").read_text().splitlines() if line.startswith("CV_GITHUB_CONTINGENCY_TOKEN=")]
if len(values)!=1 or not values[0]:raise SystemExit("credential unavailable")
for cid in (a,b,c):
    if rx.remote_deadline(m,values[0],int(cid),sha,time.time()+900,time.time())-time.time()<720:raise SystemExit("insufficient certification lifetime")
fd=os.open(token,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
with os.fdopen(fd,"w") as f:f.write(values[0]);f.flush();os.fsync(f.fileno())
rx.atomic(Path(state)/"approved.json",{"sha":sha,"scope":"isolated_fixture","certification_ids":[int(a),int(b)]})
PY
rm -- "$STATE/STOP"
systemctl start "$TIMER"
# Verify two independent successful reservations and an idle duplicate poll.
 /usr/bin/python3.12 -I -B - "$STATE" "$A" "$B" <<'PY'
import sqlite3,sys,time
from pathlib import Path
p=Path(sys.argv[1]);ids=tuple(map(int,sys.argv[2:]))
def rows():
    if not (p/"admission.sqlite3").is_file():return []
    with sqlite3.connect(p/"admission.sqlite3",timeout=3) as db:return db.execute("select cid,state from attempts order by cid").fetchall()
deadline=time.monotonic()+300
expected=sorted((i,"passed") for i in ids)
while time.monotonic()<deadline:
    r=rows()
    if any(c not in ids or s in ("blocked","interrupted") for c,s in r):raise SystemExit("unexpected outcome")
    if r==expected:break
    time.sleep(1)
else:raise SystemExit("two requests not completed")
owners=[(p/f"owner-{i}.json").read_bytes() for i in ids]
time.sleep(75)
if rows()!=expected or owners!=[(p/f"owner-{i}.json").read_bytes() for i in ids]:raise SystemExit("duplicate reservation")
print("TWO_REQUESTS_AND_REPLAY_CHECK=0")
PY
# Stop polling before changing the locally approved certification scope.
systemctl stop "$TIMER" "$SERVICE"
 /usr/bin/python3.12 -I -B - "$STATE" "$REV" "$C" <<'PY'
import json,os,sys
from pathlib import Path
p=Path(sys.argv[1]);tmp=p/"approval.next"
fd=os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
with os.fdopen(fd,"w") as f:json.dump({"sha":sys.argv[2],"scope":"isolated_fixture","certification_ids":[int(sys.argv[3])]},f);f.flush();os.fsync(f.fileno())
os.replace(tmp,p/"approved.json")
fd=os.open(p,os.O_RDONLY|os.O_DIRECTORY)
try:os.fsync(fd)
finally:os.close(fd)
PY
systemctl start "$TIMER"
# Observe an actual active transient launcher before interrupting.
 /usr/bin/python3.12 -I -B - "$STATE" "$C" "$SRC" <<'PY'
import json,subprocess,sys,time
from pathlib import Path
p=Path(sys.argv[1]);cid=int(sys.argv[2]);src=Path(sys.argv[3])
deadline=time.monotonic()+150
while time.monotonic()<deadline:
    record=p/f"owner-{cid}.json"
    if record.is_file():
        owner=json.loads(record.read_text())["owner"]
        unit="cv-test-only-cert-"+owner+".service"
        state=subprocess.run(["/usr/bin/systemctl","show","-p","ActiveState","--value",unit],capture_output=True,text=True,timeout=3).stdout.strip()
        if state=="active":
            subprocess.run(["/usr/bin/bash",str(src/"stop-isolated-periodic.sh")],check=True,timeout=150)
            print("ACTIVE_STOP_OBSERVED=1")
            break
    time.sleep(.1)
else:raise SystemExit("active launcher not observed; stop not certified")
PY
 /usr/bin/python3.12 -I -B - "$STATE" "$A" "$B" "$C" <<'PY'
import sqlite3,sys
from pathlib import Path
p=Path(sys.argv[1]);a,b,c=map(int,sys.argv[2:])
with sqlite3.connect(p/"admission.sqlite3") as db:
    rows=dict(db.execute("select cid,state from attempts"))
assert rows=={a:"passed",b:"passed",c:"interrupted"},rows
assert (p/"STOP").is_file()
print("PERSISTENT_RECOVERY_CHECK=0")
PY
