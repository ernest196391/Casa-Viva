"""Durable fixed-target release transaction. No network or service control.

The locally installed, independently trusted supervisor owns the health probe.
This module does not interpret mailbox payloads or execute candidate code.
"""
import contextlib
import fcntl
import hashlib
import json
import os
import re
import stat
from pathlib import Path
from github_release_gate import read_private, atomic_file

ROOT = Path('/root/supervisor')
HEX = re.compile(r'[0-9a-f]{64}')
COMMIT = re.compile(r'[0-9a-f]{40}')
STATES = {'prepared','pending_health','rollback_pending','restored','confirmed','aborted'}
TERMINAL = {'restored','confirmed','aborted'}

def digest(data):
    return hashlib.sha256(data).hexdigest()

@contextlib.contextmanager
def release_lock(root=ROOT):
    fd=os.open(root/'.github-release.lock',os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW|os.O_NONBLOCK,0o600)
    try:
        st=os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=os.geteuid() or st.st_mode & 0o077 or st.st_nlink!=1:
            raise ValueError('unsafe_lock')
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        yield
    finally:
        os.close(fd)

def load_state(root=ROOT):
    try:
        data=json.loads(read_private(root/'.github-release-state.json',2048))
    except FileNotFoundError:
        return None
    if not isinstance(data,dict) or set(data)!={'sha','previous','candidate','state'}:
        raise ValueError('invalid_journal')
    if not isinstance(data['sha'],str) or not COMMIT.fullmatch(data['sha']):
        raise ValueError('invalid_commit')
    if any(not isinstance(data[k],str) or not HEX.fullmatch(data[k]) for k in ('previous','candidate')):
        raise ValueError('invalid_digest')
    if data['previous']==data['candidate'] or data['state'] not in STATES:
        raise ValueError('invalid_state')
    return data

def save_state(data,state,root=ROOT):
    if state not in STATES:
        raise ValueError('invalid_state')
    atomic_file(root/'.github-release-state.json',json.dumps(dict(data,state=state),sort_keys=True).encode())

def prepare(sha,previous,candidate,root=ROOT):
    """Caller must hold release_lock until the replacement and journal update."""
    if not COMMIT.fullmatch(sha) or previous==candidate:
        raise ValueError('invalid_transaction')
    old=load_state(root)
    if old and old['state'] not in TERMINAL:
        raise ValueError('release_pending')
    p,c=digest(previous),digest(candidate)
    if read_private(root/('github_contingency.backup.'+p))!=previous:
        raise ValueError('backup_unverified')
    if read_private(root/'github_contingency.py')!=previous:
        raise ValueError('target_changed')
    data={'sha':sha,'previous':p,'candidate':c,'state':'prepared'}
    save_state(data,'prepared',root)
    return data

def recover(observed_health=None,root=ROOT,*,expected=None):
    """Only the independent local supervisor may supply a health observation.

    None means unknown. A failed candidate restores the old file but leaves the
    transaction pending until a separately observed restart and health check.
    """
    try:
        with release_lock(root):
            data=load_state(root)
            if expected is not None and data != expected:
                return {'ok':False,'reason':'observation_stale'}
            if not data or data['state'] in TERMINAL:
                return {'ok':True,'state':'idle' if not data else data['state']}
            target=root/'github_contingency.py'
            current=digest(read_private(target))
            previous,candidate=data['previous'],data['candidate']
            if current not in (previous,candidate):
                return {'ok':False,'reason':'target_changed'}
            if data['state']=='prepared' and current==previous:
                save_state(data,'aborted',root)
                return {'ok':True,'state':'aborted'}
            if data['state']=='prepared' and current==candidate:
                save_state(data,'pending_health',root)
                # A health observation from before this transition is stale.
                return {'ok':False,'state':'pending_health','restart_required':True}
            if data['state']=='rollback_pending':
                if current==previous:
                    if observed_health is True:
                        save_state(data,'restored',root)
                        return {'ok':True,'state':'restored'}
                    return {'ok':False,'state':'rollback_pending','restart_required':True}
                # Interrupted rollback before replacement: retry restoration.
                observed_health=False
            elif current==previous:
                return {'ok':False,'reason':'unexpected_previous'}
            elif observed_health is True and data['state']=='pending_health':
                save_state(data,'confirmed',root)
                return {'ok':True,'state':'confirmed'}
            elif observed_health is not False:
                return {'ok':False,'state':'pending_health','restart_required':True}
            backup=read_private(root/('github_contingency.backup.'+previous))
            if digest(backup)!=previous:
                return {'ok':False,'reason':'backup_mismatch'}
            compile(backup,str(target),'exec')
            save_state(data,'rollback_pending',root)
            atomic_file(target,backup)
            if digest(read_private(target))!=previous:
                return {'ok':False,'reason':'restore_unverified'}
            return {'ok':False,'state':'rollback_pending','restart_required':True}
    except BlockingIOError:
        return {'ok':False,'reason':'release_busy'}
    except (OSError,ValueError,TypeError,SyntaxError):
        return {'ok':False,'reason':'recovery_unavailable'}
