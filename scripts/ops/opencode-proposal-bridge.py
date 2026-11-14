#!/usr/bin/env python3
"""Casa Viva Control OS Proposal Bridge v1 (Fase F).

Puente entre la generación remota de propuestas de IA y el ejecutor
determinista de Fase B. Recibe una propuesta JSON (`cv-change-proposal-v1`,
validada por `opencode-proposal.py`), la convierte en un paquete
`opencode-ai-job-v1` reutilizando el constructor de la Fase A
(`build_ai_job`, whitelist + redacción + TTL) y la entrega en el buzón
del ejecutor cvdev con la entrega atómica de la Fase A
(`write_ai_job`, tmp+rename, 0644).

Invariantes:
- NUNCA ejecuta texto generado por la IA: la única salida es un paquete
  estructurado en `jobs/`. Antes de entregarlo lo revalida con
  `executor.validate_job` (Fase B), que no confía en el productor.
- Sin red, sin subprocesos, sin shell, sin rutas de sistema ni
  credenciales. La generación remota (adaptador de Fase D) no se
  invoca aquí ni desde el ejecutor: la separación es estructural.
- Duplicados bloqueados en el puente: misma `proposal_id`, misma huella
  de contenido, o `run_id` ya presente en jobs/claims/results/dead.
  El ejecutor conserva además sus propias barreras (rename, flock,
  resultado previo).
- Aprobación humana obligatoria antes de integrar: el puente no
  integra nada. Solo habilita la ejecución aislada que produce un diff
  para revisión; la integración sigue pasando por la decisión humana
  del consumidor (Fase C). Sin commit, push, merge ni despliegue.

Registro anti-duplicados: un recibo por propuesta en
`CV_PROPOSAL_LEDGER` (por omisión `<buzón>/../proposal-ledger`),
archivos 0600 con `proposal_id`, `run_id`, huella y marcas de tiempo.

Rol: el mismo del worker root (Fase A). No corre automatizaciones: es
una entrega puntual, invocada a mano o por el worker con propuesta ya
aprobada para preparación.
"""
from __future__ import annotations

import json
import os
from pathlib import Path, PurePosixPath
import stat
import sys
import time

import importlib.util

_HERE = Path(__file__).resolve().parent


def _load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, _HERE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


executor = _load("cv_opencode_executor_for_bridge", "opencode-executor.py")
dev_worker = _load("cv_dev_worker_for_bridge", "dev-worker.py")
proposal_contract = _load("cv_opencode_proposal", "opencode-proposal.py")

BRIDGE_VERSION = "opencode-proposal-bridge-v1"
MAX_PROPOSAL_BYTES = executor.MAX_JOB_BYTES
DEFAULT_LEDGER = dev_worker.AI_MAILBOX.parent / "proposal-ledger"

# Códigos de salida del CLI del puente.
EXIT_OK = 0
EXIT_USAGE = 2
EXIT_CONTRACT = 3
EXIT_DUPLICATE = 4
EXIT_EXPIRED = 5
EXIT_PATH_UNSAFE = 6
EXIT_ENVIRONMENT = 7
EXIT_INTERNAL = 8


class BridgeError(Exception):
    """Error del puente; `.exit_code` es el código de salida CLI."""

    exit_code = EXIT_INTERNAL

    def __init__(self, message: str):
        super().__init__(message[:4000])
        self.message = message[:4000]


class BridgeContract(BridgeError):
    exit_code = EXIT_CONTRACT


class BridgeDuplicate(BridgeError):
    exit_code = EXIT_DUPLICATE


class BridgeExpired(BridgeError):
    exit_code = EXIT_EXPIRED


class BridgePathUnsafe(BridgeError):
    exit_code = EXIT_PATH_UNSAFE


class BridgeEnvironment(BridgeError):
    exit_code = EXIT_ENVIRONMENT


def default_ledger() -> Path:
    return Path(os.environ.get("CV_PROPOSAL_LEDGER", str(DEFAULT_LEDGER)))


def load_proposal(path) -> dict:
    """Carga una propuesta desde archivo local (nunca red, nunca stdin)."""
    try:
        path = Path(path)
        if not path.is_file():
            raise BridgeContract(f"archivo de propuesta no encontrado: {path}")
        if path.stat().st_size > MAX_PROPOSAL_BYTES:
            raise BridgeContract("propuesta demasiado grande")
        proposal = json.loads(path.read_text(encoding="utf-8"))
    except BridgeContract:
        raise
    except (OSError, ValueError) as exc:
        raise BridgeContract(f"propuesta ilegible: {exc}")
    if not isinstance(proposal, dict):
        raise BridgeContract("propuesta no es un objeto")
    return proposal


def precheck_symlinks(repo, rel_paths) -> None:
    """Defensa en profundidad contra symlinks peligrosos en el repo real.

    El ejecutor de Fase B vuelve a comprobar esto dentro del worktree
    (`workspace_target`); aquí se rechaza antes de entregar el paquete.
    """
    if not isinstance(repo, (str, Path)) or not Path(repo).is_dir():
        raise BridgePathUnsafe("repo configurado no disponible")
    root = Path(repo).resolve()
    for rel in rel_paths:
        current = root
        for part in PurePosixPath(rel).parts[:-1]:
            current = current / part
            if current.is_symlink():
                raise BridgePathUnsafe(f"ruta pasa por symlink en el repo: {rel}")
        target = root / rel
        if target.is_symlink():
            raise BridgePathUnsafe(f"target symlink en el repo: {rel}")
        resolved = target.parent.resolve() if target.parent.exists() else root
        try:
            resolved.relative_to(root)
        except ValueError:
            raise BridgePathUnsafe(f"resolución fuera del repo: {rel}")


def check_no_duplicate_run(mailbox: Path, run_id: str) -> None:
    """Un run_id solo entra una vez al buzón, nunca se re-entrega.

    Comprueba jobs (en vuelo), claims (en ejecución), results (ya
    ejecutado) y dead (rechazado o caducado): si ya está allí, la
    propuesta es duplicada y requiere un run_id nuevo tras revisión.
    """
    mailbox = Path(mailbox)
    name = f"task-run-{run_id}.json"
    for folder in ("jobs", "claims", "results", "dead"):
        if (mailbox / folder / name).exists():
            raise BridgeDuplicate(f"run_id ya entregado al buzón ({folder}): {run_id}")


def ensure_ledger(ledger: Path) -> Path:
    ledger = Path(ledger)
    try:
        if not ledger.is_dir():
            ledger.mkdir(mode=0o700)
        os.chmod(ledger, 0o700)
    except OSError as exc:
        raise BridgeEnvironment(f"registro de propuestas no disponible: {exc}")
    mode = stat.S_IMODE(ledger.stat().st_mode)
    if mode & 0o022:
        raise BridgeEnvironment(f"registro de propuestas con permisos inseguros: {oct(mode)}")
    return ledger


def load_receipts(ledger: Path) -> list[dict]:
    receipts = []
    for path in sorted(Path(ledger).glob("*.json")):
        try:
            record = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            continue
        if isinstance(record, dict):
            receipts.append(record)
    return receipts


def check_no_duplicate_proposal(ledger: Path, proposal: dict, fingerprint: str) -> None:
    receipts = load_receipts(ledger)
    proposal_id = proposal["proposal_id"]
    for record in receipts:
        if record.get("proposal_id") == proposal_id:
            raise BridgeDuplicate(f"proposal_id ya entregada: {proposal_id}")
        if record.get("content_sha256") == fingerprint:
            raise BridgeDuplicate(
                f"propuesta duplicada por contenido (proposal_id {record.get('proposal_id')})"
            )


def write_receipt(ledger: Path, proposal: dict, fingerprint: str, job: dict, job_path: Path, now: float) -> Path:
    record = {
        "version": proposal_contract.PROPOSAL_VERSION,
        "bridge": BRIDGE_VERSION,
        "proposal_id": proposal["proposal_id"],
        "run_id": proposal["run_id"],
        "content_sha256": fingerprint,
        "job_path": str(job_path),
        "job_expires_at": job.get("expires_at"),
        "delivered_at": executor.ts(now),
    }
    path = Path(ledger) / f"{proposal['proposal_id']}.json"
    tmp = path.with_name(f"{path.name}.tmp-{os.getpid()}")
    tmp.write_text(json.dumps(record, ensure_ascii=False, indent=2, sort_keys=True), encoding="utf-8")
    os.chmod(tmp, 0o600)
    os.replace(tmp, path)
    return path


def build_job(proposal: dict, now: float) -> dict:
    """Convierte la propuesta en paquete Fase A (whitelist + redacción + TTL)."""
    task = {
        "id": proposal["task_id"],
        "public_id": proposal.get("task_public_id"),
        "title": proposal.get("title") or "",
        "payload": {
            "dev": {
                "patch_format": "structured-v1",
                "allow_paths": proposal["allow_paths"],
                "operations": proposal["operations"],
            }
        },
    }
    claim = {"run_id": proposal["run_id"], "repo": proposal["repo"]}
    try:
        return dev_worker.build_ai_job(task, claim, now)
    except ValueError as exc:
        raise BridgeContract(f"construcción de paquete rechazada: {exc}")


def deliver_proposal(
    proposal,
    *,
    mailbox=None,
    ledger=None,
    repo=None,
    now: float | None = None,
) -> dict:
    """Valida, revalida y entrega la propuesta al buzón del ejecutor.

    Nunca ejecuta nada: solo deja el paquete en `jobs/` para que el
    ejecutor de Fase B lo reclame y aplique las operaciones sobre un
    worktree desechable. Devuelve el resumen de la entrega.
    """
    moment = time.time() if now is None else now
    mailbox = Path(mailbox) if mailbox is not None else dev_worker.AI_MAILBOX.parent
    jobs_dir = mailbox / "jobs"

    try:
        proposal_contract.validate_proposal(proposal, now=moment)
    except proposal_contract.ProposalError as exc:
        message = str(exc)
        if "caducada" in message:
            raise BridgeExpired(message)
        raise BridgeContract(message)

    ledger = Path(ledger) if ledger is not None else default_ledger()
    ensure_ledger(ledger)

    run_id = proposal["run_id"]
    check_no_duplicate_run(mailbox, run_id)
    fingerprint = proposal_contract.proposal_fingerprint(proposal)
    check_no_duplicate_proposal(ledger, proposal, fingerprint)

    repo_root = repo if repo is not None else executor.ALLOWED_REPOS.get(proposal["repo"])
    if repo_root is None:
        raise BridgePathUnsafe(f"repo no configurado: {proposal['repo']}")
    precheck_symlinks(repo_root, proposal_contract.list_proposal_paths(proposal))

    job = build_job(proposal, moment)
    try:
        executor.validate_job(job, moment)
    except executor.SecurityError as exc:
        raise BridgeContract(f"paquete revalidado y rechazado por el ejecutor: {exc}")

    try:
        job_path = dev_worker.write_ai_job(job, jobs_dir)
    except (RuntimeError, OSError, ValueError) as exc:
        raise BridgeEnvironment(f"entrega al buzón falló: {exc}")

    write_receipt(ledger, proposal, fingerprint, job, job_path, moment)

    return {
        "delivered": True,
        "bridge": BRIDGE_VERSION,
        "proposal_id": proposal["proposal_id"],
        "run_id": run_id,
        "job_path": str(job_path),
        "job_expires_at": job["expires_at"],
        "operations": len(job["instructions"]["operations"]),
        "note": "Ejecución aislada pendiente; la integración exige aprobación humana (Fase C).",
    }


def main(argv: list[str]) -> int:
    if len(argv) != 1:
        print(json.dumps({"bridge": BRIDGE_VERSION, "delivered": False,
                          "code": EXIT_USAGE, "reason": "uso: opencode-proposal-bridge.py <propuesta.json>"}))
        return EXIT_USAGE
    try:
        proposal = load_proposal(argv[0])
        summary = deliver_proposal(proposal)
    except BridgeError as exc:
        print(json.dumps({"bridge": BRIDGE_VERSION, "delivered": False,
                          "code": exc.exit_code, "reason": exc.message}))
        return exc.exit_code
    print(json.dumps(summary, ensure_ascii=False))
    return EXIT_OK


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
