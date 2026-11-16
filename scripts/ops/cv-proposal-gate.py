#!/usr/bin/env python3
"""Casa Viva Control OS — Puerta de propuestas (EVO-LOCAL-001).

Único punto de entrada del portátil al VPS. Se instala como COMANDO FORZADO
de una clave SSH propia (`restrict,command="..."`); la orden del cliente llega
en `SSH_ORIGINAL_COMMAND` y solo se acepta una lista blanca:

    lista            tareas que piden una propuesta local
    tarea N          reserva la tarea N (45 min) y devuelve su detalle
    entregar N       propuesta cv-change-proposal-v1 por stdin (<= 200 KB)
    latido           {"modelo": "...", "version": "..."} por stdin

Garantías
- Apagada por defecto: solo funciona con crm_settings `local_proposer` = `si`.
- Solo tareas `executor=dev`, `triaged`, sin aprobación pendiente y con
  `payload.proposal_request` explícito; el repo es siempre `casa-viva`.
- Fase 1: solo rutas bajo `docs/` y dentro de las `allow_paths` de la tarea.
- La propuesta pasa por el contrato oficial (`opencode-proposal.py`), huella
  de idempotencia, reserva vigente y tope de 6 entregas por hora; entra por la
  función existente `ops_prepare_dev_patch` con `auto_merge=false`. Después
  mandan el Dev Worker existente y la aprobación humana.
- Nunca ejecuta lo que recibe: sin procesos hijos, sin shell. Los cuerpos no
  se guardan en el registro (solo orden, tarea, código).

Códigos de salida: 0 ok · 2 uso · 3 contrato · 4 duplicada/reservada/límite ·
5 sin reserva o caducada · 6 ruta fuera de docs/ o de allow_paths ·
7 entorno (apagada, base de datos) · 8 interno.
"""
from __future__ import annotations

import importlib.util
import json
import os
import re
import sys
import time
from pathlib import Path

_HERE = Path(__file__).resolve().parent


def _load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, _HERE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


contract = _load("cv_proposal_for_gate", "opencode-proposal.py")
dev_worker = contract.dev_worker
ProposalError = contract.ProposalError

STATE = Path(os.environ.get("CV_GATE_STATE", "/var/lib/cv-proposal-gate"))
BUSINESS_ID = "8771c6ed-9dbf-497d-b3df-be6697ba2693"
PROVIDER = "local-proposer"
RESERVE_SECONDS = 45 * 60
MAX_BODY = 200_000
MAX_HEARTBEAT = 2_000
MAX_PER_HOUR = 6
DOCS_PREFIX = "docs/"
CMD_RE = re.compile(r"^(lista|latido|(tarea|entregar) ([1-9][0-9]{0,11}))$")
MODEL_RE = re.compile(r"^[A-Za-z0-9._:/-]{1,80}$")
VERSION_RE = re.compile(r"^[A-Za-z0-9._-]{1,20}$")

EXIT_OK, EXIT_USAGE, EXIT_CONTRACT, EXIT_BUSY, EXIT_NORESERVE, EXIT_PATH, EXIT_ENV, EXIT_INTERNAL = 0, 2, 3, 4, 5, 6, 7, 8


class GateError(Exception):
    def __init__(self, code: int, reason: str):
        super().__init__(reason)
        self.code, self.reason = code, reason


def _clock() -> float:
    return time.time()


def _iso(moment: float) -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(moment))


def _dir(name: str) -> Path:
    path = STATE / name
    path.mkdir(parents=True, exist_ok=True, mode=0o700)
    return path


def _setting(key: str):
    rows = dev_worker.api("GET", f"crm_settings?select=value&business_id=eq.{BUSINESS_ID}&key=eq.{key}") or []
    return rows[0].get("value") if rows else None


def require_enabled() -> None:
    if _setting("local_proposer") != "si":
        raise GateError(EXIT_ENV, "puerta apagada: crm_settings local_proposer no es «si»")


def _docs_path(value) -> str:
    rel = contract.safe_proposal_path(value)
    if not rel.startswith(DOCS_PREFIX):
        raise GateError(EXIT_PATH, f"fase 1: solo rutas bajo docs/ ({rel})")
    return rel


def _item(row: dict):
    """Elemento ofrecible al portátil, o None si la tarea no lo pide."""
    payload = row.get("payload")
    if not isinstance(payload, dict) or "dev" in payload:
        return None
    req = payload.get("proposal_request")
    if not isinstance(req, dict) or not isinstance(req.get("request"), str) or not req["request"].strip():
        return None
    paths = req.get("allow_paths")
    if not isinstance(paths, list) or not paths or len(paths) > 12:
        return None
    try:
        allow = [_docs_path(p) for p in paths]
    except (GateError, ProposalError):
        return None
    return {
        "public_id": row["public_id"], "task_id": str(row["id"]),
        "title": str(dev_worker.redact_value(str(row.get("title") or "")))[:200],
        "request": str(dev_worker.redact_value(req["request"]))[:2000],
        "allow_paths": allow, "risk": row.get("risk") or "guarded",
    }


def _eligible(public_id=None) -> list[dict]:
    query = "ops_tasks?select=id,public_id,title,payload,risk&executor=eq.dev&status=eq.triaged&approval_required=eq.false&order=created_at.asc&limit=50"
    if public_id is not None:
        query += f"&public_id=eq.{int(public_id)}"
    items = []
    for row in dev_worker.api("GET", query) or []:
        item = _item(row)
        if item:
            items.append(item)
    return items


def _reservation_path(public_id: int) -> Path:
    return _dir("reservations") / f"{int(public_id)}.json"


def _reserve(public_id: int, now: float) -> float:
    path = _reservation_path(public_id)
    if path.exists():
        try:
            until = float(json.loads(path.read_text(encoding="utf-8")).get("expires", 0))
        except (OSError, ValueError):
            until = 0
        if until > now:
            raise GateError(EXIT_BUSY, "tarea reservada")
        path.unlink()
    expires = now + RESERVE_SECONDS
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        json.dump({"expires": expires, "at": _iso(now)}, fh)
    return expires


def _check_reservation(public_id: int, now: float) -> None:
    path = _reservation_path(public_id)
    try:
        until = float(json.loads(path.read_text(encoding="utf-8")).get("expires", 0))
    except (OSError, ValueError):
        raise GateError(EXIT_NORESERVE, "sin reserva: pide primero «tarea N»")
    if until <= now:
        raise GateError(EXIT_NORESERVE, "reserva caducada")


def _rate_ok(now: float) -> list[float]:
    path = STATE / "rate.json"
    try:
        stamps = [float(x) for x in json.loads(path.read_text(encoding="utf-8"))]
    except (OSError, ValueError):
        stamps = []
    stamps = [x for x in stamps if now - x < 3600]
    if len(stamps) >= MAX_PER_HOUR:
        raise GateError(EXIT_BUSY, f"límite de {MAX_PER_HOUR} entregas por hora")
    return stamps


def _read_stdin(limit: int) -> bytes:
    data = sys.stdin.buffer.read(limit + 1)
    if len(data) > limit:
        raise GateError(EXIT_CONTRACT, f"cuerpo mayor de {limit} bytes")
    return data


def cmd_lista(now: float) -> dict:
    require_enabled()
    return {"items": _eligible()}


def cmd_tarea(public_id: int, now: float) -> dict:
    require_enabled()
    found = _eligible(public_id)
    if not found:
        raise GateError(EXIT_CONTRACT, "tarea no elegible")
    expires = _reserve(public_id, now)
    return {"item": found[0], "reservada_hasta": _iso(expires)}


def cmd_entregar(public_id: int, now: float) -> dict:
    require_enabled()
    found = _eligible(public_id)
    if not found:
        raise GateError(EXIT_CONTRACT, "tarea no elegible")
    item = found[0]
    _check_reservation(public_id, now)
    try:
        proposal = json.loads(_read_stdin(MAX_BODY).decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        raise GateError(EXIT_CONTRACT, "propuesta no es JSON válido")
    try:
        contract.validate_proposal(proposal, now=now)
        if proposal.get("task_id") != item["task_id"] or proposal.get("task_public_id") != public_id:
            raise GateError(EXIT_CONTRACT, "la propuesta no es de esta tarea")
        if proposal.get("repo") != "casa-viva":
            raise GateError(EXIT_CONTRACT, "repo no permitido")
        paths = [_docs_path(p) for p in proposal["allow_paths"]] + [_docs_path(op["path"]) for op in proposal["operations"]]
        task_prefixes = item["allow_paths"]
        for rel in paths:
            if not any(rel == pre or rel.startswith(pre if pre.endswith("/") else pre + "/") for pre in task_prefixes):
                raise GateError(EXIT_PATH, f"fuera de las rutas de la tarea: {rel}")
    except ProposalError as exc:
        raise GateError(EXIT_PATH if "ruta" in str(exc) else EXIT_CONTRACT, str(exc)[:200])
    fingerprint = contract.proposal_fingerprint(proposal)
    ledger = _dir("ledger") / f"{fingerprint}.json"
    if ledger.exists():
        raise GateError(EXIT_BUSY, "propuesta duplicada")
    stamps = _rate_ok(now)
    try:
        dev_worker.api("POST", "rpc/ops_prepare_dev_patch", {
            "p_task_public_id": public_id, "p_provider": PROVIDER, "p_repo": "casa-viva",
            "p_operations": proposal["operations"], "p_allow_paths": proposal["allow_paths"],
            "p_auto_merge": False, "p_test_profile": "casa-viva",
        })
    except Exception as exc:
        raise GateError(EXIT_ENV, f"la base rechazó la propuesta: {str(exc)[:160]}")
    ledger.write_text(json.dumps({"public_id": public_id, "at": _iso(now)}), encoding="utf-8")
    os.chmod(ledger, 0o600)
    (STATE / "rate.json").write_text(json.dumps(stamps + [now]), encoding="utf-8")
    try:
        _reservation_path(public_id).unlink()
    except OSError:
        pass
    return {"estado": "triaged", "auto_merge": False, "fase": 1}


def cmd_latido(now: float) -> dict:
    try:
        body = json.loads(_read_stdin(MAX_HEARTBEAT).decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        raise GateError(EXIT_CONTRACT, "latido no es JSON válido")
    if not isinstance(body, dict) or not MODEL_RE.match(str(body.get("modelo", ""))) or not VERSION_RE.match(str(body.get("version", ""))):
        raise GateError(EXIT_CONTRACT, "latido: modelo o versión no válidos")
    value = json.dumps({"at": _iso(now), "modelo": body["modelo"], "version": body["version"]}, ensure_ascii=False)
    dev_worker.api("POST", "crm_settings?on_conflict=business_id,key",
                   {"business_id": BUSINESS_ID, "key": "programador_local_latido", "value": value, "updated_at": _iso(now)},
                   "resolution=merge-duplicates,return=minimal")
    return {"hora": _iso(now), "encendida": _setting("local_proposer") == "si"}


def _log(command: str, public_id, code: int) -> None:
    try:
        line = json.dumps({"ts": _iso(_clock()), "cmd": command.split(" ")[0][:12], "tarea": public_id, "code": code})
        path = STATE / "gate.log"
        STATE.mkdir(parents=True, exist_ok=True, mode=0o700)
        with open(path, "a", encoding="utf-8") as fh:
            fh.write(line + "\n")
        os.chmod(path, 0o600)
    except OSError:
        pass


def run(command: str) -> tuple[int, dict]:
    match = CMD_RE.match(command or "")
    if not match:
        return EXIT_USAGE, {"ok": False, "code": EXIT_USAGE, "reason": "orden no permitida (lista, tarea N, entregar N, latido)"}
    verb = (match.group(2) or match.group(1))
    public_id = int(match.group(3)) if match.group(3) else None
    now = _clock()
    try:
        if verb == "lista":
            result = cmd_lista(now)
        elif verb == "tarea":
            result = cmd_tarea(public_id, now)
        elif verb == "entregar":
            result = cmd_entregar(public_id, now)
        else:
            result = cmd_latido(now)
    except GateError as exc:
        _log(command, public_id, exc.code)
        return exc.code, {"ok": False, "code": exc.code, "reason": exc.reason}
    except Exception as exc:  # no filtrar detalles internos al cliente
        _log(command, public_id, EXIT_INTERNAL)
        return EXIT_INTERNAL, {"ok": False, "code": EXIT_INTERNAL, "reason": "error interno"}
    _log(command, public_id, EXIT_OK)
    return EXIT_OK, {"ok": True, "code": EXIT_OK, **result}


def main() -> int:
    command = os.environ.get("SSH_ORIGINAL_COMMAND", "")
    try:
        dev_worker.load_credentials()
    except SystemExit:
        print(json.dumps({"ok": False, "code": EXIT_ENV, "reason": "sin credenciales del servidor"}))
        return EXIT_ENV
    code, out = run(command)
    print(json.dumps(out, ensure_ascii=False))
    return code


if __name__ == "__main__":
    sys.exit(main())
