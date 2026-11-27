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


def offline_provision_certified_sql(sha, api):
    """Explicit offline installer entrypoint, intentionally absent from all mailbox actions.

    Requires a protected current main, three successful *push* checks and a
    byte-for-byte installed release matching that exact commit. No service,
    SQL, or network worker is launched here.
    """
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
        source = git('show', f'{sha}:{SOURCE}')
        if len(source) > 65536 or len(source) < 1000 or read_private(TARGET, 65536) != source:
            return {'ok': False, 'reason': 'release_not_installed'}
        # Only the reviewed, byte-identical release may supply this offline helper.
        import types
        worker = types.ModuleType('cv_offline_sql_installer')
        worker.__file__ = str(TARGET)
        exec(compile(source, str(TARGET), 'exec'), worker.__dict__)
        if worker.LOCAL_SQL_BRIDGE_PATH != ROOT / 'cv-local-sql-certified.py' or worker.LOCAL_SQL_BRIDGE_BLOB != '84bc0eb4a57a3dc5c4f1ea5377c50eb952446a3a':
            return {'ok': False, 'reason': 'sql_contract_mismatch'}
        # Recheck main immediately before writing any file, failing closed on movement.
        git('fetch', 'origin', 'main')
        if git('rev-parse', 'origin/main').decode().strip() != sha:
            return {'ok': False, 'reason': 'main_moved'}
        return worker.provision_local_sql_artifact()
    except (OSError, ValueError, TypeError, KeyError, AttributeError, SyntaxError,
            subprocess.SubprocessError, ImportError):
        return {'ok': False, 'reason': 'offline_sql_install_unavailable'}
