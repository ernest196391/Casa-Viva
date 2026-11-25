"""Fixed typed boundary for a candidate in the existing Dev Worker sandbox.

Pilot only: no production mailbox, network, credentials or privileged actions.
The trusted caller stages the reviewed candidate in a disposable workspace.
Candidate output is advisory, never release approval or a host command.
"""
import json
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
