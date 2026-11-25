"""Fixed typed boundary for a candidate in the existing Dev Worker sandbox.

Pilot only: no production mailbox, network, credentials or privileged actions.
The trusted caller stages the reviewed candidate in a disposable workspace.
Candidate output is advisory, never release approval or a host command.
"""
import json
import hashlib
import os
import stat
import tempfile
import contextlib
import re
from pathlib import Path

MAX_INPUT=2048
MAX_OUTPUT=2048
OPERATIONS=frozenset({'health_summary'})
PROCESSES=frozenset({'vivabot','vivabot-recovery-receiver','nexofirma'})
STATUSES=frozenset({'online','stopped','unknown'})
SERVICES=frozenset({'active','inactive','failed','unknown'})

def request_bytes(operation, facts):
    if operation not in OPERATIONS or type(facts) is not dict or set(facts)!={'pm2','bridge'}:
        raise ValueError('invalid_request')
    pm=facts['pm2']
    if type(pm) is not dict or set(pm)!=PROCESSES:
        raise ValueError('invalid_processes')
    if any(type(v) is not str or v not in STATUSES for v in pm.values()):
        raise ValueError('invalid_status')
    if type(facts['bridge']) is not str or facts['bridge'] not in SERVICES:
        raise ValueError('invalid_service')
    return json.dumps({'operation':operation,'facts':facts},sort_keys=True,separators=(',',':')).encode()

def parse_response(raw):
    if type(raw) is not str or len(raw)>MAX_OUTPUT:
        raise ValueError('invalid_response')
    data=json.loads(raw)
    if type(data) is not dict or set(data)!={'status'} or type(data['status']) is not str or data['status'] not in {'healthy','degraded','unknown'}:
        raise ValueError('invalid_response')
    return {'status':data['status'],'advisory_only':True}

def evaluate(workspace, facts, *, uid, gid, sandbox):
    """Use existing sandbox API; no arbitrary commands or candidate-selected paths."""
    try:
        if type(uid) is not int or type(gid) is not int or uid<=0 or gid<=0:
            return {'ok':False,'reason':'unprivileged_identity_required'}
        payload=request_bytes('health_summary',facts)
        if len(payload)>MAX_INPUT:
            return {'ok':False,'reason':'invalid_request'}
        workspace=Path(workspace)
        # Caller owns staging and must verify the exact candidate hash before use.
        # No host data beyond the enumerated synthetic facts is passed to the child.
        rc,out=sandbox.run_in_box(
            ['/usr/bin/python3','-I','-B','candidate.py',payload.decode('ascii')],
            workspace,10,uid=uid,gid=gid)
        if rc!=0:
            return {'ok':False,'reason':'candidate_failed'}
        return {'ok':True,'result':parse_response(out)}
    except (OSError,ValueError,TypeError,RuntimeError):
        return {'ok':False,'reason':'candidate_unavailable'}

@contextlib.contextmanager
def stage_candidate(source, expected_digest, *, uid, gid, work_root=Path('/var/lib/control-os-dev/work')):
    """Stage exact reviewed bytes; never import candidate or follow source links.

    The root-owned workspace is not writable by the candidate identity. No
    secrets, Git metadata or other files are copied. Cleanup is limited to
    the one directory created here; unexpected contents fail closed.
    """
    if type(uid) is not int or type(gid) is not int or uid<=0 or gid<=0:
        raise ValueError('invalid_identity')
    if not isinstance(expected_digest,str) or not re.fullmatch(r'[0-9a-f]{64}',expected_digest):
        raise ValueError('invalid_digest')
    root=Path(work_root)
    for parent in (root,*root.parents):
        st=parent.lstat()
        if not stat.S_ISDIR(st.st_mode) or st.st_uid!=os.geteuid() or st.st_mode & 0o022:
            raise ValueError('unsafe_workspace_root')
    fd=os.open(source,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    with os.fdopen(fd,'rb') as f:
        st=os.fstat(f.fileno())
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=os.geteuid() or st.st_mode & 0o022 or st.st_nlink!=1:
            raise ValueError('unsafe_source')
        body=f.read(65537)
    if not body or len(body)>65536 or hashlib.sha256(body).hexdigest()!=expected_digest:
        raise ValueError('artifact_mismatch')
    compile(body,'candidate.py','exec')
    work=Path(tempfile.mkdtemp(prefix='run-release-',dir=root))
    target=work/'candidate.py'
    try:
        fd=os.open(target,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,'wb') as f:
            f.write(body)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(target,0o444)
        os.chmod(work,0o555)
        yield work
    finally:
        os.chmod(work,0o700)
        target.unlink(missing_ok=True)
        work.rmdir()
