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

def daemon_identity(*, home=PM2_HOME, proc=Path('/proc'), connect=None):
    """Verify the actual listening peer via Linux SO_PEERCRED, not a PID file."""
    import socket
    import struct
    import re
    try:
        p=home/'rpc.sock'
        for parent in (home,*home.parents):
            st=parent.lstat()
            if not stat.S_ISDIR(st.st_mode) or st.st_uid!=0 or st.st_mode & 0o022:
                return None
        before=p.lstat()
        if not stat.S_ISSOCK(before.st_mode) or before.st_uid!=0:
            return None
        if connect is None:
            def connect(path):
                with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as sock:
                    sock.settimeout(1)
                    sock.connect(str(path))
                    return struct.unpack('3i',sock.getsockopt(socket.SOL_SOCKET,socket.SO_PEERCRED,struct.calcsize('3i')))
        pid,uid,gid=connect(p)
        if pid<=1 or uid!=0 or gid!=0:
            return None
        process=proc/str(pid)
        def epoch():
            return int((process/'stat').read_text().rsplit(')',1)[1].split()[19])
        started=epoch()
        title=(process/'cmdline').read_bytes().split(b'\0',1)[0]
        if not re.fullmatch(rb'PM2 v[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?: God Daemon \('+re.escape(os.fsencode(home))+rb'\)',title):
            return None
        after=p.lstat()
        if started<=0 or epoch()!=started or (before.st_dev,before.st_ino)!=(after.st_dev,after.st_ino):
            return None
        return (pid,started,before.st_dev,before.st_ino)
    except (OSError,ValueError,TypeError,IndexError,AttributeError):
        return None

def daemon_present():
    return daemon_identity() is not None

# Connect only to the existing PM2 RPC socket. Never import PM2's CLI/API,
# which can start a daemon. Resolve dependencies from the installed package.
RPC = r"""
const path = require('path');
const root = process.argv[1];
// PM2 7 vendors these modules; older releases declare npm dependencies.
const fs = require('fs');
function loadTransport(name) {
  const bundled = path.join(root, 'modules', name);
  if (fs.existsSync(bundled)) return require(bundled);
  return require(require.resolve(name, {paths:[root]}));
}
let axon, rpc;
try {
  axon = loadTransport('pm2-axon');
  rpc = loadTransport('pm2-axon-rpc');
} catch (_) { process.exit(25); }
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

def collect_summary(*, run=subprocess.run, present=daemon_identity, locate=shutil.which, diagnostics=None):
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
        elif not (identity := present()):
            note('existing_daemon_not_verified')
        else:
            root=Path(binary).resolve().parent.parent
            metadata=json.loads((root/'package.json').read_text())
            if metadata.get('name')!='pm2':
                raise ValueError('not_pm2_package')
            proc=run([node,'-e',RPC,str(root)],env=env,stdin=subprocess.DEVNULL,
                     stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=6,check=False)
            note({0:'rpc_response_received',21:'rpc_socket_error',22:'rpc_timeout',23:'rpc_method_error',24:'rpc_unexpected_shape',25:'rpc_transport_unavailable'}.get(proc.returncode,'rpc_runtime_error'))
            if proc.returncode==0 and len(proc.stdout)<=4096 and present()==identity:
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
