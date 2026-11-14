#!/usr/bin/env python3
"""Casa Viva Control OS Proposal Contract v1 (Fase F).

Contrato JSON estricto para propuestas de cambios producidas por la
generación remota de IA, y validador que las rechaza si violan las
invariantes del Control OS.

Separación de la Fase F:
- La generación remota (adaptador de CLI, Fase D) SOLO puede producir
  texto. Ese texto, convertido en propuesta con este contrato, entra
  por el puente (`opencode-proposal-bridge.py`) hacia el ejecutor
  determinista de Fase B. Nada de este módulo ejecuta texto generado
  por la IA, ni invoca el adaptador, ni usa red, ni lanza subprocesos.

Superficie del contrato (`cv-change-proposal-v1`):
- Solo operaciones estructuradas (`create_file`, `replace_file`,
  `text_replace` con `expected_count` 1..5), reutilizadas del ejecutor.
  Los comandos arbitrarios, la escritura de smoke y cualquier otra
  operación están fuera del contrato y se rechazan.
- Rutas relativas estrictas dentro de `allow_paths`, con bloqueo de
  rutas protegidas del repo (`.github/`, `package.json`,
  `package-lock.json`), `.git`, absolutas, traversal y caracteres
  peligrosos (barra invertida, NUL, `~` inicial).
- Rechazo de secretos: valores con forma de credencial en cualquier
  texto de la propuesta (patrones compartidos con la Fase A).

El validador no confía en nada: reutiliza las mismas rutinas con que el
ejecutor de Fase B valida los paquetes (`safe_id`, `safe_rel_path`,
`validate_operations`, `parse_ts`, `ALLOWED_REPOS`), de modo que una
propuesta aceptada aquí también puede construir un paquete
`opencode-ai-job-v1` que el ejecutor acepte de forma independiente.
"""
from __future__ import annotations

import importlib.util
from pathlib import Path, PurePosixPath

_HERE = Path(__file__).resolve().parent


def _load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, _HERE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


executor = _load("cv_opencode_executor_for_proposal", "opencode-executor.py")
dev_worker = _load("cv_dev_worker_for_proposal", "dev-worker.py")

PROPOSAL_VERSION = "cv-change-proposal-v1"

ALLOWED_PROPOSAL_KEYS = {
    "version", "proposal_id", "run_id", "task_id", "task_public_id",
    "repo", "title", "reason", "allow_paths", "operations",
    "patch_format", "created_at", "expires_at",
}

MAX_TITLE = 200
MAX_REASON = 2000
MAX_TASK_ID = 200

# Campos de texto de la propuesta que se escanean por forma de credencial.
SECRET_TEXT_FIELDS = ("title", "reason")


class ProposalError(ValueError):
    """Violación del contrato de propuestas (Fase F)."""


def safe_proposal_path(value) -> str:
    """Ruta de propuesta: endurece `safe_rel_path` del ejecutor.

    Rechaza además barra invertida (separador Windows), NUL y `~`
    inicial, que `PurePosixPath` no marca como absolutas pero sí son
    peligrosas fuera de POSIX estricto.
    """
    if not isinstance(value, str) or not value:
        raise ProposalError("ruta ausente")
    if "\\" in value or "\x00" in value:
        raise ProposalError("caracteres no permitidos en ruta")
    if value.startswith("~"):
        raise ProposalError("ruta con ~ inicial no permitida")
    try:
        return executor.safe_rel_path(value)
    except executor.SecurityError as exc:
        raise ProposalError(f"ruta no permitida: {exc}")


def scan_secret_text(label: str, text) -> None:
    """Rechaza valores con forma de credencial (patrones de Fase A)."""
    if not isinstance(text, str):
        return
    if dev_worker.SECRET_VALUE_RE.search(text):
        raise ProposalError(f"posible secreto en {label}: rechazada la propuesta")


def validate_proposal(proposal, now: float | None = None) -> dict:
    """Valida una propuesta contra el contrato; devuelve la propuesta.

    Lanza `ProposalError` ante cualquier desviación. `now` permite
    forzar la caducidad en pruebas; por defecto usa el reloj actual.
    """
    if not isinstance(proposal, dict):
        raise ProposalError("propuesta no es un objeto")
    if proposal.get("version") != PROPOSAL_VERSION:
        raise ProposalError("versión de propuesta no soportada")

    unknown = sorted(str(key) for key in proposal if key not in ALLOWED_PROPOSAL_KEYS)
    if unknown:
        raise ProposalError(f"claves de propuesta no autorizadas: {unknown}")

    try:
        proposal_id = executor.safe_id(proposal.get("proposal_id"))
        run_id = executor.safe_id(proposal.get("run_id"))
    except executor.SecurityError as exc:
        raise ProposalError(f"identificadores rechazados: {exc}")

    task_id = proposal.get("task_id")
    if not isinstance(task_id, str) or not task_id or len(task_id) > MAX_TASK_ID:
        raise ProposalError("task_id inválido")

    public_id = proposal.get("task_public_id")
    if public_id is not None and (isinstance(public_id, bool) or not isinstance(public_id, int)):
        raise ProposalError("task_public_id inválido")

    repo = proposal.get("repo")
    if repo not in executor.ALLOWED_REPOS:
        raise ProposalError(f"repo no habilitado: {str(repo)[:80]}")

    title = proposal.get("title")
    if title is not None and (not isinstance(title, str) or len(title) > MAX_TITLE):
        raise ProposalError("title inválido")
    reason = proposal.get("reason")
    if reason is not None and (not isinstance(reason, str) or len(reason) > MAX_REASON):
        raise ProposalError("reason inválido")
    for field in SECRET_TEXT_FIELDS:
        scan_secret_text(field, proposal.get(field))

    created = executor.parse_ts(proposal.get("created_at"))
    if created is None:
        raise ProposalError("created_at ausente o inválido")
    expires = executor.parse_ts(proposal.get("expires_at"))
    if expires is None:
        raise ProposalError("expires_at ausente o inválido")
    if expires <= created:
        raise ProposalError("expires_at anterior a created_at")
    if now is not None and expires <= now:
        raise ProposalError("propuesta caducada")

    if proposal.get("patch_format") != "structured-v1":
        raise ProposalError("propuestas IA solo admiten patch_format structured-v1")

    prefixes_raw = proposal.get("allow_paths")
    if not isinstance(prefixes_raw, list) or not prefixes_raw:
        raise ProposalError("allow_paths ausente o vacío")
    prefixes = [safe_proposal_path(raw) for raw in prefixes_raw]

    operations = proposal.get("operations")
    if not isinstance(operations, list):
        raise ProposalError("propuesta sin operations estructuradas")
    # Reutiliza la validación de operaciones del ejecutor de Fase B:
    # rechaza operaciones no autorizadas, rutas fuera de allow_paths y
    # rutas protegidas del repo.
    try:
        executor.validate_operations(operations, prefixes)
    except executor.SecurityError as exc:
        raise ProposalError(f"operaciones rechazadas: {exc}")

    for index, op in enumerate(operations):
        for field in ("content", "old", "new"):
            if isinstance(op, dict) and field in op:
                scan_secret_text(f"operations[{index}].{field}", op.get(field))

    return proposal


def proposal_fingerprint(proposal: dict) -> str:
    """Huella del contenido de cambio (sin identificadores ni tiempos).

    Dos propuestas con la misma huella son la misma propuesta aunque
    presenten `proposal_id` distinto.
    """
    import hashlib
    import json

    material = {
        "task_id": proposal.get("task_id"),
        "title": proposal.get("title"),
        "allow_paths": proposal.get("allow_paths"),
        "operations": proposal.get("operations"),
        "patch_format": proposal.get("patch_format"),
    }
    canonical = json.dumps(material, ensure_ascii=False, sort_keys=True)
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def list_proposal_paths(proposal: dict) -> list[str]:
    """Rutas relativas normalizadas que la propuesta toca (para prechecks)."""
    paths = []
    for op in proposal.get("operations") or []:
        if isinstance(op, dict):
            paths.append(safe_proposal_path(op.get("path")))
    return sorted(set(paths))
