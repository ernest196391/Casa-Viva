#!/usr/bin/env python3
"""Casa Viva Control OS — Ejecución de pruebas en caja (EVO-LOCAL-001, paso E1).

Módulo usado por el Dev Worker únicamente cuando CV_DEV_SANDBOX=1. Reúne lo que el
worker necesitará para correr las pruebas de una tarea con aislamiento:

- `hand_over` / `take_back`: entregar el worktree al usuario sin privilegios
  (`cvdev`), sin seguir enlaces simbólicos; el worker no recupera la copia hostil.
- `install_deps`: `npm ci --ignore-scripts` como `cvdev`, con red y entorno
  mínimo, FUERA de la caja (`package*.json` son rutas protegidas: una
  propuesta no puede cambiarlos).
- `run_in_box`: un comando dentro de Bubblewrap con la especificación de
  `sandbox/opencode-sandbox-spec.py` (sin red, entorno por lista blanca, `/usr`
  solo lectura, un único destino de escritura: el worktree `run-<id>`), como
  `cvdev`, con tiempo límite y muerte del grupo de procesos.

Nunca usa shell. Las variables y configuración npm heredadas se excluyen;
la ausencia de acceso a archivos del host todavía requiere certificación E3.
La caja necesita la copia dedicada de bwrap y su perfil AppArmor (paso E2, que
exige aprobación de Ernesto); hasta entonces este módulo solo se prueba con
procesos simulados.
"""
from __future__ import annotations

import importlib.util
import os
import signal
import subprocess
from pathlib import Path

_HERE = Path(__file__).resolve().parent
_SIGKILL = getattr(signal, "SIGKILL", signal.SIGTERM)  # Windows no tiene SIGKILL (solo pruebas)


def _load(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, _HERE / relative)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


spec = _load("cv_sandbox_spec_for_dev_sandbox", "sandbox/opencode-sandbox-spec.py")
SandboxSpecError = spec.SandboxSpecError

DEDICATED_BWRAP = "/usr/local/lib/control-os/bwrap-dev"  # copia root 0755 (E2); no existe todavía
WORK_ROOT = "/var/lib/control-os-dev/work"  # root-owned ancestors; only disposable children are writable
BOX_ENV = {"PATH": "/usr/bin:/bin", "HOME": "/tmp", "TMPDIR": "/tmp"}
INSTALL_ENV_KEYS = ("LANG", "LC_ALL", "TZ")
INSTALL_PATH = "/usr/local/bin:/usr/bin:/bin"
MAX_OUT = 30_000


class SandboxError(RuntimeError):
    """Fallo al preparar o lanzar la caja (nunca de la prueba en sí)."""


def _walk(path: Path):
    yield path
    for root, dirs, files in os.walk(path, followlinks=False):
        for name in dirs + files:
            yield Path(root) / name


def _chown_tree(path, uid: int, gid: int, work_root: str) -> int:
    base = Path(spec.validate_workspace(str(path), work_root))
    count = 0
    for item in _walk(base):
        os.lchown(item, uid, gid)  # lchown: nunca sigue enlaces simbólicos
        count += 1
    return count


def hand_over(work, uid: int, gid: int, work_root: str = WORK_ROOT) -> int:
    """El worktree pasa a `cvdev` para poder compilar dentro de la caja."""
    return _chown_tree(work, uid, gid, work_root)


def take_back(work, uid: int = 0, gid: int = 0, work_root: str = WORK_ROOT) -> int:
    """Tras las pruebas, el worktree vuelve a root (commit y push van fuera de la caja)."""
    return _chown_tree(work, uid, gid, work_root)


def _cap(text: bytes) -> str:
    return text.decode("utf-8", errors="replace")[-MAX_OUT:]


def _launch(argv, *, cwd, env, timeout, uid, gid, spawn=subprocess.Popen):
    """Lanza sin shell, como `uid:gid`, en grupo propio; al vencer mata TODO el grupo."""
    proc = spawn(
        argv, cwd=cwd, env=env, user=uid, group=gid, extra_groups=[],
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, start_new_session=True,
    )
    try:
        out, _ = proc.communicate(timeout=timeout)
    except subprocess.TimeoutExpired:
        try:
            os.killpg(proc.pid, _SIGKILL)
        except (ProcessLookupError, PermissionError):
            proc.kill()
        out, _ = proc.communicate()
        return 124, _cap(out or b"") + f"\n[tiempo agotado a los {timeout} s; grupo de procesos terminado]"
    return proc.returncode, _cap(out or b"")


def run_in_box(cmd, workspace, timeout: int, *, uid: int, gid: int, bwrap_binary: str = DEDICATED_BWRAP,
               work_root: str = WORK_ROOT, spawn=subprocess.Popen):
    """Un comando de prueba dentro de la caja. Devuelve (código, salida)."""
    try:
        argv = spec.build_bwrap_command(workspace, cmd, bwrap_binary=bwrap_binary, work_root=work_root, child_env=BOX_ENV)
        violations = spec.validate_command(argv, work_root, bwrap_binary=bwrap_binary)
        if violations:
            raise SandboxError("comando de caja rechazado: " + "; ".join(violations))
    except SandboxSpecError as exc:
        raise SandboxError(f"comando de caja rechazado: {exc}")
    if uid == 0 or gid == 0:
        raise SandboxError("la caja nunca corre como root")
    return _launch(argv, cwd=str(workspace), env={}, timeout=timeout, uid=uid, gid=gid, spawn=spawn)


def install_deps(workspace, timeout: int, *, uid: int, gid: int, environ=None,
                 work_root: str = WORK_ROOT, spawn=subprocess.Popen):
    """Instalación con configuración aislada; el aislamiento del host requiere E3."""
    workspace = spec.validate_workspace(str(workspace), work_root)
    if uid == 0 or gid == 0:
        raise SandboxError("la instalación nunca corre como root")
    source = os.environ if environ is None else environ
    env = {k: source[k] for k in INSTALL_ENV_KEYS if k in source}
    # Una configuración de proyecto puede prevalecer sobre el entorno.
    project_config = Path(workspace) / ".npmrc"
    if project_config.is_symlink() or project_config.exists():
        raise SandboxError("configuración npm de proyecto no permitida")
    home = Path(workspace) / ".cv-npm-home"
    if home.is_symlink() or home.exists():
        raise SandboxError("HOME npm ya existe; se exige una copia nueva")
    env.update({"PATH": INSTALL_PATH, "HOME": str(home),
                "NPM_CONFIG_USERCONFIG": "/dev/null", "NPM_CONFIG_GLOBALCONFIG": "/dev/null",
                "NPM_CONFIG_CACHE": str(home / "cache")})
    argv = ["npm", "ci", "--ignore-scripts", "--no-audit", "--no-fund"]
    return _launch(argv, cwd=workspace, env=env, timeout=timeout, uid=uid, gid=gid, spawn=spawn)
