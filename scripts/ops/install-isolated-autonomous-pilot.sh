#!/usr/bin/env bash
# Isolated one-shot pilot. Never installs production units.
set -Eeuo pipefail
umask 077
[[ $EUID == 0 && $# == 2 ]] || { echo 'usage: isolated-pilot.sh reviewed-sha comment-id'; exit 2; }
REV=$1
CID=$2
[[ $REV =~ ^[0-9a-f]{40}$ && $CID =~ ^[1-9][0-9]{0,19}$ ]] || exit 2
OWNER="$$-$(date +%s%N)"
ROOT="/tmp/cv-autonomous-$OWNER"
SERVICE="cv-autonomous-fixture@$OWNER.service"
TIMER="cv-autonomous-fixture@$OWNER.timer"
IMAGE=sha256:ef257d85f76e48da1c64832459b59fcaba1a4dac97bf5d7450c77753542eee94
mkdir -m 700 "$ROOT"
installed=0
cleanup() {
  local rc=$? clean=0
  trap - EXIT INT TERM
  if (( installed )); then
    systemctl stop "$TIMER" "$SERVICE" || clean=1
    if [[ -f "$ROOT/attempt.json" ]]; then
      /usr/bin/python3.12 -I -B "$ROOT/source/scripts/ops/github_test_only_autonomous_cleanup.py" "$OWNER" || clean=1
    fi
    rm -f "/run/systemd/system/$SERVICE" "/run/systemd/system/$TIMER"
    systemctl daemon-reload || clean=1
  fi
  rm -f "$ROOT/mailbox.token"
  printf 'PILOT_EVIDENCE=%s\nROLLBACK_EXIT=%s\n' "$ROOT" "$clean"
  (( clean == 0 )) || exit 4
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
git clone --no-checkout --single-branch --branch cv-auto-002-bridge github-casaviva:ernest196391/Casa-Viva.git "$ROOT/source"
git -C "$ROOT/source" checkout --detach "$REV"
[[ $(git -C "$ROOT/source" rev-parse HEAD) == "$REV" ]]
[[ -z $(git -C "$ROOT/source" status --porcelain) ]]
docker image inspect "$IMAGE" >/dev/null
INDEX=/sys/kernel/security/apparmor/profiles
! grep -q '^cv-release-pilot-' "$INDEX"
grep -q '^cv-dev-worker-bwrap (enforce)$' "$INDEX"
[[ ! -e /run/cv-autonomous-apparmor.owner ]]
for unit in cv-ops-bridge.service cv-dev-worker.service; do systemctl is-active --quiet "$unit"; done
for unit in "$SERVICE" "$TIMER"; do
  [[ ! -e "/run/systemd/system/$unit" && ! -e "/etc/systemd/system/$unit" ]]
done
/usr/bin/python3.12 -I -B - "$ROOT" "$REV" "$CID" "$IMAGE" <<'PY'
import json,os,sys,time
from pathlib import Path
root,rev,cid,image=sys.argv[1:]
p=Path(root)
values=[line.partition("=")[2].strip().strip('"').strip("'") for line in Path("/root/supervisor/.env").read_text().splitlines() if line.startswith("CV_GITHUB_CONTINGENCY_TOKEN=")]
if len(values)!=1 or not values[0]:raise SystemExit("credential unavailable")
(p/"mailbox.token").write_text(values[0])
(p/"mailbox.token").chmod(0o600)
now=int(time.time())
(p/"arm.json").write_text(json.dumps({"scope":"isolated_fixture","repo":root+"/source","image":image,"arm":{"comment_id":int(cid),"sha":rev,"armed_at":now,"expires_at":now+900}}))
(p/"arm.json").chmod(0o600)
PY
BASE="$ROOT/source/scripts/ops/sandbox"
cp "$BASE/cv-autonomous-fixture@.service" "$ROOT/$SERVICE"
cp "$BASE/cv-autonomous-fixture@.timer" "$ROOT/$TIMER"
systemd-analyze verify "$ROOT/$SERVICE" "$ROOT/$TIMER"
installed=1
install -m 0644 "$ROOT/$SERVICE" "/run/systemd/system/$SERVICE"
install -m 0644 "$ROOT/$TIMER" "/run/systemd/system/$TIMER"
systemctl daemon-reload
systemctl start "$TIMER"
# The timer, not this shell, invokes the receiver.
deadline=$((SECONDS+360))
while (( SECONDS < deadline )); do
  if [[ -f "$ROOT/terminal.json" ]]; then
    state=$(systemctl show "$SERVICE" -p ActiveState --value)
    [[ $state == inactive || $state == failed ]] && break
  fi
  [[ $(systemctl show "$SERVICE" -p ActiveState --value) != failed ]] || break
  sleep 3
done
[[ -f "$ROOT/terminal.json" ]]
cat "$ROOT/terminal.json"
[[ $(systemctl show "$SERVICE" -p Result --value) == success ]]
/usr/bin/python3.12 -I -B - "$ROOT/terminal.json" <<'PY'
import json,sys
r=json.load(open(sys.argv[1]))
assert r["state"]=="passed" and r["durable_rows"]==1 and r["production_authorized"] is False
PY
! grep -q '^cv-release-pilot-' "$INDEX"
grep -q '^cv-dev-worker-bwrap (enforce)$' "$INDEX"
[[ -z $(docker ps -aq --filter "name=^/cv-e2e-pg-$OWNER$") ]]
[[ ! -e /run/cv-autonomous-apparmor.owner ]]
for unit in cv-ops-bridge.service cv-dev-worker.service; do systemctl is-active --quiet "$unit"; done
echo AUTONOMOUS_PILOT_EXIT=0
