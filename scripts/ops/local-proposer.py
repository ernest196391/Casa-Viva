#!/usr/bin/env python3
"""Casa Viva Control OS — Proponedor local (EVO-LOCAL-001).

Convierte una tarea en una propuesta `cv-change-proposal-v1` usando un
modelo que corre en ESTE equipo (Ollama, por defecto `qwen2.5-coder:7b`).

Qué hace
- Lee la tarea (JSON), lee en SOLO LECTURA los archivos existentes bajo
  `allow_paths` del clon local, pide al modelo operaciones estructuradas
  y arma la propuesta.
- La valida con el contrato oficial (`opencode-proposal.py`, el mismo
  validador del puente y del ejecutor) y comprueba contra los archivos
  reales que cada cambio es aplicable (texto a reemplazar presente el
  número exacto de veces, archivo nuevo que no existe, etc.).
- Escribe la propuesta en un directorio de salida. Nada más.

Qué NO hace (a propósito)
- No aplica cambios, no ejecuta comandos, no usa shell ni subprocess.
- No entrega la propuesta: la entrega es el puente (`opencode-proposal-
  bridge.py`) y la integración exige aprobación humana.
- No toca producción ni usa credenciales. Solo habla con el modelo por
  loopback (127.0.0.1 / localhost / ::1); cualquier otro host se rechaza.

Códigos de salida: 0 propuesta escrita · 2 uso · 3 contrato/aplicabilidad
· 6 ruta insegura · 7 entorno (modelo no disponible) · 8 respuesta del
modelo inválida.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
import sys
import time
import types
import urllib.error
import urllib.request
from pathlib import Path
from urllib.parse import urlparse

_HERE = Path(__file__).resolve().parent

# Windows no trae estos módulos POSIX. El validador solo usa funciones
# puras (rutas, ids, forma de las operaciones); el sustituto vacío se
# pone únicamente para poder IMPORTARLO. Aquí nunca se ejecuta nada.
for _name in ("fcntl", "resource", "pwd", "grp"):
    try:
        __import__(_name)
    except ImportError:
        sys.modules[_name] = types.ModuleType(_name)


def _load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, _HERE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


contract = _load("cv_proposal_for_local_proposer", "opencode-proposal.py")
ProposalError = contract.ProposalError

TS_FMT = "%Y-%m-%dT%H:%M:%SZ"
TTL_SECONDS = 45 * 60
DEFAULT_MODEL = "qwen2.5-coder:7b"
DEFAULT_HOST = "http://127.0.0.1:11434"
MAX_CONTEXT_FILES = 6
MAX_CONTEXT_CHARS = 12_000
MAX_TASK_TEXT = 2_000
MODEL_TIMEOUT = 600
LOOPBACK = {"127.0.0.1", "localhost", "::1"}
OP_KEYS = ("op", "path", "content", "old", "new", "expected_count")

EXIT_OK, EXIT_USAGE, EXIT_CONTRACT, EXIT_PATH, EXIT_ENV, EXIT_MODEL = 0, 2, 3, 6, 7, 8


class ModelOutputError(ValueError):
    """La respuesta del modelo no es utilizable."""


class ModelUnavailable(RuntimeError):
    """El modelo local no responde."""


def load_task(path) -> dict:
    task = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(task, dict):
        raise ProposalError("la tarea no es un objeto")
    for key in ("run_id", "task_id", "title", "request", "allow_paths"):
        if key not in task:
            raise ProposalError(f"tarea sin {key}")
    request = task["request"]
    if not isinstance(request, str) or not request.strip() or len(request) > MAX_TASK_TEXT:
        raise ProposalError(f"request debe ser texto de 1..{MAX_TASK_TEXT} caracteres")
    return task


def read_context(repo_root, allow_paths: list[str]) -> dict[str, str]:
    """Archivos existentes bajo allow_paths, solo lectura, sin symlinks."""
    root = Path(repo_root).resolve()
    found: dict[str, str] = {}
    for prefix in allow_paths:
        rel = contract.safe_proposal_path(prefix)
        base = root / rel
        candidates = [base] if base.is_file() else sorted(base.rglob("*")) if base.is_dir() else []
        for item in candidates:
            if len(found) >= MAX_CONTEXT_FILES:
                return found
            if item.is_symlink() or not item.is_file():
                continue
            try:
                resolved = item.resolve()
                resolved.relative_to(root)
                text = resolved.read_text(encoding="utf-8")
            except (ValueError, OSError, UnicodeDecodeError):
                continue
            if len(text) > MAX_CONTEXT_CHARS:
                continue
            found[resolved.relative_to(root).as_posix()] = text
    return found


def build_messages(task: dict, context: dict[str, str]) -> list[dict]:
    files = "\n\n".join(f"### {p}\n```\n{t}\n```" for p, t in context.items()) or "(sin archivos existentes)"
    system = (
        "Eres un programador cuidadoso. Responde SOLO con un objeto JSON, sin texto extra, con las claves: "
        '"title" (texto corto), "reason" (por qué, 1-3 frases) y "operations" (lista de 1 a 8 objetos). '
        'Cada operación es {"op":"text_replace","path":"...","old":"texto exacto existente","new":"texto nuevo",'
        '"expected_count":1} o {"op":"create_file","path":"...","content":"..."} o '
        '{"op":"replace_file","path":"...","content":"archivo completo"}. '
        "Usa solo rutas dentro de las permitidas. 'old' debe copiarse EXACTO del archivo mostrado. "
        "No inventes archivos que no se te muestran salvo create_file. Sin comandos ni secretos."
    )
    user = (
        f"Tarea: {task['title']}\nPetición: {task['request']}\n"
        f"Rutas permitidas: {', '.join(task['allow_paths'])}\n\nArchivos actuales:\n{files}"
    )
    return [{"role": "system", "content": system}, {"role": "user", "content": user}]


def ollama_chat(messages, model: str = DEFAULT_MODEL, host: str = DEFAULT_HOST, timeout: int = MODEL_TIMEOUT) -> str:
    parsed = urlparse(host)
    if parsed.scheme != "http" or (parsed.hostname or "") not in LOOPBACK:
        raise ProposalError("el modelo solo puede estar en este equipo (loopback)")
    body = json.dumps({
        "model": model, "messages": messages, "stream": False, "format": "json",
        "options": {"temperature": 0, "num_ctx": 8192, "num_predict": 2048},
    }).encode("utf-8")
    req = urllib.request.Request(host.rstrip("/") + "/api/chat", data=body, headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            data = json.loads(resp.read().decode("utf-8"))
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        raise ModelUnavailable(f"modelo local no disponible: {exc}")
    content = (data.get("message") or {}).get("content")
    if not isinstance(content, str) or not content.strip():
        raise ModelOutputError("respuesta vacía del modelo")
    return content


def parse_model_output(text: str) -> dict:
    try:
        obj = json.loads(text)
    except json.JSONDecodeError as exc:
        raise ModelOutputError(f"JSON no válido: {exc.msg}")
    if not isinstance(obj, dict) or not isinstance(obj.get("operations"), list):
        raise ModelOutputError("falta la lista operations")
    return obj


def check_applicable(operations: list, repo_root) -> None:
    """Cada cambio debe poder aplicarse sobre los archivos reales."""
    root = Path(repo_root).resolve()
    for index, op in enumerate(operations):
        rel = contract.safe_proposal_path(op.get("path"))
        target = root / rel
        kind = op.get("op")
        if kind == "create_file":
            if target.exists():
                raise ProposalError(f"operations[{index}]: {rel} ya existe (usa replace_file)")
        elif kind in ("replace_file", "text_replace"):
            if not target.is_file() or target.is_symlink():
                raise ProposalError(f"operations[{index}]: {rel} no existe como archivo normal")
            if kind == "text_replace":
                actual = target.read_text(encoding="utf-8").count(op["old"])
                expected = op.get("expected_count", 1)
                if actual != expected:
                    raise ProposalError(f"operations[{index}]: 'old' aparece {actual} veces en {rel}, se esperaban {expected}")


def build_proposal(task: dict, model_obj: dict, now: float | None = None) -> dict:
    moment = time.time() if now is None else now
    operations = []
    for raw in model_obj["operations"]:
        if not isinstance(raw, dict):
            raise ProposalError("operación no es un objeto")
        operations.append({k: raw[k] for k in OP_KEYS if k in raw})
    digest = hashlib.sha256(json.dumps(operations, sort_keys=True).encode("utf-8")).hexdigest()[:10]
    return {
        "version": contract.PROPOSAL_VERSION,
        "proposal_id": f"lp-{str(task['run_id'])[:40]}-{digest}",
        "run_id": task["run_id"],
        "task_id": str(task["task_id"]),
        "task_public_id": task.get("task_public_id"),
        "repo": task.get("repo", "casa-viva"),
        "title": str(model_obj.get("title") or task["title"])[:200],
        "reason": str(model_obj.get("reason") or "")[:2000],
        "allow_paths": list(task["allow_paths"]),
        "operations": operations,
        "patch_format": "structured-v1",
        "created_at": time.strftime(TS_FMT, time.gmtime(moment)),
        "expires_at": time.strftime(TS_FMT, time.gmtime(moment + TTL_SECONDS)),
    }


def propose(task_path, repo_root, out_dir, model: str = DEFAULT_MODEL, host: str = DEFAULT_HOST, chat=None, now=None) -> Path:
    task = load_task(task_path)
    context = read_context(repo_root, task["allow_paths"])
    text = (chat or ollama_chat)(build_messages(task, context), model, host)
    proposal = build_proposal(task, parse_model_output(text), now)
    contract.validate_proposal(proposal, now=now if now is not None else time.time())
    check_applicable(proposal["operations"], repo_root)
    out = Path(out_dir)
    out.mkdir(parents=True, exist_ok=True)
    dest = out / f"{proposal['proposal_id']}.json"
    tmp = dest.with_name(dest.name + f".tmp-{os.getpid()}")
    tmp.write_text(json.dumps(proposal, ensure_ascii=False, indent=2, sort_keys=True), encoding="utf-8")
    os.replace(tmp, dest)
    return dest


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="Genera una propuesta cv-change-proposal-v1 con un modelo local; no aplica ni entrega nada.")
    ap.add_argument("--task", required=True, help="JSON de la tarea (run_id, task_id, title, request, allow_paths)")
    ap.add_argument("--repo", required=True, help="clon local del repo (solo lectura)")
    ap.add_argument("--out", required=True, help="carpeta donde escribir la propuesta")
    ap.add_argument("--model", default=DEFAULT_MODEL)
    ap.add_argument("--host", default=DEFAULT_HOST)
    args = ap.parse_args(argv)
    try:
        dest = propose(args.task, args.repo, args.out, args.model, args.host)
    except ModelUnavailable as exc:
        print(f"ENTORNO: {exc}", file=sys.stderr)
        return EXIT_ENV
    except ModelOutputError as exc:
        print(f"MODELO: {exc}", file=sys.stderr)
        return EXIT_MODEL
    except ProposalError as exc:
        text = str(exc)
        print(f"CONTRATO: {text}", file=sys.stderr)
        return EXIT_PATH if "ruta" in text else EXIT_CONTRACT
    except (OSError, json.JSONDecodeError) as exc:
        print(f"USO: {exc}", file=sys.stderr)
        return EXIT_USAGE
    print(f"propuesta escrita: {dest} (no aplicada, no entregada)")
    return EXIT_OK


if __name__ == "__main__":
    sys.exit(main())
