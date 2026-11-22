"""Run the fixed synthetic receiver classifier in a transient Linux sandbox."""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys

SOURCE = Path('/root/backups/nexo-recovery/receiver/recovery-replies.mjs')
PROBE = Path(__file__).with_name('receiver-classification-probe.mjs')
LIMIT = 262144
CASES = {'saludo', 'ventiladores'}
OUTCOMES = {'detected', 'none', 'unsupported'}


def read_private(path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as handle:
        info = os.fstat(handle.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
            raise ValueError('not a private regular file')
        data = handle.read(LIMIT + 1)
        if not data or len(data) > LIMIT:
            raise ValueError('invalid size')
        return data


def run(expected):
    if not re.fullmatch(r'[0-9a-f]{64}', expected):
        return {'ok': False, 'reason': 'invalid_hash'}
    try:
        source = read_private(SOURCE)
        harness = read_private(PROBE).decode('utf-8')
    except (OSError, ValueError):
        return {'ok': False, 'reason': 'private_input_unavailable'}
    if hashlib.sha256(source).hexdigest() != expected:
        return {'ok': False, 'reason': 'source_changed'}

    # Stdin is the only module input. The transient service cannot read the
    # production checkout or initiate network connections.
    try:
        child = subprocess.run([
            '/usr/bin/systemd-run', '--quiet', '--wait', '--pipe', '--collect',
            '--property=DynamicUser=yes', '--property=PrivateNetwork=yes',
            '--property=IPAddressDeny=any', '--property=RestrictAddressFamilies=AF_UNIX',
            '--property=ProtectSystem=strict', '--property=ProtectHome=yes',
            '--property=PrivateTmp=yes', '--property=PrivateDevices=yes',
            '--property=NoNewPrivileges=yes', '--property=InaccessiblePaths=/run',
            '--property=RuntimeMaxSec=5s', '--property=TimeoutStopSec=1s',
            '--property=MemoryMax=128M', '--property=TasksMax=24',
            '/usr/bin/node', '--expose-internals', '--input-type=module', '--eval',
            harness + '\nconsole.log(JSON.stringify(probe(readFileSync(0, "utf8"))));',
        ], input=source, capture_output=True, timeout=12, check=False,
           env={'PATH': '/usr/bin:/bin'})
        if child.returncode or len(child.stdout) > 2048:
            raise ValueError('isolated process did not report')
        result = json.loads(child.stdout)
        outcomes = result['classification']
        if (result['sha256'] != expected or set(outcomes) != CASES or
                any(not isinstance(value, str) or value not in OUTCOMES for value in outcomes.values())):
            raise ValueError('invalid result')
        return {'ok': True, 'sha256': expected, 'classification': outcomes,
                'catalog': 'not_tested', 'reply': 'not_tested'}
    except (OSError, ValueError, TypeError, KeyError, AttributeError, subprocess.SubprocessError):
        return {'ok': False, 'reason': 'isolated_probe_unavailable'}


if __name__ == '__main__':
    print(json.dumps(run(sys.argv[1] if len(sys.argv) == 2 else '')))
