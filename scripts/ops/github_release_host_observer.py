"""Read-only host identity sampler for disposable release pilot units.
No production approval, candidate commands, or signing keys.
"""
import re
import subprocess
from pathlib import Path

LABEL = "cv-release-pilot-bootstrap//&cv-release-pilot-candidate (enforce)"
UNIT = re.compile(r"cv-release-pilot-[0-9]+-[0-9]+\.service")

def unit_snapshot(unit):
    if not isinstance(unit,str) or not UNIT.fullmatch(unit):
        return None
    try:
        raw=subprocess.check_output(["/usr/bin/systemctl","show","--no-pager",
            "-p","InvocationID","-p","ControlGroup","-p","MainPID","-p","ActiveState",unit],
            timeout=3,env={"PATH":"/usr/bin:/bin","LANG":"C"})
        if len(raw)>4096:
            return None
        d=dict(line.split("=",1) for line in raw.decode("ascii").splitlines())
        if (d["ActiveState"]!="active" or d["ControlGroup"]!="/system.slice/"+unit
            or not re.fullmatch("[0-9a-f]{32}",d["InvocationID"])
            or d["InvocationID"]=="0"*32 or int(d["MainPID"])<=0):
            return None
        return d
    except (OSError,ValueError,KeyError,subprocess.SubprocessError):
        return None

def sample_process(pid, uid, group, *, proc=Path("/proc")):
    """Read kernel metadata, not candidate stdout. PID reuse fails closed."""
    if type(pid) is not int or pid<=0 or type(uid) is not int or uid<=0:
        return None
    try:
        p=proc/str(pid)
        def start():
            return int((p/"stat").read_text().rsplit(")",1)[1].split()[19])
        t=start()
        fields=dict(line.split(":",1) for line in (p/"status").read_text().splitlines() if ":" in line)
        if (list(map(int,fields["Uid"].split()))!=[uid]*4
            or fields["NoNewPrivs"].strip()!="1"
            or any(int(fields[k].strip(),16) for k in ("CapEff","CapPrm","CapInh","CapAmb","CapBnd"))
            or (p/"attr/current").read_text().strip()!=LABEL
            or (p/"cgroup").read_text().strip()!="0::"+group):
            return None
        ns=list(map(int,fields["NSpid"].split()))
        if not ns or ns[0]!=pid or t<=0 or start()!=t:
            return None
        return {"host_pid":pid,"namespace_pid":ns[-1],"start_ticks":t,"uid":uid}
    except (OSError,ValueError,KeyError,IndexError):
        return None

def sample_unit(unit, uid, *, snapshot=unit_snapshot, cgroot=Path("/sys/fs/cgroup"), sampler=sample_process):
    """Require unique confined process and stable systemd invocation."""
    if not isinstance(unit,str) or not UNIT.fullmatch(unit):
        return None
    try:
        before=snapshot(unit)
        if not before or before["ControlGroup"]!="/system.slice/"+unit:
            return None
        group=before["ControlGroup"]
        raw=(cgroot/group.lstrip("/")/"cgroup.procs").read_text()
        if len(raw)>4096:
            return None
        matches=[m for pid in set(map(int,raw.split())) if (m:=sampler(pid,uid,group)) is not None]
        if len(matches)!=1 or snapshot(unit)!=before:
            return None
        m=matches[0]
        if sampler(m["host_pid"],uid,group)!=m:
            return None
        return dict(m,invocation=before["InvocationID"],scope="synthetic_only",production_authorized=False)
    except (OSError,ValueError,KeyError,TypeError):
        return None
