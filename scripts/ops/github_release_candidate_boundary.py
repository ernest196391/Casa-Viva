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

def _evaluate(workspace, facts, *, uid, gid, sandbox):
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
        if not stat.S_ISDIR(st.st_mode) or st.st_uid not in (0,os.geteuid()) or st.st_mode & 0o022:
            raise ValueError('unsafe_workspace_root')
    fd=os.open(source,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK)
    with os.fdopen(fd,'rb') as f:
        st=os.fstat(f.fileno())
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=os.geteuid() or st.st_mode & 0o022 or st.st_nlink!=1:
            raise ValueError('unsafe_source')
        body=f.read(65537)
    if not body or len(body)>65536 or hashlib.sha256(body).hexdigest()!=expected_digest:
        raise ValueError('artifact_mismatch')
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

def evaluate(source, facts, *, expected_digest, uid, gid, sandbox,
             work_root=Path('/var/lib/control-os-dev/work')):
    """Public boundary remains closed until a mandatory pre-execution confinement is certified."""
    return {'ok':False,'reason':'candidate_execution_boundary_not_certified'}

def _evaluate_staged(source, facts, *, expected_digest, uid, gid, sandbox,
                     work_root=Path('/var/lib/control-os-dev/work')):
    """Internal staging primitive for controlled tests; not a production entrypoint."""
    try:
        if type(uid) is not int or type(gid) is not int or uid<=0 or gid<=0:
            return {'ok':False,'reason':'unprivileged_identity_required'}
        if uid==os.geteuid():
            return {'ok':False,'reason':'distinct_identity_required'}
        request_bytes('health_summary',facts)
        with stage_candidate(source,expected_digest,uid=uid,gid=gid,work_root=work_root) as workspace:
            return _evaluate(workspace,facts,uid=uid,gid=gid,sandbox=sandbox)
    except (OSError,ValueError,TypeError,RuntimeError,SyntaxError):
        return {'ok':False,'reason':'candidate_unavailable'}

# Trusted interpreter prefix. The candidate is not opened, imported or compiled
# by this interpreter until the mandatory confinement checks have passed.
# Only a separately certified launcher may invoke this command.
PREFLIGHT_PREFIX = r'''
import os, sys
with open("/proc/self/attr/current", "w") as f:
    f.write("stack cv-release-pilot-candidate")
with open("/proc/self/attr/current") as f:
    if f.read().strip() != "cv-release-pilot-bootstrap//&cv-release-pilot-candidate (enforce)":
        raise SystemExit(126)
with open("/proc/self/status") as f:
    fields = dict(line.split(":", 1) for line in f if ":" in line)
if os.getuid() == 0 or os.geteuid() == 0 or fields["NoNewPrivs"].strip() != "1":
    raise SystemExit(126)
if any(int(fields[k].strip(), 16) for k in ("CapEff","CapPrm","CapInh","CapAmb","CapBnd")):
    raise SystemExit(126)
import hashlib
expected, payload = sys.argv[1:]
with open("candidate.py", "rb") as f:
    body = f.read(65537)
if not body or len(body) > 65536 or hashlib.sha256(body).hexdigest() != expected:
    raise SystemExit(126)
# Fixed synthetic fixture only: emit a pre-execution measurement from the
# trusted prefix, not from the fixture. This is not a production receipt.
import json
if expected == "ad56c87fba9c5ccb77906f2ab143b7ca1fe53ca0b14beffbfac17e4e76ac777e":
    print(json.dumps({"pilot_measurement":{"digest":hashlib.sha256(body).hexdigest(),
        "uid":os.geteuid(),"pid":os.getpid(),"label":"cv-release-pilot-bootstrap//&cv-release-pilot-candidate (enforce)",
        "nnp":True,"caps_zero":True}},sort_keys=True),flush=True)
sys.argv = ["candidate.py", payload]
exec(compile(body, "candidate.py", "exec"), {"__name__":"__main__", "__file__":"candidate.py"})
'''

def confined_command(expected_digest, facts):
    """Fixed command for supervised certification; never a host execution API."""
    if not isinstance(expected_digest,str) or not re.fullmatch(r'[0-9a-f]{64}',expected_digest):
        raise ValueError('invalid_digest')
    payload=request_bytes('health_summary',facts)
    return ['/usr/bin/python3.12','-I','-S','-B','-c',PREFLIGHT_PREFIX,
            expected_digest,payload.decode('ascii')]

# This exact harmless fixture is the only artifact admitted by the pilot API.
SYNTHETIC_SOURCE = b"""import json, sys
facts = json.loads(sys.argv[1])["facts"]
values = list(facts["pm2"].values())
status = "unknown" if "unknown" in values or facts["bridge"] == "unknown" else (
    "healthy" if all(v == "online" for v in values) and facts["bridge"] == "active" else "degraded")
print(json.dumps({"status":status}))
"""

def parse_measured_fixture(raw, digest, uid):
    """Only accept the fixed fixture's two-line pilot transcript.

    The trusted prefix measured bytes before execution. The stream is shared
    with the fixture, so this is NOT attestation for arbitrary candidate code.
    PID is namespace-local, not a host process epoch.
    """
    if type(raw) is not str or len(raw)>MAX_OUTPUT:
        raise ValueError('invalid_pilot_transcript')
    lines=raw.splitlines()
    if len(lines)!=2:
        raise ValueError('invalid_pilot_transcript')
    envelope=json.loads(lines[0])
    if type(envelope) is not dict or set(envelope)!={'pilot_measurement'}:
        raise ValueError('missing_measurement')
    m=envelope['pilot_measurement']
    if (type(m) is not dict or set(m)!={'digest','uid','pid','label','nnp','caps_zero'}
        or digest!=hashlib.sha256(SYNTHETIC_SOURCE).hexdigest()
        or m['digest']!=digest or type(m['uid']) is not int or m['uid']!=uid or uid<=0
        or type(m['pid']) is not int or m['pid']<=0
        or m['label']!='cv-release-pilot-bootstrap//&cv-release-pilot-candidate (enforce)'
        or m['nnp'] is not True or m['caps_zero'] is not True):
        raise ValueError('invalid_measurement')
    return parse_response(lines[1]),m


def run_synthetic_pilot(*, sandbox, uid, gid, work_root=Path('/var/lib/control-os-dev/work')):
    """Run only the fixed fixture; never issue production health receipts."""
    from github_release_health_emitter import measure_synthetic_contract
    import time
    if os.geteuid() != 0 or type(uid) is not int or type(gid) is not int or uid<=0 or gid<=0:
        return {'ok':False,'scope':'synthetic_only','reason':'identity_required'}
    digest = hashlib.sha256(SYNTHETIC_SOURCE).hexdigest()
    epoch = (os.getpid(), time.monotonic_ns())
    try:
        with tempfile.TemporaryDirectory(prefix='cv-release-fixture-') as temp:
            source=Path(temp)/'candidate.py'
            source.write_bytes(SYNTHETIC_SOURCE)
            source.chmod(0o600)
            with stage_candidate(source,digest,uid=uid,gid=gid,work_root=work_root) as work:
                def artifact_digest():
                    return hashlib.sha256((work/'candidate.py').read_bytes()).hexdigest()
                def identity():
                    return epoch if os.getpid()==epoch[0] else None
                measurements=[]
                def run_case(facts):
                    rc,out=sandbox.run_release_pilot(work,facts,digest,uid=uid,gid=gid)
                    if rc != 0:
                        print('PILOT_CHILD_EXIT='+str(rc), file=__import__('sys').stderr)
                        return None
                    result,measurement=parse_measured_fixture(out,digest,uid)
                    measurements.append(measurement)
                    return result
                outcome=measure_synthetic_contract(digest,'sha256:'+digest,epoch,
                    identity=identity,artifact_digest=artifact_digest,run_case=run_case)
        return {'ok':outcome is True,'scope':'synthetic_only','functional_ok':outcome,
                'digest':digest,'production_authorized':False,'measurements':measurements}
    except (OSError,ValueError,TypeError,RuntimeError):
        return {'ok':False,'scope':'synthetic_only','functional_ok':None,'production_authorized':False}

def main(argv):
    """Explicit, fixed fixture entrypoint; never accepts source or commands."""
    if argv != ['--synthetic-pilot']:
        return 2
    import importlib.util
    import pwd
    import sys
    try:
        # Check actual inherited constraints before the root harness spawns cvdev.
        lines=Path('/proc/self/cgroup').read_text().splitlines()
        if len(lines)!=1 or not re.fullmatch(r'0::/system.slice/cv-release-pilot-[0-9]+-[0-9]+\.service',lines[0]):
            raise ValueError('dedicated_cgroup_required')
        cg=Path('/sys/fs/cgroup') / lines[0][3:].lstrip('/')
        memory=int((cg/'memory.max').read_text())
        tasks=int((cg/'pids.max').read_text())
        quota,period=map(int,(cg/'cpu.max').read_text().split())
        if not (0<memory<=268435456 and 0<tasks<=32 and period>0 and 0<quota<=period/2):
            raise ValueError('resource_limits_required')
        fields=dict(line.split(':',1) for line in Path('/proc/self/status').read_text().splitlines() if ':' in line)
        if fields['NoNewPrivs'].strip()!='1':
            raise ValueError('nnp_required')
        sys.path.insert(0,str(Path(__file__).resolve().parent))
        spec=importlib.util.spec_from_file_location('cv_dev_sandbox',Path(__file__).with_name('dev-sandbox.py'))
        sandbox=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(sandbox)
        account=pwd.getpwnam('cvdev')
        report=run_synthetic_pilot(sandbox=sandbox,uid=account.pw_uid,gid=account.pw_gid)
    except (OSError,ValueError,KeyError,RuntimeError,ImportError):
        report={'ok':False,'scope':'synthetic_only','reason':'pilot_preconditions_failed','production_authorized':False}
    print(json.dumps(report,sort_keys=True))
    return 0 if report['ok'] else 4

if __name__=='__main__':
    import sys
    sys.exit(main(sys.argv[1:]))
