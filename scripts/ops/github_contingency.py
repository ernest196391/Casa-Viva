"""Read-only GitHub contingency transport for Casa Viva Ops Bridge.

No arbitrary commands, credentials in results, or production mutations.
Call only under the existing bridge lock; opt in locally after review.
"""
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import time
import urllib.request

REPO = 'ernest196391/casa-viva-centro-control'
OWNER = 'ernest196391'
PREFIX = 'CV-CONTINGENCY-V1 '
STATES = {'online', 'stopped', 'errored', 'launching', 'stopping', 'active', 'inactive', 'failed', 'unknown'}
PROCESSES = {'vivabot', 'vivabot-recovery-receiver', 'nexofirma'}


def config(env):
    if env.get('CV_GITHUB_CONTINGENCY_ENABLED') != '1':
        return None
    issue = env.get('CV_GITHUB_CONTINGENCY_ISSUE', '')
    token = env.get('CV_GITHUB_CONTINGENCY_TOKEN', '')
    if not re.fullmatch(r'[1-9][0-9]{0,8}', issue) or not token:
        return None
    return int(issue), token


def timestamp(value):
    if not isinstance(value, str) or not value.endswith('Z'):
        raise ValueError('invalid timestamp')
    return dt.datetime.fromisoformat(value[:-1] + '+00:00').timestamp()


def command(comment, now):
    """Strict owner-authored, unedited, fresh command, no parameters."""
    try:
        if comment.get('user', {}).get('login') != OWNER or comment['user'].get('type') != 'User' or comment.get('author_association') != 'OWNER':
            return None
        if comment['created_at'] != comment['updated_at'] or not isinstance(comment['id'], int) or comment['id'] <= 0:
            return None
        body = comment['body']
        if not isinstance(body, str) or not body.startswith(PREFIX) or len(body) > 400:
            return None
        data = json.loads(body[len(PREFIX):])
        if set(data) != {'action', 'nonce', 'expires_at'} or data['action'] != 'health' or not isinstance(data['nonce'], str) or not re.fullmatch(r'[A-Za-z0-9_-]{16,64}', data['nonce']):
            return None
        created, expires = timestamp(comment['created_at']), timestamp(data['expires_at'])
        if not created <= now < expires <= created + 1800:
            return None
        return data
    except (KeyError, TypeError, ValueError, AttributeError):
        return None


def private_dir(path):
    path.mkdir(mode=0o700, parents=True, exist_ok=True)
    if path.is_symlink() or path.stat().st_uid != os.geteuid() or path.stat().st_mode & 0o077:
        raise ValueError('insecure contingency directory')
    return path


def atomic_state(path, value):
    temp = path.with_suffix('.new')
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as f:
        json.dump(value, f)
        f.flush()
        os.fsync(f.fileno())
    os.replace(temp, path)
    fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def reserve(directory, nonce, comment_id):
    path = directory / ('nonce-' + hashlib.sha256(nonce.encode()).hexdigest())
    try:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    except FileExistsError:
        return False
    with os.fdopen(fd, 'w') as f:
        json.dump({'comment_id': comment_id}, f)
        f.flush()
        os.fsync(f.fileno())
    fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)
    return True


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def github_api(token, method, path, body=None):
    # Call paths are generated internally; the token is never printed or forwarded.
    req = urllib.request.Request('https://api.github.com' + path,
        data=None if body is None else json.dumps(body).encode(), method=method,
        headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/vnd.github+json',
                 'Content-Type': 'application/json', 'User-Agent': 'CasaViva-contingency-v1'})
    with urllib.request.build_opener(NoRedirect()).open(req, timeout=12) as response:
        return json.loads(response.read(1_000_001))


def health_summary(pm2_result, service_status):
    """No raw output, env, identifiers of customers, or exceptions leave the VPS."""
    processes = {}
    try:
        if pm2_result.get('returncode') == 0:
            for row in json.loads(pm2_result.get('output', '')):
                name, status = row.get('name'), row.get('status')
                if name in PROCESSES:
                    processes[name] = {'status': status if status in STATES else 'unknown'}
    except (ValueError, TypeError, AttributeError):
        processes = {}
    active = service_status if service_status in STATES else 'unknown'
    return {'pm2': {name: processes.get(name, {'status': 'unknown'}) for name in sorted(PROCESSES)}, 'bridge': active}


class Contingency:
    def __init__(self, env, health, directory=Path('/var/lib/cv-ops-bridge-contingency'), api=github_api, clock=time.time):
        self.cfg = config(env)
        self.health, self.directory, self.api, self.clock = health, Path(directory), api, clock

    def state(self):
        path = private_dir(self.directory) / 'state.json'
        if not path.exists():
            return {}
        if path.is_symlink():
            raise ValueError('invalid state')
        return json.loads(path.read_text())

    def active(self):
        return bool(self.cfg and self.state().get('remote_retry_at', 0) > self.clock())

    def activate(self):
        if not self.cfg:
            return False
        state = self.state()
        state['remote_retry_at'] = self.clock() + 300
        atomic_state(self.directory / 'state.json', state)
        return True

    def poll(self):
        if not self.cfg:
            return 'disabled'
        now = self.clock()
        state = self.state()
        if state.get('next_poll_at', 0) > now:
            return 'cooldown'
        # Throttle prior to network access, including failed API calls.
        state['next_poll_at'] = now + 60
        atomic_state(self.directory / 'state.json', state)
        issue, token = self.cfg
        root = '/repos/' + REPO
        repo = self.api(token, 'GET', root)
        if repo.get('private') is not True or repo.get('full_name') != REPO:
            return 'blocked'
        target = self.api(token, 'GET', f'{root}/issues/{issue}')
        if target.get('state') != 'open' or target.get('title') != 'CV contingency — diagnostic mailbox' or 'pull_request' in target:
            return 'blocked'
        since = dt.datetime.fromtimestamp(now - 1800, dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
        path = f'{root}/issues/{issue}/comments'
        comments = self.api(token, 'GET', f'{path}?per_page=100&since={since}')
        if not isinstance(comments, list) or len(comments) >= 100:
            return 'blocked'
        for comment in comments:
            data = command(comment, now)
            if not data or not reserve(self.directory, data['nonce'], comment['id']):
                continue
            # One fixed read-only diagnostic. No retry if post outcome is uncertain.
            summary = self.health()
            safe = health_summary(summary[0], summary[1])
            report = {'action': 'health', 'request_id': comment['id'], 'result': safe}
            self.api(token, 'POST', path, {'body': 'CV-CONTINGENCY-RESULT-V1 ' + json.dumps(report, ensure_ascii=False, separators=(',', ':'))})
            return 'reported'
        return 'idle'
