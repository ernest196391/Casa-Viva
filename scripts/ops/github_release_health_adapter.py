"""Read-only systemd process identity adapter for the fixed Casa Viva bridge.

No arbitrary unit names, shell, secrets, or production mutation. The bridge
may be a short-lived job, so absence of MainPID is UNKNOWN, not failure.
This adapter deliberately cannot certify functional health by itself.
"""
import subprocess

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

def health_probe(expected_digest, expected_commit, epoch):
    # A process epoch is not proof that the module was loaded or that the
    # contingency path succeeded. Require a separately certified functional
    # observation before returning either True or False.
    return None
