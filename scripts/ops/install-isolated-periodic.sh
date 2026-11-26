#!/usr/bin/env bash
# Reversible staging only: no timer start, no boot enablement, no mailbox requests.
set -Eeuo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && $1 =~ ^[0-9a-f]{40}$ ]] || exit 2
REV=$1
BASE=/opt/cv-test-only-periodic
STATE=/var/lib/cv-test-only-periodic
TOKEN=/var/lib/cv-test-only-periodic/mailbox.token
SERVICE=/run/systemd/system/cv-test-only-periodic.service
TIMER=/run/systemd/system/cv-test-only-periodic.timer
for p in "$BASE" "$STATE" "$TOKEN" "$SERVICE" "$TIMER" /etc/systemd/system/cv-test-only-periodic.service /etc/systemd/system/cv-test-only-periodic.timer; do
  [[ ! -e "$p" && ! -L "$p" ]] || { echo EXISTING_PERIODIC_RESOURCE; exit 4; }
done
for u in cv-test-only-periodic.service cv-test-only-periodic.timer; do
  [[ $(systemctl show "$u" -p LoadState --value) == not-found ]] || exit 4
done
for u in cv-ops-bridge.service cv-dev-worker.service; do systemctl is-active --quiet "$u"; done
INDEX=/sys/kernel/security/apparmor/profiles
if grep -q '^cv-release-pilot-' "$INDEX"; then exit 4; fi
grep -q '^cv-dev-worker-bwrap (enforce)$' "$INDEX"
[[ ! -e /run/cv-autonomous-apparmor.owner ]]
[[ -z $(docker ps -aq --filter name=cv-e2e-pg-) ]]
docker image inspect sha256:ef257d85f76e48da1c64832459b59fcaba1a4dac97bf5d7450c77753542eee94 >/dev/null
made_base=0; made_state=0; made_service=0; made_timer=0
rollback() {
  rc=$?
  trap - EXIT
  if (( rc != 0 )); then
    clean=0
    if (( made_timer )); then rm -f "$TIMER" || clean=1; fi
    if (( made_service )); then rm -f "$SERVICE" || clean=1; fi
    if (( made_timer || made_service )); then systemctl daemon-reload || clean=1; fi
    if (( made_base )); then rm -rf -- "$BASE" || clean=1; fi
    if (( made_state )); then rm -rf -- "$STATE" || clean=1; fi
    echo "STAGING_ROLLBACK_EXIT=$clean"
    (( clean == 0 )) || exit 4
  fi
  exit "$rc"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir -m 700 "$BASE"; made_base=1
mkdir -m 700 "$STATE"; made_state=1
install -m 600 /dev/null "$STATE/STOP"
git clone --no-checkout --single-branch --branch cv-auto-002-bridge github-casaviva:ernest196391/Casa-Viva.git "$BASE/source"
git -C "$BASE/source" checkout --detach "$REV"
[[ $(git -C "$BASE/source" rev-parse HEAD) == "$REV" ]]
[[ -z $(git -C "$BASE/source" status --porcelain --untracked-files=all) ]]
SRC="$BASE/source/scripts/ops"
systemd-analyze verify "$SRC/sandbox/cv-test-only-periodic.service" "$SRC/sandbox/cv-test-only-periodic.timer"
# Deliberately no credential or approved.json during staging.
install -m 644 "$SRC/sandbox/cv-test-only-periodic.service" "$SERVICE"; made_service=1
install -m 644 "$SRC/sandbox/cv-test-only-periodic.timer" "$TIMER"; made_timer=1
systemctl daemon-reload
for u in cv-test-only-periodic.service cv-test-only-periodic.timer; do
  [[ $(systemctl show "$u" -p ActiveState --value) == inactive ]] || exit 4
done
[[ ! -e "$TOKEN" && ! -e "$STATE/approved.json" && -f "$STATE/STOP" ]]
for u in cv-ops-bridge.service cv-dev-worker.service; do systemctl is-active --quiet "$u"; done
echo PERIODIC_STAGING_EXIT=0
