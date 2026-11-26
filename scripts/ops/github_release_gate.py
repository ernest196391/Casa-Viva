"""Fixed-scope release updater; installed locally once, never self-updated.

The issue token cannot write the source repository. A separate read-only token
verifies exact main push checks. No shell, user-supplied paths or commands.
"""
import hashlib
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile

REPO = 'ernest196391/Casa-Viva'
CHECKS = {316960256, 336358124, 336470374}
ROOT = Path('/root/supervisor')
CHECKOUT = Path('/root/Casa-Viva')
TOKEN_FILE = ROOT / '.github-release.env'
TARGET = ROOT / 'github_contingency.py'
SOURCE = 'scripts/ops/github_contingency.py'


def read_private(path, limit=65536):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as f:
        st = os.fstat(f.fileno())
        if not stat.S_ISREG(st.st_mode) or st.st_uid != os.geteuid() or st.st_mode & 0o077:
            raise ValueError('insecure file')
        data = f.read(limit + 1)
        if len(data) > limit:
            raise ValueError('file too large')
        return data


def git(*args):
    p = subprocess.run(['/usr/bin/git', '-C', str(CHECKOUT), *args],
        env={'PATH': '/usr/bin:/bin', 'HOME': '/root', 'GIT_TERMINAL_PROMPT': '0'},
        capture_output=True, timeout=90, check=True)
    return p.stdout


def verified_checks(api, token, sha):
    # Exact push runs, not a PR preview or unrelated successful workflow.
    path = f'/repos/{REPO}/actions/runs?head_sha={sha}&event=push&branch=main&per_page=100'
    data = api(token, 'GET', path)
    rows = data.get('workflow_runs', [])
    if not isinstance(rows, list) or data.get('total_count', 101) > 100:
        return False
    for wid in CHECKS:
        relevant = [r for r in rows if r.get('workflow_id') == wid and r.get('head_sha') == sha and r.get('head_branch') == 'main' and r.get('event') == 'push']
        if not relevant:
            return False
        latest = max(relevant, key=lambda r: (r.get('run_number', 0), r.get('run_attempt', 0)))
        if latest.get('status') != 'completed' or latest.get('conclusion') != 'success':
            return False
    return True


def atomic_file(path, data):
    fd, name = tempfile.mkstemp(prefix='.cv-release-', dir=path.parent)
    temp = Path(name)
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.replace(temp, path)
        fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try: os.fsync(fd)
        finally: os.close(fd)
    finally:
        if temp.exists(): temp.unlink()


def provision_certified_artifact():
    """Fixed local operation, never execute the artifact or unlink its public name."""
    target = ROOT / 'cv-local-sql-certified.py'
    pin = '84bc0eb4a57a3dc5c4f1ea5377c50eb952446a3a'
    def valid(raw):
        return hashlib.sha1(b'blob ' + str(len(raw)).encode() + bytes([0]) + raw).hexdigest() == pin
    parent = ROOT.lstat()
    if os.geteuid() != 0 or not stat.S_ISDIR(parent.st_mode) or parent.st_uid != 0 or parent.st_mode & 0o022:
        return False
    try:
        raw = read_private(target, 262144)
    except FileNotFoundError:
        raw = read_private(ROOT / 'cv-ops-bridge.py.prev', 262144)
        if not valid(raw):
            return False
        fd, name = tempfile.mkstemp(prefix='.cv-sql-', dir=ROOT)
        try:
            with os.fdopen(fd, 'wb') as out:
                os.fchmod(out.fileno(), 0o600)
                out.write(raw)
                out.flush()
                os.fsync(out.fileno())
            try:
                os.link(name, target, follow_symlinks=False)
            except FileExistsError:
                pass
        finally:
            os.unlink(name)
        raw = read_private(target, 262144)
    if not valid(raw):
        return False
    fd = os.open(ROOT, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)
    return True


def apply_release(sha, api):
    if not isinstance(sha, str) or not re.fullmatch(r'[0-9a-f]{40}', sha):
        return {'ok': False, 'reason': 'invalid_sha'}
    try:
        raw = read_private(TOKEN_FILE).decode().strip()
        if not raw.startswith('CV_GITHUB_RELEASE_TOKEN=') or '\n' in raw:
            return {'ok': False, 'reason': 'release_config'}
        token = raw.split('=', 1)[1]
        if not token:
            return {'ok': False, 'reason': 'release_config'}
        git('fetch', 'origin', 'main')
        if git('rev-parse', 'origin/main').decode().strip() != sha:
            return {'ok': False, 'reason': 'not_current_main'}
        branch = api(token, 'GET', f'/repos/{REPO}/branches/main')
        if branch.get('protected') is not True or branch.get('commit', {}).get('sha') != sha:
            return {'ok': False, 'reason': 'main_not_protected'}
        if not verified_checks(api, token, sha):
            return {'ok': False, 'reason': 'ci_not_certified'}
        body = git('show', f'{sha}:{SOURCE}')
        if len(body) > 65536 or len(body) < 1000:
            return {'ok': False, 'reason': 'invalid_artifact'}
        compile(body, str(TARGET), 'exec')
        # Never execute candidate module top-level code in the release gate.
        # The locally installed gate owns the fixed provisioning operation.
        if b'def provision_local_sql_artifact():' in body:
            git('fetch', 'origin', 'main')
            if git('rev-parse', 'origin/main').decode().strip() != sha:
                return {'ok': False, 'reason': 'main_moved'}
            if not provision_certified_artifact():
                return {'ok': False, 'reason': 'artifact_provision_unconfirmed'}
        previous = read_private(TARGET)
        if previous == body:
            return {'ok': True, 'changed': False, 'sha': sha}
        # Preserve every distinct previous revision and avoid overwriting backups.
        backup = ROOT / ('github_contingency.backup.' + hashlib.sha256(previous).hexdigest())
        if backup.exists():
            if read_private(backup) != previous:
                return {'ok': False, 'reason': 'backup_conflict'}
        else:
            atomic_file(backup, previous)
        # Confirm main did not move while checking. No service or policy changes.
        git('fetch', 'origin', 'main')
        if git('rev-parse', 'origin/main').decode().strip() != sha:
            return {'ok': False, 'reason': 'main_moved'}
        atomic_file(TARGET, body)
        return {'ok': True, 'changed': True, 'sha': sha}
    except (OSError, ValueError, TypeError, KeyError, AttributeError, SyntaxError, subprocess.SubprocessError):
        return {'ok': False, 'reason': 'release_unavailable'}
