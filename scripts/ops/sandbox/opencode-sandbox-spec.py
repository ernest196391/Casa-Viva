#!/usr/bin/env python3
"""Casa Viva Control OS — especificación del sandbox Bubblewrap (Fase E, PROPUESTA).

Módulo de configuración, NO de ejecución:
- `build_bwrap_command` construye el comando Bubblewrap de la propuesta
  (montaje mínimo documentado en docs/CONTROL_OS_SANDBOX_PROPOSAL.md).
- `validate_command` verifica estructuralmente ese comando (pruebas
  positivas y negativas).
- `validate_profile` verifica las invariantes de seguridad del perfil
  AppArmor propuesto (`scripts/ops/sandbox/opencode-sandbox.profile`).

Este módulo nunca ejecuta bwrap, AppArmor ni subproceso alguno; no lee
rutas del sistema ni del entorno del VPS. La activación real exige
revisión humana e instalación supervisada (ver documento de propuesta).
Hasta que el arnés `verify-sandbox.py` no confirme las pruebas negativas
en una instalación real, NADA de esta propuesta debe declararse operativo
ni cablearse al adaptador (`ISOLATION_GUARANTEED` sigue en False).
"""
from __future__ import annotations

import re
from pathlib import Path

SANDBOX_SPEC_VERSION = "opencode-sandbox-spec-v1"
PROFILE_NAME = "cv-opencode-bwrap"
BWRAP_BIN = "bwrap"
PROFILE_REL_PATH = "scripts/ops/sandbox/opencode-sandbox.profile"
VERIFY_REL_PATH = "scripts/ops/sandbox/verify-sandbox.py"

# Raíz del buzón cvdev. Fuente única de la ruta: el perfil AppArmor
# asigna su variable @{CV_CONTROL_OS} a este mismo valor y el ejecutor
# de Fase B usa esta ruta como valor por defecto de CV_OPENCODE_HOME.
# Cambiar la ruta en el VPS invalida perfil y comando: hay que revisar
# los tres artefactos juntos (las pruebas verifican la coincidencia).
CV_CONTROL_OS = "/home/cvdev/control-os-dev"

# Raíz de worktrees desechables del ejecutor de Fase B. El workspace
# cedido al sandbox debe ser un directorio hijo directo de esta raíz,
# nombrado run-<id> (mismo identificador que el buzón).
DEFAULT_WORK_ROOT = CV_CONTROL_OS + "/work"

# Montajes de solo lectura del sistema (origen y destino idénticos).
RO_BIND_SYSTEM = ("/usr",)
# Montajes de solo lectura opcionales: si faltan en el host, se omiten.
# /etc/ssl NUNCA se monta entero: contiene /etc/ssl/private (claves TLS
# del host); solo se expone el directorio público de CA.
RO_BIND_TRY_SYSTEM = (
    "/usr/local",
    "/etc/ssl/certs",
    "/etc/localtime",
    "/etc/ld.so.cache",
    "/etc/ld.so.preload",
)
# tmpfs: los únicos destinos de escritura fuera del workspace.
# El marcador WORK_ROOT_MASK se sustituye por la raíz de worktrees para
# ocultar los demás run-<id> del host dentro del sandbox.
WORK_ROOT_MASK = "__work_root__"
TMPFS_TARGETS = ("/tmp", "/run", WORK_ROOT_MASK)
SYMLINKS = (("/bin", "usr/bin"), ("/lib", "usr/lib"), ("/lib64", "usr/lib64"),
            ("/sbin", "usr/sbin"))

# Entorno del hijo: lista blanca; nunca hereda secretos. Debe cubrir
# TODO el KEEP_ENV del adaptador (Fase D) para que la integración futura
# pueda pasar el entorno ya depurado por `child_environment()`.
CHILD_ENV_KEYS = frozenset({"PATH", "HOME", "TMPDIR", "TERM", "LANG", "LC_ALL", "LC_CTYPE", "USER", "LOGNAME", "SHELL"})
DEFAULT_CHILD_ENV = {"PATH": "/usr/bin:/bin", "HOME": "/tmp", "TMPDIR": "/tmp"}

ENV_KEY_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_]{0,63}$")
WORKSPACE_RE = re.compile(r"^/[A-Za-z0-9_][A-Za-z0-9_./-]{0,240}$")
# Hoja del workspace: run-<id> con el mismo alfabeto que safe_id del
# ejecutor (Fase B); el contrato documentado es run-<id>, no cualquier
# hijo de la raíz.
WORKSPACE_LEAF_RE = re.compile(r"^run-[A-Za-z0-9][A-Za-z0-9_-]{0,63}$")


class SandboxSpecError(ValueError):
    """Violación de la especificación del sandbox (uso o tampering)."""


def validate_workspace(workspace, work_root: str = DEFAULT_WORK_ROOT) -> str:
    """Solo rutas absolutas, sin traversal, hijas directas de la raíz."""
    if isinstance(workspace, Path):
        workspace = str(workspace)
    if not isinstance(workspace, str) or not WORKSPACE_RE.match(workspace):
        raise SandboxSpecError(f"workspace inválido: {str(workspace)[:120]!r}")
    parts = [p for p in workspace.split("/") if p]
    if ".." in parts:
        raise SandboxSpecError("workspace con traversal no permitido")
    root_parts = [p for p in work_root.split("/") if p]
    if parts[: len(root_parts)] != root_parts or len(parts) != len(root_parts) + 1:
        raise SandboxSpecError(
            f"workspace debe ser hijo directo de {work_root} (run-<id>): {workspace}"
        )
    if not WORKSPACE_LEAF_RE.match(parts[-1]):
        raise SandboxSpecError(f"workspace debe llamarse run-<id>: {workspace}")
    return workspace


def validate_child_env(child_env) -> dict:
    if child_env is None:
        return dict(DEFAULT_CHILD_ENV)
    if not isinstance(child_env, dict):
        raise SandboxSpecError("child_env debe ser dict o None")
    merged = dict(DEFAULT_CHILD_ENV)
    for key, value in child_env.items():
        if not isinstance(key, str) or key not in CHILD_ENV_KEYS:
            raise SandboxSpecError(f"clave de entorno no habilitada: {str(key)[:80]!r}")
        if not isinstance(value, str) or not value or "\x00" in value or len(value) > 4096:
            raise SandboxSpecError(f"valor de entorno inválido para {key}")
        merged[key] = value
    return merged


def validate_inner_cmd(inner_cmd) -> list:
    if not isinstance(inner_cmd, (list, tuple)) or not inner_cmd:
        raise SandboxSpecError("inner_cmd vacío")
    for index, token in enumerate(inner_cmd):
        if not isinstance(token, str) or not token or "\x00" in token:
            raise SandboxSpecError(f"elemento {index} del comando interno inválido")
        if token in ("&&", "||", "|", ";", "`", "$("):
            raise SandboxSpecError(f"elemento {index} parece construcción de shell")
        if token == ".." or "/" + token == ".." or token.startswith("../"):
            raise SandboxSpecError(f"elemento {index} con traversal")
    return list(inner_cmd)


def build_bwrap_command(
    workspace,
    inner_cmd,
    *,
    bwrap_binary: str = BWRAP_BIN,
    work_root: str = DEFAULT_WORK_ROOT,
    child_env=None,
) -> list:
    """Comando Bubblewrap de la propuesta (montaje mínimo).

    Contrato: todo el sistema en solo lectura, red aislada, entorno
    limpio por lista blanca y exactamente UN destino de escritura real:
    el worktree desechable. La raíz de worktrees se cubre con tmpfs para
    que el sandbox no vea ningún otro run del host.
    """
    if not isinstance(bwrap_binary, str) or not bwrap_binary or "\x00" in bwrap_binary:
        raise SandboxSpecError("binario bwrap inválido")
    workspace = validate_workspace(workspace, work_root)
    inner = validate_inner_cmd(inner_cmd)
    env = validate_child_env(child_env)

    cmd = [
        bwrap_binary,
        "--unshare-all",
        "--die-with-parent",
        "--new-session",
        "--clearenv",
    ]
    for key in sorted(env):
        cmd.extend(["--setenv", key, env[key]])
    for source in RO_BIND_SYSTEM:
        cmd.extend(["--ro-bind", source, source])
    for source in RO_BIND_TRY_SYSTEM:
        cmd.extend(["--ro-bind-try", source, source])
    for link, target in SYMLINKS:
        cmd.extend(["--symlink", target, link])
    cmd.extend(["--proc", "/proc", "--dev", "/dev"])
    for target in TMPFS_TARGETS:
        cmd.extend(["--tmpfs", work_root if target == WORK_ROOT_MASK else target])
    cmd.extend(["--bind", workspace, workspace])
    cmd.extend(["--chdir", workspace, "--"])
    cmd.extend(inner)
    return cmd


def _pairs(cmd: list, flag: str, arity: int = 2) -> list:
    pairs = []
    for index, token in enumerate(cmd):
        if token == flag and index + arity <= len(cmd) - 1:
            pairs.append(tuple(cmd[index + 1 : index + 1 + arity]))
    return pairs


def validate_command(cmd, work_root: str = DEFAULT_WORK_ROOT, *, bwrap_binary: str | None = None) -> list:
    """Verificación estructural del comando; devuelve violaciones ([]) si ok."""
    violations = []
    if not isinstance(cmd, (list, tuple)) or len(cmd) < 10:
        return ["comando demasiado corto o no es lista"]
    if (str(cmd[0]) != bwrap_binary if bwrap_binary is not None else Path(str(cmd[0])).name != "bwrap"):
        violations.append(f"el comando no comienza por bwrap: {cmd[0]!r}")

    def flag_count(flag: str) -> int:
        return sum(1 for token in cmd if token == flag)

    for required in ("--unshare-all", "--die-with-parent", "--new-session", "--clearenv"):
        if flag_count(required) != 1:
            violations.append(f"falta {required} (exactamente una vez)")
    for forbidden in ("--share-net", "--dev-bind", "--dev-bind-try", "--bind-try"):
        if flag_count(forbidden):
            violations.append(f"bandera prohibida presente: {forbidden}")

    for key, _value in _pairs(cmd, "--setenv"):
        if key not in CHILD_ENV_KEYS:
            violations.append(f"--setenv con clave fuera de lista blanca: {key}")

    ro_pairs = _pairs(cmd, "--ro-bind") + _pairs(cmd, "--ro-bind-try")
    allowed_ro = set(RO_BIND_SYSTEM) | set(RO_BIND_TRY_SYSTEM)
    for source, target in ro_pairs:
        if source != target:
            violations.append(f"montaje ro con destino distinto del origen: {source} -> {target}")
        if source not in allowed_ro:
            violations.append(f"montaje ro fuera de lista: {source}")
    if flag_count("--ro-bind") != len(RO_BIND_SYSTEM):
        violations.append("número de --ro-bind no concilia con la especificación")

    tmpfs = [pair[0] for pair in _pairs(cmd, "--tmpfs")]
    allowed_tmpfs = {t for t in TMPFS_TARGETS if t != WORK_ROOT_MASK} | {work_root}
    for target in tmpfs:
        if target not in allowed_tmpfs:
            violations.append(f"tmpfs fuera de lista: {target}")
    if len(tmpfs) != len(TMPFS_TARGETS):
        violations.append("número de tmpfs no concilia con la especificación")

    binds = _pairs(cmd, "--bind")
    if len(binds) != 1:
        violations.append(f"debe haber exactamente un --bind, hay {len(binds)}")
    else:
        source, target = binds[0]
        if source != target:
            violations.append(f"--bind con destino distinto del origen: {source} -> {target}")
        try:
            validate_workspace(source, work_root)
        except SandboxSpecError as exc:
            violations.append(f"--bind fuera de contrato: {exc}")

    chdirs = _pairs(cmd, "--chdir", arity=1)
    if len(chdirs) != 1:
        violations.append(f"debe haber exactamente un --chdir, hay {len(chdirs)}")
    elif binds and chdirs[0][0] != binds[0][0]:
        violations.append("--chdir no coincide con el workspace del --bind")

    if "--" not in cmd:
        violations.append("falta el separador -- antes del comando interno")
    else:
        separator = cmd.index("--")
        inner = cmd[separator + 1 :]
        if not inner:
            violations.append("comando interno vacío")
        try:
            validate_inner_cmd(inner)
        except SandboxSpecError as exc:
            violations.append(f"comando interno inválido: {exc}")

    return violations


# --- Validación del perfil AppArmor propuesto --------------------------

PROFILE_ABI = "abi <abi/4.0>,"
PROFILE_HEADER = f"profile {PROFILE_NAME} /usr/bin/bwrap"
PROFILE_REQUIRED = ("userns,", "deny network,")
PROFILE_REQUIRED_WRITE = "@{CV_CONTROL_OS}/work/{,**} rw,"
PROFILE_REQUIRED_IX = "/usr/bin/** ix,"
# Lecturas requeridas: bibliotecas (incluido /usr/lib64, que aloja el
# cargador dinámico en distros usrmerge) y CA TLS públicas.
PROFILE_REQUIRED_READ = ("/usr/lib/** mr,", "/usr/lib64/** mr,", "/etc/ssl/certs/** r,")
PROFILE_FORBIDDEN_SUBSTRINGS = ("unconfined", "capability", "net_admin")
PROFILE_WRITE_PREFIXES = ("@{CV_CONTROL_OS}/work/", "/tmp/")
PROFILE_FORBIDDEN_RULES = (
    "/ rw,", "/** rw,", "/etc/{,**} rw,", "/home/{,", "/ rwk,",
    # /etc/ssl entero expone /etc/ssl/private (claves TLS del host).
    "/etc/ssl/** r,", "/etc/ssl/private",
)


def _profile_rules(text: str) -> list:
    rules = []
    for line in text.splitlines():
        code = line.split("#", 1)[0].strip()
        if not code:
            continue
        rules.append(code)
    return rules


def _rule_write_path(rule: str) -> str | None:
    """Ruta de una regla que concede `w`; None si la regla no concede escritura.

    Formato analizado: `<ruta> <permisos>,` (la porción de permisos es el
    último segmento separado por espacios). Las reglas `deny`, las
    asignaciones de variables y los flags no conceden escritura.
    """
    if rule.startswith("deny ") or rule.startswith(("profile", "abi", "include", "@{", "}")):
        return None
    if not rule.endswith(","):
        return None
    body = rule[:-1].strip()
    parts = body.split()
    if len(parts) < 2:
        return None
    path_part, perms = parts[0], parts[-1]
    if "(" in path_part or "flags=" in body:
        return None
    if "w" in perms:
        return path_part
    return None


def validate_profile(text) -> list:
    """Invariantes de seguridad del perfil propuesto; [] si cumple."""
    violations = []
    if not isinstance(text, str) or len(text) < 100:
        return ["perfil vacío o demasiado corto"]
    rules = _profile_rules(text)
    lowered = "\n".join(rules).lower()

    if not any(rule == PROFILE_ABI for rule in rules):
        violations.append("falta abi 4.0")
    if not any(rule.startswith("include <tunables/global>") for rule in rules):
        violations.append("falta include <tunables/global>")
    headers = [rule for rule in rules if rule.startswith(f"profile {PROFILE_NAME}")]
    if len(headers) != 1:
        violations.append(f"debe haber exactamente un perfil {PROFILE_NAME}")
    elif PROFILE_HEADER not in headers[0]:
        violations.append(f"el perfil no está adjunto a /usr/bin/bwrap: {headers[0]}")
    profile_lines = [rule for rule in rules if rule.startswith("profile ")]
    if len(profile_lines) != 1:
        violations.append(f"el archivo debe declarar un único perfil, hay {len(profile_lines)}")

    for fragment in PROFILE_FORBIDDEN_SUBSTRINGS:
        if fragment.lower() in lowered:
            violations.append(f"fragmento prohibido en el perfil: {fragment}")
    for rule in PROFILE_FORBIDDEN_RULES:
        if any(rule in candidate for candidate in rules):
            violations.append(f"regla demasiado amplia: {rule}")

    for required in (
        *PROFILE_REQUIRED,
        PROFILE_REQUIRED_WRITE,
        PROFILE_REQUIRED_IX,
        *PROFILE_REQUIRED_READ,
    ):
        if not any(required in rule for rule in rules):
            violations.append(f"falta regla requerida: {required}")

    for rule in rules:
        write_path = _rule_write_path(rule)
        if write_path is not None and not any(
            write_path.startswith(prefix) for prefix in PROFILE_WRITE_PREFIXES
        ):
            violations.append(f"escritura fuera de la lista de destinos: {rule}")

    return violations
