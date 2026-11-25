"""Local, fixed, read-only collection for isolated advisory observation.
No mailbox, credentials, customer data, or service mutations.
"""
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess

from github_contingency import health_summary, PROCESSES

PM2_HOME = Path('/root/.pm2')
PATH = '/usr/local/bin:/usr/bin:/bin'

def daemon_present():
    """Avoid PM2 CLI auto-start if the existing daemon is not available."""
    try:
        p = PM2_HOME/'rpc.sock'
        s = p.lstat()
        pid_file = PM2_HOME/'pm2.pid'
        t = pid_file.lstat()
        if not stat.S_ISSOCK(s.st_mode) or s.st_uid != 0 or not stat.S_ISREG(t.st_mode) or t.st_uid != 0:
            return False
        pid = int(pid_file.read_text().strip())
        if pid <= 1:
            return False
        return Path('/proc',str(pid)).stat().st_uid == 0 and Path('/proc',str(pid),'stat').exists()
    except (OSError,ValueError):
        return False

# Connect only to the existing PM2 RPC socket. Never import PM2's CLI/API,
# which can start a daemon. Resolve dependencies from the installed package.
RPC = r"""
const path = require('path');
const root = process.argv[1];
const axon = require(require.resolve('pm2-axon', {paths:[root]}));
const rpc = require(require.resolve('pm2-axon-rpc', {paths:[root]}));
const socket = axon.socket('req');
const client = new rpc.Client(socket);
const names = new Set(['vivabot','vivabot-recovery-receiver','nexofirma']);
const timer = setTimeout(() => process.exit(22), 4000);
socket.on('error', () => process.exit(21));
socket.connect('/root/.pm2/rpc.sock');
client.call('getMonitorData', {}, (err, rows) => {
  if (err) process.exit(23);
  if (!Array.isArray(rows)) process.exit(24);
  const safe = rows.filter(r => r && names.has(r.name)).map(r => ({
    name:r.name, status:typeof r.pm2_env?.status === 'string' ? r.pm2_env.status : 'unknown'
  }));
  clearTimeout(timer);
  process.stdout.write(JSON.stringify(safe), () => process.exit(0));
});
"""

def collect_summary(*, run=subprocess.run, present=daemon_present, locate=shutil.which, diagnostics=None):
    """Only the fixed read-only RPC method and systemd property are queried."""
    def note(value):
        if diagnostics is not None:
            diagnostics.append(value)
    pm = {'returncode':1,'output':'[]'}
    service = 'unknown'
    env={'PATH':PATH,'HOME':'/root','PM2_HOME':str(PM2_HOME),'LANG':'C'}
    try:
        binary=locate('pm2',path=PATH)
        node=locate('node',path=PATH)
        if not binary or not node:
            note('pm2_or_node_not_in_fixed_path')
        elif not present():
            note('existing_daemon_not_verified')
        else:
            root=Path(binary).resolve().parent.parent
            metadata=json.loads((root/'package.json').read_text())
            if metadata.get('name')!='pm2':
                raise ValueError('not_pm2_package')
            proc=run([node,'-e',RPC,str(root)],env=env,stdin=subprocess.DEVNULL,
                     stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=6,check=False)
            note({0:'rpc_response_received',21:'rpc_socket_error',22:'rpc_timeout',23:'rpc_method_error',24:'rpc_unexpected_shape'}.get(proc.returncode,'rpc_runtime_error'))
            if proc.returncode==0 and len(proc.stdout)<=4096 and present():
                rows=json.loads(proc.stdout)
                if type(rows) is list and all(type(r) is dict and set(r)=={'name','status'}
                    and type(r['name']) is str and r['name'] in PROCESSES
                    and type(r['status']) is str for r in rows):
                    pm={'returncode':0,'output':json.dumps(rows)}
    except (OSError,ValueError,TypeError,AttributeError,subprocess.SubprocessError):
        note('rpc_preflight_or_decode_failed')
    try:
        proc=run(['/usr/bin/systemctl','show','--no-pager','--property=ActiveState','--value','cv-ops-bridge.service'],
                 env={'PATH':'/usr/bin:/bin','LANG':'C'},stdin=subprocess.DEVNULL,
                 stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=3,check=False)
        if proc.returncode==0 and len(proc.stdout)<=64:
            service=proc.stdout.decode('ascii').strip()
    except (OSError,ValueError,TypeError,subprocess.SubprocessError):
        pass
    return health_summary(pm,service)
