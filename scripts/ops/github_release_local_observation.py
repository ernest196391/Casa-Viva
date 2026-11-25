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

def collect_summary(*, run=subprocess.run, present=daemon_present, locate=shutil.which):
    """Do not expose raw PM2 JSON to the candidate or to stdout.

    PM2 CLI may auto-start a missing daemon. Use only an existing daemon,
    and PM2's no-daemon-start alternative below: read its live dump via
    existing RPC is not implemented here. The local CLI check is guarded
    but not atomic, so this collector remains a manually supervised pilot.
    """
    pm = {'returncode':1,'output':'[]'}
    service = 'unknown'
    env={'PATH':PATH,'HOME':'/root','PM2_HOME':str(PM2_HOME),'LANG':'C'}
    try:
        binary=locate('pm2',path=PATH)
        if binary and present():
            proc=run([binary,'jlist'],env=env,stdin=subprocess.DEVNULL,
                     stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=10,check=False)
            if proc.returncode==0 and len(proc.stdout)<=2_000_000 and present():
                rows=json.loads(proc.stdout)
                if type(rows) is list:
                    slim=[]
                    for row in rows:
                        if type(row) is not dict or type(row.get('name')) is not str or row['name'] not in PROCESSES:
                            continue
                        data=row.get('pm2_env')
                        status=data.get('status') if type(data) is dict else None
                        slim.append({'name':row['name'],'status':status if type(status) is str else 'unknown'})
                    pm={'returncode':0,'output':json.dumps(slim)}
    except (OSError,ValueError,TypeError,subprocess.SubprocessError):
        pass
    try:
        proc=run(['/usr/bin/systemctl','show','--no-pager','--property=ActiveState','--value','cv-ops-bridge.service'],
                 env={'PATH':'/usr/bin:/bin','LANG':'C'},stdin=subprocess.DEVNULL,
                 stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=3,check=False)
        if proc.returncode==0 and len(proc.stdout)<=64:
            service=proc.stdout.decode('ascii').strip()
    except (OSError,ValueError,TypeError,subprocess.SubprocessError):
        pass
    return health_summary(pm,service)
