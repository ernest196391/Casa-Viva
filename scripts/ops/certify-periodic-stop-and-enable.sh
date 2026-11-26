#!/usr/bin/env bash
# Independent synthetic active-stop test; enable only the fixed isolated receiver after success.
set -Eeuo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && $1 =~ ^[0-9a-f]{40}$ ]] || exit 2
REV=$1
BASE=/opt/cv-test-only-periodic
STATE=/var/lib/cv-test-only-periodic
TOKEN="$STATE/mailbox.token"
SERVICE=cv-test-only-periodic.service
TIMER=cv-test-only-periodic.timer
INDEX=/sys/kernel/security/apparmor/profiles
SRC="$BASE/source/scripts/ops"
[[ $(stat -c '%u:%a' "$BASE") == 0:700 && $(stat -c '%u:%a' "$STATE") == 0:700 ]]
[[ ! -L "$BASE" && ! -L "$STATE" && -f "$STATE/STOP" && ! -e "$STATE/approved.json" && ! -e "$TOKEN" ]]
for u in "$SERVICE" "$TIMER"; do
  [[ $(systemctl show "$u" -p LoadState --value) == not-found ]]
  [[ ! -e "/etc/systemd/system/$u" && ! -e "/run/systemd/system/$u" ]]
done
[[ ! -e /run/cv-autonomous-apparmor.owner ]]
if grep -q '^cv-release-pilot-' "$INDEX"; then exit 4; fi
grep -q '^cv-dev-worker-bwrap (enforce)$' "$INDEX"
[[ -z $(docker ps -aq --filter name=cv-e2e-pg-) ]]
for u in cv-ops-bridge.service cv-dev-worker.service; do systemctl is-active --quiet "$u"; done
# Preserve real successful rows; do not replay them.
 /usr/bin/python3.12 -I -B - "$STATE" <<'PY'
import sqlite3,sys
from pathlib import Path
with sqlite3.connect(Path(sys.argv[1])/"admission.sqlite3") as db:
    rows=dict(db.execute("select cid,state from attempts"))
assert rows.get(7180273923)=="passed" and rows.get(7180274433)=="passed"
assert "reserved" not in rows.values()
PY
git -C "$BASE/source" fetch --no-tags origin cv-auto-002-bridge
git -C "$BASE/source" checkout --detach "$REV"
[[ $(git -C "$BASE/source" rev-parse HEAD) == "$REV" ]]
[[ -z $(git -C "$BASE/source" status --porcelain --untracked-files=all) ]]
OWNER="$$-$(date +%s%N)"
WORK="/tmp/cv-autonomous-$OWNER"
CHILD="cv-test-only-cert-$OWNER.service"
# A negative synthetic ID is never admissible from GitHub.
CID=-1
done_ok=0; started=0; installed=0
rollback() {
  rc=$?
  trap - EXIT INT TERM
  if (( done_ok == 0 )); then
    touch "$STATE/STOP"
    if (( installed )); then systemctl stop "$TIMER" "$SERVICE" || true; fi
    if (( started )); then systemctl stop "$CHILD" || true; fi
    if (( installed )); then /usr/bin/python3.12 -I -B "$SRC/github_test_only_periodic_recover.py" || echo RECOVERY_UNCONFIRMED=1; fi
    rm -f "$TOKEN" "$STATE/approved.json"
    if (( installed )); then rm -f "/etc/systemd/system/$SERVICE" "/etc/systemd/system/$TIMER" "/etc/systemd/system/timers.target.wants/$TIMER"; systemctl daemon-reload || true; fi
    echo "ENABLE_FAILED_STOP_RETAINED=1"
  fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir -m 700 "$WORK"
# The synthetic test uses an inert sleep, no candidate bytes, DB, network or credentials.
 /usr/bin/python3.12 -I -B - "$STATE" "$WORK" "$OWNER" "$SRC" <<'PY'
import sys,time
from pathlib import Path
state,work,owner,src=sys.argv[1:]
sys.path.insert(0,src)
import github_test_only_admission as ledger
import github_test_only_autonomous_receiver as rx
db=ledger.connect(Path(state))
db.execute("INSERT INTO attempts VALUES (-1,?,?,?,'reserved')",("local-stop-"+owner,"0"*40,int(time.time())))
rx.atomic(Path(state)/"owner--1.json",{"cid":-1,"owner":owner})
rx.atomic(Path(work)/"attempt.json",{"comment_id":-1,"state":"reserved"})
PY
systemd-analyze verify "$SRC/sandbox/$SERVICE" "$SRC/sandbox/$TIMER"
installed=1
install -m 644 "$SRC/sandbox/$SERVICE" "/etc/systemd/system/$SERVICE"
install -m 644 "$SRC/sandbox/$TIMER" "/etc/systemd/system/$TIMER"
systemctl daemon-reload
started=1
systemd-run --unit="$CHILD" --property=RuntimeMaxSec=120 --property=NoNewPrivileges=yes --property=PrivateNetwork=yes --property=ProtectSystem=strict --property=ProtectHome=yes --property=MemoryMax=32M --property=TasksMax=4 /usr/bin/sleep 90
systemctl is-active --quiet "$CHILD"
echo ACTIVE_SYNTHETIC_LAUNCHER_OBSERVED=1
bash "$SRC/stop-isolated-periodic.sh"
 /usr/bin/python3.12 -I -B - "$STATE" <<'PY'
import sqlite3,sys
from pathlib import Path
with sqlite3.connect(Path(sys.argv[1])/"admission.sqlite3") as db:
    rows=dict(db.execute("select cid,state from attempts"))
assert rows[-1]=="interrupted"
assert rows[7180273923]==rows[7180274433]=="passed"
assert "reserved" not in rows.values()
PY
[[ $(systemctl show "$CHILD" -p ActiveState --value) == inactive ]]
[[ ! -e "$WORK" ]]
echo ACTIVE_STOP_SYNTHETIC_CERTIFIED=1
# Prepare fixed reviewed revision only; no remote code fetching or production tasks.
 /usr/bin/python3.12 -I -B - "$STATE" "$REV" "$SRC" <<'PY'
import os,sys
from pathlib import Path
state,sha,src=sys.argv[1:]
sys.path.insert(0,src)
import github_test_only_autonomous_receiver as rx
values=[line.partition("=")[2].strip().strip('"').strip("'") for line in Path("/root/supervisor/.env").read_text().splitlines() if line.startswith("CV_GITHUB_CONTINGENCY_TOKEN=")]
if len(values)!=1 or not values[0]:raise SystemExit("credential unavailable")
p=Path(state)
fd=os.open(p/"mailbox.token",os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
with os.fdopen(fd,"w") as f:f.write(values[0]);f.flush();os.fsync(f.fileno())
rx.atomic(p/"approved.json",{"sha":sha,"scope":"isolated_fixture"})
PY
rm -- "$STATE/STOP"
# Explicit operator invocation enables only the reviewed test-only timer.
systemctl add-wants timers.target "$TIMER"
systemctl start "$TIMER"
systemctl is-active --quiet "$TIMER"
for u in cv-ops-bridge.service cv-dev-worker.service; do systemctl is-active --quiet "$u"; done
done_ok=1
echo RESTRICTED_PERIODIC_ENABLED=1
echo PRODUCTION_AUTHORIZED=0
