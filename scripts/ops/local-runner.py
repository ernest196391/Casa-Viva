#!/usr/bin/env python3
"""Casa Viva Control OS — Programa del portátil (EVO-LOCAL-001).

Une el proponedor local (Qwen por Ollama) con la puerta de propuestas del
VPS (`cv-proposal-gate`, vía `ssh vps-gate`). Se lanza A MANO.

    local-runner.py lista                      solo lee: tareas que piden propuesta
    local-runner.py latido                     avisa «en línea» (modelo y versión)
    local-runner.py proponer N                 reserva la tarea, genera la propuesta
                                               con Qwen y la GUARDA en local (no se envía)
    local-runner.py proponer N --entregar      lo mismo y, además, la entrega a la puerta
    local-runner.py entregar N archivo.json    entrega una propuesta ya guardada y revisada
                                               (la reserva de «proponer» dura 45 min)

Por defecto nada se entrega: hay que pedir `--entregar` de forma explícita.
La puerta decide si acepta (apagada por defecto, solo `docs/**`, idempotencia,
reserva, tope por hora); después manda el Dev Worker y la aprobación humana.

Seguridad: la única orden que lanza este programa es `ssh vps-gate <orden>` con
una lista blanca de órdenes, sin shell. No usa credenciales de producción: solo
la clave SSH restringida del portátil.

Códigos de salida: 0 ok · 2 uso · 3 la puerta rechazó (ver mensaje) ·
7 entorno (sin conexión o modelo apagado) · 8 interno.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import re
import subprocess
import sys
import time
from pathlib import Path

_HERE = Path(__file__).resolve().parent
VERSION = "1.0"
GATE_HOST = "vps-gate"
SSH_TIMEOUT = 90
CMD_RE = re.compile(r"^(lista|latido|(tarea|entregar) [1-9][0-9]{0,11})$")
DEFAULT_MODEL = "qwen2.5-coder:7b"
DEFAULT_REPO = _HERE.parent.parent
DEFAULT_OUT = Path.home() / "cv-propuestas-locales"

EXIT_OK, EXIT_USAGE, EXIT_GATE, EXIT_ENV, EXIT_INTERNAL = 0, 2, 3, 7, 8

_spec = importlib.util.spec_from_file_location("cv_local_proposer_for_runner", _HERE / "local-proposer.py")
proposer = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(proposer)


class RunnerError(Exception):
    def __init__(self, code: int, message: str):
        super().__init__(message)
        self.code, self.message = code, message


def ssh_gate(command: str, stdin: bytes | None = None) -> dict:
    """Única llamada externa: `ssh vps-gate <orden>`, sin shell y con lista blanca."""
    if not CMD_RE.match(command):
        raise RunnerError(EXIT_USAGE, f"orden no permitida: {command[:40]}")
    try:
        done = subprocess.run(
            ["ssh", "-o", "BatchMode=yes", GATE_HOST, command],
            input=stdin or b"", capture_output=True, timeout=SSH_TIMEOUT,
        )
    except (subprocess.TimeoutExpired, OSError) as exc:
        raise RunnerError(EXIT_ENV, f"sin conexión con la puerta: {type(exc).__name__}")
    text = done.stdout.decode("utf-8", errors="replace").strip().splitlines()
    try:
        reply = json.loads(text[-1]) if text else None
    except ValueError:
        reply = None
    if not isinstance(reply, dict):
        raise RunnerError(EXIT_ENV, "respuesta inesperada de la puerta (¿clave o conexión?)")
    return reply


def _require_ok(reply: dict) -> dict:
    if not reply.get("ok"):
        raise RunnerError(EXIT_GATE, f"la puerta respondió {reply.get('code')}: {reply.get('reason')}")
    return reply


def cmd_lista() -> int:
    reply = _require_ok(ssh_gate("lista"))
    items = reply.get("items") or []
    if not items:
        print("No hay tareas que pidan una propuesta local.")
        return EXIT_OK
    for item in items:
        print(f"#{item['public_id']}  {item['title']}  ({', '.join(item['allow_paths'])})")
    return EXIT_OK


def cmd_latido(model: str) -> int:
    body = json.dumps({"modelo": model, "version": VERSION}).encode("utf-8")
    reply = _require_ok(ssh_gate("latido", body))
    print(f"Latido registrado a las {reply.get('hora')}. Puerta {'encendida' if reply.get('encendida') else 'apagada'}.")
    return EXIT_OK


def summarize(proposal: dict) -> str:
    lines = [f"Propuesta {proposal['proposal_id']}: {proposal['title']}", f"Motivo: {proposal.get('reason', '')}"]
    for op in proposal["operations"]:
        lines.append(f"  - {op['op']} {op['path']}")
    return "\n".join(lines)


def cmd_proponer(public_id: int, deliver: bool, repo: Path, out: Path, model: str, host: str, chat=None) -> int:
    reply = _require_ok(ssh_gate(f"tarea {public_id}"))
    item = reply["item"]
    work = out / f"tarea-{public_id}"
    work.mkdir(parents=True, exist_ok=True)
    task = {
        "run_id": f"lr-{public_id}-{int(time.time())}", "task_id": item["task_id"], "task_public_id": public_id,
        "title": item["title"], "request": item["request"], "allow_paths": item["allow_paths"], "repo": "casa-viva",
    }
    task_path = work / "tarea.json"
    task_path.write_text(json.dumps(task, ensure_ascii=False, indent=2), encoding="utf-8")
    try:
        proposal_path = proposer.propose(task_path, repo, work, model=model, host=host, chat=chat)
    except proposer.ModelUnavailable as exc:
        raise RunnerError(EXIT_ENV, str(exc))
    except (proposer.ModelOutputError, proposer.ProposalError) as exc:
        raise RunnerError(EXIT_GATE, f"el modelo no produjo una propuesta válida: {exc}")
    proposal = json.loads(proposal_path.read_text(encoding="utf-8"))
    print(summarize(proposal))
    print(f"Guardada en: {proposal_path}")
    if not deliver:
        print("No se entregó (falta --entregar). La reserva de la tarea caduca sola a los 45 min.")
        return EXIT_OK
    _require_ok(ssh_gate(f"entregar {public_id}", proposal_path.read_bytes()))
    print("Entregada a la puerta: pasa al Dev Worker y queda pendiente de aprobación humana.")
    return EXIT_OK


def cmd_entregar_guardada(public_id: int, path: Path) -> int:
    """Entrega una propuesta ya guardada y revisada (la reserva de `proponer` sigue vigente 45 min)."""
    try:
        proposal = json.loads(Path(path).read_text(encoding="utf-8"))
        proposal["proposal_id"], proposal["operations"], proposal["title"]
    except (OSError, ValueError, KeyError, TypeError):
        raise RunnerError(EXIT_USAGE, "archivo de propuesta no válido")
    if proposal.get("task_public_id") != public_id:
        raise RunnerError(EXIT_USAGE, "la propuesta guardada no es de esa tarea")
    print(summarize(proposal))
    _require_ok(ssh_gate(f"entregar {public_id}", Path(path).read_bytes()))
    print("Entregada a la puerta: pasa al Dev Worker y queda pendiente de aprobación humana.")
    return EXIT_OK


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="Programa del portátil: Qwen local + puerta de propuestas del VPS (a mano).")
    ap.add_argument("--model", default=DEFAULT_MODEL)
    ap.add_argument("--host", default=proposer.DEFAULT_HOST)
    ap.add_argument("--repo", default=str(DEFAULT_REPO), help="clon local actualizado (solo lectura)")
    ap.add_argument("--out", default=str(DEFAULT_OUT))
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("lista")
    sub.add_parser("latido")
    p = sub.add_parser("proponer")
    p.add_argument("tarea", type=int)
    p.add_argument("--entregar", action="store_true")
    e = sub.add_parser("entregar", help="entrega una propuesta ya guardada y revisada")
    e.add_argument("tarea", type=int)
    e.add_argument("archivo")
    args = ap.parse_args(argv)
    for stream in (sys.stdout, sys.stderr):  # tildes y «» legibles en la consola de Windows
        try:
            stream.reconfigure(encoding="utf-8", errors="replace")
        except (AttributeError, ValueError):
            pass
    try:
        if args.cmd == "lista":
            return cmd_lista()
        if args.cmd == "latido":
            return cmd_latido(args.model)
        if args.tarea < 1:
            raise RunnerError(EXIT_USAGE, "número de tarea no válido")
        if args.cmd == "entregar":
            return cmd_entregar_guardada(args.tarea, Path(args.archivo))
        return cmd_proponer(args.tarea, args.entregar, Path(args.repo), Path(args.out), args.model, args.host)
    except RunnerError as exc:
        print(f"{exc.message}", file=sys.stderr)
        return exc.code
    except Exception as exc:  # no mostrar trazas con rutas ni datos
        print(f"error interno: {type(exc).__name__}", file=sys.stderr)
        return EXIT_INTERNAL


if __name__ == "__main__":
    sys.exit(main())
