"""Independent rollback decision for the fixed contingency module.
No mailbox action, shell execution, service restart or arbitrary path.
A locally installed supervisor must supply independently observed health.
"""
import hashlib
import re
from pathlib import Path
from github_release_gate import read_private, atomic_file

ROOT=Path('/root/supervisor')
TARGET=ROOT/'github_contingency.py'
HEX=re.compile(r'[0-9a-f]{64}')

def rollback_candidate(previous, candidate, healthy, *, root=ROOT):
    if not isinstance(previous,str) or not HEX.fullmatch(previous) or not isinstance(candidate,str) or not HEX.fullmatch(candidate) or previous==candidate:
        return {'ok':False,'reason':'invalid_digest'}
    if healthy is not False:
        return {'ok':False,'reason':'failure_not_confirmed'}
    target=root/'github_contingency.py'
    backup=root/('github_contingency.backup.'+previous)
    digest=lambda data:hashlib.sha256(data).hexdigest()
    try:
        current=read_private(target)
        if digest(current)!=candidate:
            return {'ok':False,'reason':'target_changed'}
        data=read_private(backup)
        if digest(data)!=previous:
            return {'ok':False,'reason':'backup_mismatch'}
        compile(data,str(target),'exec')
        if read_private(target)!=current:
            return {'ok':False,'reason':'target_changed'}
        atomic_file(target,data)
        return {'ok':digest(read_private(target))==previous,'restored':True,'restart_required':True}
    except (OSError,ValueError,SyntaxError):
        return {'ok':False,'reason':'rollback_unavailable'}
