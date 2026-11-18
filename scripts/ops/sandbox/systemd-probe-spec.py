"""E3 systemd calibration candidate. Builds only a fixed synthetic probe.

No root operations, worker integration or arbitrary payload. The caller must
review the command and run it manually in a disposable workspace. A successful
probe is not certification of untrusted code execution.
"""
import re
from pathlib import PurePosixPath

WORK_ROOT = "/var/lib/control-os-dev/work"
LEAF = re.compile(r"run-e3-[A-Za-z0-9][A-Za-z0-9_-]{0,47}\Z")
UNIT = re.compile(r"cv-e3-[A-Za-z0-9][A-Za-z0-9_-]{0,39}\Z")

PROBE = r'''import json, os, pathlib, socket
p = pathlib.Path.cwd() / "cv-e3-synthetic.txt"
p.write_text("synthetic")
checks = {"workspace_write": p.read_text() == "synthetic",
          "root_hidden": not os.path.exists("/root/Casa-Viva"),
          "sibling_hidden": not os.path.exists(os.environ["CV_E3_OTHER"]),
          "run_hidden": not os.path.exists("/run/systemd/private")}
for family in (socket.AF_INET, socket.AF_INET6):
    try:
        s = socket.socket(family, socket.SOCK_STREAM)
        s.close()
        checks["network_" + str(family)] = False
    except OSError:
        checks["network_" + str(family)] = True
try:
    pathlib.Path("/var/lib/control-os-dev/work/cv-e3-outside.txt").write_text("synthetic")
    checks["outside_write_denied"] = False
except OSError:
    checks["outside_write_denied"] = True
group = pathlib.Path("/sys/fs/cgroup") / pathlib.Path(
    pathlib.Path("/proc/self/cgroup").read_text().strip().split("::", 1)[1]
).relative_to("/")
limits = {k: (group / k).read_text().strip()
          for k in ("memory.max", "pids.max", "cpu.max")}
checks["memory_limit"] = limits["memory.max"] == "268435456"
checks["process_limit"] = limits["pids.max"] == "16"
quota, period = map(int, limits["cpu.max"].split())
checks["cpu_limit"] = quota > 0 and period > 0 and quota * 4 <= period
print(json.dumps({"checks": checks, "limits": limits}, sort_keys=True))
raise SystemExit(0 if all(checks.values()) else 5)
'''

def _workspace(path: str) -> str:
    if not isinstance(path, str):
        raise ValueError("workspace inválido")
    parsed = PurePosixPath(path)
    if str(parsed) != path or str(parsed.parent) != WORK_ROOT or not LEAF.fullmatch(parsed.name):
        raise ValueError("workspace sintético fuera de contrato")
    return path


def build_probe_command(workspace: str, sibling: str, unit: str) -> list[str]:
    workspace, sibling = _workspace(workspace), _workspace(sibling)
    if workspace == sibling or not isinstance(unit, str) or not UNIT.fullmatch(unit):
        raise ValueError("unidad o canario inválido")
    properties = (
        "PrivateNetwork=yes", "PrivateIPC=yes", "RestrictAddressFamilies=AF_UNIX",
        "ProtectSystem=strict", "ProtectHome=tmpfs", "PrivateTmp=yes",
        "PrivateDevices=yes", "NoNewPrivileges=yes", "CapabilityBoundingSet=",
        "ProtectKernelTunables=yes", "ProtectKernelModules=yes",
        "ProtectControlGroups=yes", "RestrictNamespaces=yes",
        "ProtectProc=invisible", "TemporaryFileSystem=/var:ro",
        "TemporaryFileSystem=/etc:ro", "TemporaryFileSystem=/run:ro",
        "InaccessiblePaths=-/srv -/opt -/mnt -/media",
        "BindPaths=" + workspace, "ReadWritePaths=" + workspace,
        "MemoryMax=256M", "TasksMax=16", "CPUQuota=25%",
        "RuntimeMaxSec=30s", "KillMode=control-group",
    )
    command = ["/usr/bin/systemd-run", "--wait", "--pipe", "--collect",
               "--unit=" + unit, "--uid=cvdev", "--working-directory=" + workspace,
               "--setenv=CV_E3_OTHER=" + sibling + "/canary"]
    for prop in properties:
        command.extend(["--property", prop])
    return command + ["/usr/bin/python3", "-B", "-c", PROBE]
