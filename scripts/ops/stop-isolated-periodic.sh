#!/usr/bin/env bash
# Local operator emergency stop: never targets production units.
set -Eeuo pipefail
umask 077
[[ $EUID == 0 ]] || exit 2
ROOT=/var/lib/cv-test-only-periodic
[[ -d "$ROOT" && ! -L "$ROOT" && $(stat -c '%u:%a' "$ROOT") == 0:700 ]] || exit 2
/usr/bin/python3.12 -I -B - "$ROOT" <<'PY'
import os,sys
from pathlib import Path
p=Path(sys.argv[1])
fd=os.open(p/"STOP",os.O_WRONLY|os.O_CREAT|os.O_NOFOLLOW|os.O_NONBLOCK,0o600)
try:os.fsync(fd)
finally:os.close(fd)
fd=os.open(p,os.O_RDONLY|os.O_DIRECTORY)
try:os.fsync(fd)
finally:os.close(fd)
PY
systemctl stop cv-test-only-periodic.timer
systemctl stop cv-test-only-periodic.service
# Separate root invocation performs recovery under the global lock.
# STOP prevents discovery and execution, but does not prevent cleanup.
/usr/bin/python3.12 -I -B /opt/cv-test-only-periodic/source/scripts/ops/github_test_only_periodic_recover.py
echo PERIODIC_STOP_CONFIRMED=1
