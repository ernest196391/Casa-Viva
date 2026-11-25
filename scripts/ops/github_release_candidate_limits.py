"""Read-only cgroup v2 evidence for a dedicated candidate execution.

Never changes the active Dev Worker or grants release permission. Evidence
must come from the actual dedicated candidate cgroup, not a caller's claim.
"""
import re
from pathlib import Path

LIMITS={'memory.max':268435456,'pids.max':32}
CPU_MAX=0.5

def assess(values):
    try:
        if set(values)!={'memory.max','pids.max','cpu.max'}:
            return False
        for key,limit in LIMITS.items():
            raw=values[key]
            if not isinstance(raw,str) or not re.fullmatch(r'[0-9]{1,15}',raw):
                return False
            if not 0<int(raw)<=limit:
                return False
        quota,period=values['cpu.max'].split()
        if not all(re.fullmatch(r'[0-9]{1,15}',v) for v in (quota,period)):
            return False
        return 0<int(quota)<=int(period)*CPU_MAX and int(period)>0
    except (ValueError,TypeError,AttributeError,KeyError):
        return False

def read_candidate_limits(unit, *, root=Path('/sys/fs/cgroup')):
    # Only the fixed isolated pilot unit; never certify the shared worker.
    if unit!='cv-release-candidate.service':
        return False
    path=root/'system.slice'/unit
    try:
        if not path.is_dir() or path.is_symlink():
            return False
        values={}
        for name in (*LIMITS,'cpu.max'):
            with (path/name).open('r',encoding='ascii') as f:
                raw=f.read(128)
            values[name]=raw.strip()
        return assess(values)
    except (OSError,UnicodeError,ValueError):
        return False
