"""Trusted projection of the existing contingency health summary.

No side effects. The existing mailbox response remains unchanged.
The isolated classification is advisory only, not release health evidence.
"""
PROCESSES = frozenset({'vivabot','vivabot-recovery-receiver','nexofirma'})
PM2_MAP = {'online':'online','stopped':'stopped','errored':'stopped',
           'launching':'unknown','stopping':'unknown','unknown':'unknown'}
BRIDGE_MAP = {'active':'active','inactive':'inactive','failed':'failed','unknown':'unknown'}

def project_summary(summary):
    """Only enumerated, non-sensitive facts cross the candidate boundary."""
    if type(summary) is not dict or set(summary) != {'pm2','bridge'}:
        raise ValueError('invalid_summary')
    pm=summary['pm2']
    if type(pm) is not dict or set(pm)!=PROCESSES:
        raise ValueError('invalid_processes')
    facts={}
    for name,row in pm.items():
        if type(row) is not dict or set(row)!={'status'} or type(row['status']) is not str:
            raise ValueError('invalid_status')
        facts[name]=PM2_MAP.get(row['status'],'unknown')
    if type(summary['bridge']) is not str:
        raise ValueError('invalid_bridge')
    return {'pm2':facts,'bridge':BRIDGE_MAP.get(summary['bridge'],'unknown')}

def advisory_reference(facts):
    """Trusted expected classification, independent of isolated implementation."""
    from github_release_candidate_boundary import request_bytes
    request_bytes('health_summary',facts)
    values=tuple(facts['pm2'].values())
    if 'unknown' in values or facts['bridge']=='unknown':
        return 'unknown'
    if facts['bridge']=='active' and all(v=='online' for v in values):
        return 'healthy'
    return 'degraded'
