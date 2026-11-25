"""Read-only systemd process identity adapter for the fixed Casa Viva bridge.

No arbitrary unit names, shell, secrets, or production mutation. The bridge
may be a short-lived job, so absence of MainPID is UNKNOWN, not failure.
This adapter deliberately cannot certify functional health by itself.
"""
import subprocess
import hashlib
import hmac
import json
import secrets
import re
import time

UNIT = 'cv-ops-bridge.service'
PROPERTIES = ('MainPID', 'ExecMainStartTimestampMonotonic', 'InvocationID', 'Type', 'ActiveState', 'Result')

def _properties():
    try:
        p = subprocess.run(
            ['/usr/bin/systemctl', 'show', '--no-pager',
             '--property=' + ','.join(PROPERTIES), UNIT],
            env={'PATH': '/usr/bin:/bin', 'LANG': 'C'},
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            timeout=5, check=True)
        if len(p.stdout) > 4096:
            return None
        fields = dict(line.split('=', 1) for line in p.stdout.decode('ascii').splitlines())
        return fields
    except (OSError, ValueError, KeyError, subprocess.SubprocessError):
        return None

def service_type():
    fields = _properties()
    value = fields.get('Type') if fields else None
    return value if value in {'simple','exec','forking','oneshot','notify','notify-reload','dbus','idle'} else None

def process_identity():
    try:
        fields = _properties()
        if fields is None:
            return None
        pid = int(fields['MainPID'])
        started = int(fields['ExecMainStartTimestampMonotonic'])
        if pid <= 0 or started <= 0 or not fields['InvocationID']:
            return None
        # systemd reports microseconds, supervisor contract uses nanoseconds.
        return pid, started * 1000
    except (OSError, ValueError, KeyError, subprocess.SubprocessError):
        return None

def validate_observation(report, expected_digest, expected_commit, epoch, nonce, *, now_ns=None):
    """Validate evidence from a trusted external observer, never candidate output.

    The caller must authenticate the observer transport separately. This
    validator does not make an unauthenticated receipt trustworthy.
    """
    if not isinstance(report, dict) or set(report) != {
        'digest','commit','epoch','nonce','observed_ns','functional_ok'
    }:
        return None
    if not isinstance(expected_digest,str) or not re.fullmatch(r'[0-9a-f]{64}',expected_digest):
        return None
    if not isinstance(expected_commit,str) or not (re.fullmatch(r'[0-9a-f]{40}',expected_commit) or expected_commit == 'sha256:' + expected_digest):
        return None
    if not isinstance(nonce,str) or not re.fullmatch(r'[A-Za-z0-9_-]{16,64}',nonce):
        return None
    if not isinstance(epoch,tuple) or len(epoch)!=2 or any(type(v) is not int or v<=0 for v in epoch):
        return None
    if (report['digest'],report['commit'],report['epoch'],report['nonce']) != (
        expected_digest,expected_commit,list(epoch),nonce
    ):
        return None
    if not isinstance(report['epoch'],list) or any(type(v) is not int for v in report['epoch']):
        return None
    observed=report['observed_ns']
    now=time.monotonic_ns() if now_ns is None else now_ns
    if type(observed) is not int or not epoch[1] <= observed <= now or now-observed > 30_000_000_000:
        return None
    return report['functional_ok'] if type(report['functional_ok']) is bool else None

def authenticated_observer_probe(observer, key, expected_digest, expected_commit, epoch, *, now_ns=None):
    """Challenge a separately installed trusted observer and verify its receipt.

    The observer callback is a local dependency, not a mailbox input. Its
    transport must enforce bounded execution and isolate the signing key from
    the candidate. No production observer is wired by this module.
    """
    if not isinstance(key, bytes) or len(key) != 32:
        return None
    nonce = secrets.token_hex(24)
    try:
        envelope = observer(expected_digest, expected_commit, epoch, nonce)
        if not isinstance(envelope, dict) or set(envelope) != {'report','mac'}:
            return None
        report, mac = envelope['report'], envelope['mac']
        if not isinstance(report, dict) or not isinstance(mac, str) or not re.fullmatch(r'[0-9a-f]{64}',mac):
            return None
        payload = json.dumps(report, sort_keys=True, separators=(',',':'), allow_nan=False).encode('utf-8')
        if len(payload)>2048:
            return None
        expected_mac = hmac.new(key, b'CV-RELEASE-HEALTH-V1\\x00'+payload, hashlib.sha256).hexdigest()
        if not hmac.compare_digest(mac,expected_mac):
            return None
        return validate_observation(report,expected_digest,expected_commit,epoch,nonce,now_ns=now_ns)
    except (OSError,ValueError,TypeError,OverflowError,subprocess.SubprocessError):
        return None

def health_probe(expected_digest, expected_commit, epoch):
    # A process epoch is not proof that the module was loaded or that the
    # contingency path succeeded. Require a separately certified functional
    # observation before returning either True or False.
    return None
