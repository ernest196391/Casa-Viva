#!/usr/bin/env python3
"""Casa Viva Control OS Demo E2E v1 (Fase G).

Demostración reproducible de extremo a extremo del Control OS: una
solicitud humana ficticia se convierte en una propuesta
(`cv-change-proposal-v1`, Fase F), el puente real de la Fase F la
entrega al buzón, el ejecutor determinista existente de la Fase B la
aplica sobre un repositorio Git temporal con datos ficticios y produce
un diff, el consumidor de la Fase C deja el cambio pendiente de
aprobación y la decisión humana se registra reutilizando
`record_decision` (Fase C).

La demostración NO integra, NO publica y NO despliega nada: aprobar
solo registra la decisión. Al final se verifican el HEAD, las ramas,
el árbol de trabajo y la ausencia de remotos del repositorio temporal.

También demuestra, en el mismo mundo temporal, las defensas:
- propuesta maliciosa (`run_command`) rechazada por el puente (código 3),
- propuesta duplicada bloqueada por el puente (código 4),
- paquete entregado y luego manipulado → el ejecutor lo rechaza
  (resultado `failed`, repo intacto),
- resultado manipulado antes del consumidor → cuarentena en `dead/`
  con `.motivo.txt` y la run sigue sin cambios.

Restricciones respetadas: todo ocurre en directorios temporales, sin
secretos, sin red, sin Bubblewrap ni AppArmor, sin el adaptador de
OpenCode (la propuesta nace de un archivo fijo, no de un modelo) y sin
comandos generados por IA (solo git y las CLI fijas de las fases A–F).

Uso:
  python3 scripts/ops/opencode-demo-e2e.py [--decision approve|reject] [--conservar]

Sin `--decision` y con terminal interactivo, la decisión se pide por
teclado después de mostrar el diff. Sin terminal, exige `--decision`.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
import time

HERE = Path(__file__).resolve().parent

DEMO_VERSION = "opencode-demo-e2e-v1"
ACTOR_DEMO = "revisor-humano-demo"


def _load(name: str, filename: str):
    spec = importlib.util.spec_from_file_location(name, HERE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


# Fase C (in-process): consumidor de resultados, tienda simulada y decisión humana.
consumer = _load("cv_opencode_consumer_for_demo", "opencode-result-consumer.py")
# Fase F (in-process): solo la constante de versión del contrato de propuestas.
proposal_contract = _load("cv_opencode_proposal_for_demo", "opencode-proposal.py")


class DemoError(RuntimeError):
    """La demostración no pudo completar una escena; nada se integra."""


# --- Mundo temporal ---------------------------------------------------------

PAGINA_DEMO = """# Página de demostración (datos ficticios)

Tienda de demostración de Casa Viva. Todo el contenido de esta página es
ficticio y no corresponde a la tienda real.

Horario de atención: de 11:00 a 15:00.

Recogida en tienda: solo con cita previa (dato ficticio).
"""

HORARIO_ANTIGUO = "de 11:00 a 15:00"
HORARIO_NUEVO = "de 9:00 a 17:00"

SOLICITUDES = [
    {
        "run_id": "demo-run-1",
        "task_id": "demo-task-1",
        "public_id": 9001,
        "title": "Corregir el horario de atención en la página de demostración",
        "proposal_id": "demo-prop-1",
        "file": "docs/demo/pagina-demo.md",
    },
    {
        "run_id": "demo-run-2",
        "task_id": "demo-task-2",
        "public_id": 9002,
        "title": "Añadir aviso de demostración (defensa: paquete manipulado)",
        "proposal_id": "demo-prop-2",
        "file": "docs/demo/aviso-defensa-a.md",
    },
    {
        "run_id": "demo-run-3",
        "task_id": "demo-task-3",
        "public_id": 9003,
        "title": "Añadir aviso de demostración (defensa: resultado manipulado)",
        "proposal_id": "demo-prop-3",
        "file": "docs/demo/aviso-defensa-b.md",
    },
]


def _git(repo: Path, *args: str) -> str:
    env = dict(
        os.environ,
        GIT_AUTHOR_NAME="Demo Casa Viva", GIT_AUTHOR_EMAIL="demo@local.invalid",
        GIT_COMMITTER_NAME="Demo Casa Viva", GIT_COMMITTER_EMAIL="demo@local.invalid",
    )
    proc = subprocess.run(["git", "-C", str(repo), *args],
                          capture_output=True, text=True, env=env, timeout=60)
    if proc.returncode != 0:
        raise DemoError(f"git {args[0]} falló: {proc.stderr.strip()[:400]}")
    return proc.stdout.strip()


def crear_repo_ficticio(base: Path) -> Path:
    """Repositorio temporal con contenido 100 % ficticio y claramente identificado."""
    repo = base / "repo-demo"
    repo.mkdir()
    _git(repo, "-c", "init.defaultBranch=main", "init")
    docs = repo / "docs" / "demo"
    docs.mkdir(parents=True)
    (docs / "pagina-demo.md").write_text(PAGINA_DEMO, encoding="utf-8")
    (docs / "LEEME-DEMO.md").write_text(
        "Este repositorio es temporal y solo contiene datos ficticios de la "
        "demostración de Control OS; no toca archivos comerciales reales.\n",
        encoding="utf-8",
    )
    _git(repo, "add", "-A")
    _git(repo, "commit", "-m", "Datos ficticios de la demostración Control OS")
    return repo


def crear_buzon(base: Path) -> Path:
    home = base / "buzon"
    for name in ("jobs", "claims", "results", "dead", "work"):
        (home / name).mkdir(parents=True, mode=0o700)
    return home


def build_world(base: Path) -> dict:
    base.mkdir(parents=True, exist_ok=True)
    repo = crear_repo_ficticio(base)
    home = crear_buzon(base)
    world = {
        "base": base,
        "repo": repo,
        "home": home,
        "jobs": home / "jobs",
        "results": home / "results",
        "dead": home / "dead",
        "ledger": base / "proposal-ledger",
        "propuestas": base / "propuestas",
        "store": consumer.SimStore(base / "sim-store.json"),
        "head_previo": _git(repo, "rev-parse", "HEAD"),
        "commits_previos": len(_git(repo, "log", "--oneline").splitlines()),
    }
    world["propuestas"].mkdir()
    return world


def registrar_solicitud(world: dict, solicitud: dict, now: float) -> None:
    store = world["store"]
    store.tasks[solicitud["task_id"]] = {
        "id": solicitud["task_id"],
        "public_id": solicitud["public_id"],
        "title": solicitud["title"],
        "status": "claimed",
        "updated_at": consumer.ts(now),
    }
    store.runs[solicitud["run_id"]] = {
        "run_id": solicitud["run_id"],
        "task_id": solicitud["task_id"],
        "repo": "casa-viva",
        "status": consumer.RUN_EXPECTED_STATUS,
        "started_at": consumer.ts(now),
        "updated_at": consumer.ts(now),
    }
    store.add_event(solicitud["task_id"], "ai_run_started", {"run_id": solicitud["run_id"]})
    store.save()


def construir_proposal(solicitud: dict, now: float, **overrides) -> dict:
    proposal = {
        "version": proposal_contract.PROPOSAL_VERSION,
        "proposal_id": solicitud["proposal_id"],
        "run_id": solicitud["run_id"],
        "task_id": solicitud["task_id"],
        "task_public_id": solicitud["public_id"],
        "repo": "casa-viva",
        "title": solicitud["title"],
        "reason": "Solicitud ficticia de la demostración Control OS (Fase G).",
        "allow_paths": ["docs/demo/"],
        "operations": [
            {"op": "create_file", "path": solicitud["file"], "content": "# aviso de demostración\n"}
        ],
        "patch_format": "structured-v1",
        "created_at": consumer.ts(now - 30),
        "expires_at": consumer.ts(now + 600),
    }
    if solicitud["run_id"] == "demo-run-1":
        proposal["operations"] = [
            {"op": "text_replace", "path": solicitud["file"],
             "old": HORARIO_ANTIGUO, "new": HORARIO_NUEVO, "expected_count": 1}
        ]
    proposal.update(overrides)
    return proposal


def escribir_proposal(world: dict, name: str, proposal: dict) -> Path:
    path = world["propuestas"] / name
    path.write_text(json.dumps(proposal, ensure_ascii=False, indent=2), encoding="utf-8")
    return path


# --- Invocación de las fases reales ----------------------------------------

def run_bridge(world: dict, proposal_path: Path) -> tuple[int, dict]:
    """CLI real del puente de la Fase F (códigos de salida 0/2/3/4/5/6/7/8)."""
    env = dict(
        os.environ,
        CV_AI_MAILBOX=str(world["jobs"]),
        CV_PROPOSAL_LEDGER=str(world["ledger"]),
        CV_OPENCODE_REPO_CASA_VIVA=str(world["repo"]),
    )
    proc = subprocess.run(
        [sys.executable, str(HERE / "opencode-proposal-bridge.py"), str(proposal_path)],
        capture_output=True, text=True, env=env, timeout=180,
    )
    try:
        payload = json.loads(proc.stdout.strip())
    except ValueError:
        payload = {"stdout": proc.stdout.strip()[:2000]}
    return proc.returncode, payload


def run_executor(world: dict) -> tuple[int, dict]:
    """CLI real del ejecutor determinista de la Fase B (una pasada)."""
    env = dict(
        os.environ,
        CV_OPENCODE_HOME=str(world["home"]),
        CV_OPENCODE_REPO_CASA_VIVA=str(world["repo"]),
    )
    proc = subprocess.run(
        [sys.executable, str(HERE / "opencode-executor.py")],
        capture_output=True, text=True, env=env, timeout=300,
    )
    try:
        payload = json.loads(proc.stdout.strip())
    except ValueError:
        payload = {}
    return proc.returncode, payload


def run_consumer(world: dict, now: float | None = None) -> dict:
    """Pasada real del consumidor de la Fase C sobre la tienda simulada."""
    return consumer.run_once(world["store"], world["home"], now)


def leer_resultado(world: dict, run_id: str) -> dict:
    path = world["results"] / f"task-run-{run_id}.json"
    if not path.is_file():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))


# --- Verificaciones ---------------------------------------------------------

def verificar_repo_intacto(world: dict) -> dict:
    """Aprobar NO despliega: HEAD, ramas, árbol, commits y remotos intactos."""
    repo = world["repo"]
    return {
        "arbol_limpio": _git(repo, "status", "--porcelain", "-uall") == "",
        "head_intacto": _git(repo, "rev-parse", "HEAD") == world["head_previo"],
        "sin_nuevos_commits": len(_git(repo, "log", "--oneline").splitlines()) == world["commits_previos"],
        "solo_rama_base": _git(repo, "branch", "--list") == f"* {_git(repo, 'rev-parse', '--abbrev-ref', 'HEAD')}",
        "sin_remotos": _git(repo, "remote") == "",
    }


# --- Escenas ----------------------------------------------------------------

def cabecera(texto: str) -> None:
    print(f"\n== {texto} " + "=" * max(4, 66 - len(texto)))


def escena_solicitud(world: dict, now: float) -> None:
    cabecera("Escena 1 · Solicitud humana ficticia (Fase A, datos de demostración)")
    for solicitud in SOLICITUDES:
        registrar_solicitud(world, solicitud, now)
        print(f"  Tarea {solicitud['task_id']} (#{solicitud['public_id']}): {solicitud['title']}")
    store = world["store"]
    run = store.runs["demo-run-1"]
    print(f"  Run {run['run_id']} en estado {run['status']} (esperando propuesta de IA).")
    print("  Datos 100 % ficticios en un repositorio temporal; nada toca el repo real.")


def escena_propuesta(world: dict, now: float) -> tuple[Path, dict]:
    cabecera("Escena 2 · Propuesta de IA (contrato cv-change-proposal-v1, Fase F)")
    main = SOLICITUDES[0]
    proposal = construir_proposal(main, now)
    path = escribir_proposal(world, "propuesta-demo-1.json", proposal)
    print(f"  Archivo: {path.name}")
    print(f"  Título: {proposal['title']}")
    print(f"  Operación: text_replace en {proposal['operations'][0]['path']} "
          f"(esperado {proposal['operations'][0]['expected_count']})")
    print("  Nota: la propuesta nace de un archivo fijo; no se invocó ningún modelo "
          "ni el adaptador de OpenCode (Fase D sigue bloqueada).")
    return path, proposal


def escena_puente(world: dict, proposal_path: Path, now: float) -> None:
    cabecera("Escena 3 · Puente real (Fase F): validación, duplicados y rechazo")
    code, payload = run_bridge(world, proposal_path)
    if code != 0 or not payload.get("delivered"):
        raise DemoError(f"el puente no entregó la propuesta válida: {payload}")
    job = world["jobs"] / f"task-run-{SOLICITUDES[0]['run_id']}.json"
    print(f"  Entrega OK (código 0): {job.name} en jobs/, recibo 0600 en el ledger.")
    mode = stat.S_IMODE(job.stat().st_mode) & 0o777
    if mode != 0o644:
        raise DemoError(f"paquete entregado con permisos {oct(mode)}")

    # Defensa: propuesta duplicada (misma proposal_id / huella / run_id).
    code_dup, payload_dup = run_bridge(world, proposal_path)
    print(f"  Duplicado rechazado (código {code_dup}): {payload_dup.get('reason', '')[:110]}")
    if code_dup != 4:
        raise DemoError(f"el puente aceptó una propuesta duplicada: {payload_dup}")

    # Defensa: propuesta maliciosa con operación no autorizada.
    main = SOLICITUDES[0]
    maliciosa = construir_proposal(
        main, now, proposal_id="demo-prop-maliciosa", run_id="demo-run-maliciosa",
        operations=[{"op": "run_command", "path": "docs/demo/x.md", "cmd": "curl evil"}],
    )
    path_maliciosa = escribir_proposal(world, "propuesta-maliciosa.json", maliciosa)
    code_mal, payload_mal = run_bridge(world, path_maliciosa)
    print(f"  Propuesta con run_command rechazada (código {code_mal}): "
          f"{payload_mal.get('reason', '')[:110]}")
    if code_mal != 3:
        raise DemoError(f"el puente aceptó una operación no autorizada: {payload_mal}")
    jobs = [p.name for p in world["jobs"].iterdir() if p.is_file()]
    if jobs != [f"task-run-{SOLICITUDES[0]['run_id']}.json"]:
        raise DemoError(f"el buzón quedó en estado inesperado: {jobs}")


def escena_ejecutor(world: dict) -> dict:
    cabecera("Escena 4 · Ejecutor determinista (Fase B): diff aislado para revisión")
    code, payload = run_executor(world)
    if code != 0 or payload.get("status") != "completed":
        raise DemoError(f"el ejecutor no completó el trabajo: {payload}")
    run_id = SOLICITUDES[0]["run_id"]
    result = leer_resultado(world, run_id)
    if not result:
        raise DemoError("el ejecutor no dejó resultado")
    print(f"  Run {run_id}: {result['status']} sobre base {result['base_sha'][:12]}.")
    print(f"  Archivos afectados ({len(result['changed_paths'])}):")
    for rel in result["changed_paths"]:
        print(f"    - {rel}")
    print("  Diff propuesto (unificado):")
    for line in result["diff"].splitlines():
        print(f"    {line}")
    checks = verificar_repo_intacto(world)
    if not all(checks.values()):
        raise DemoError(f"el repo temporal quedó alterado: {checks}")
    print("  Repo temporal intacto tras la ejecución: sin commits, ramas ni cambios.")
    return result


def escena_consumidor(world: dict, result: dict) -> dict:
    cabecera("Escena 5 · Consumidor (Fase C): validación y conciliación")
    summary = run_consumer(world)
    run_id = SOLICITUDES[0]["run_id"]
    if summary["consumed"] != [{"run_id": run_id, "status": consumer.RUN_APPROVAL_STATUS}]:
        raise DemoError(f"el consumidor no dejó el cambio pendiente de aprobación: {summary}")
    store = world["store"]
    run = store.runs[run_id]
    task = store.tasks[SOLICITUDES[0]["task_id"]]
    if run["status"] != consumer.RUN_APPROVAL_STATUS or task["status"] != "waiting_approval":
        raise DemoError(f"estados inesperados: run={run['status']} tarea={task['status']}")
    print(f"  Resultado validado y conciliado: run {run_id} → {run['status']}.")
    print(f"  Tarea {task['id']} → {task['status']} (approval_required={task['approval_required']}).")
    return summary


def escena_decision(world: dict, decision: str) -> str:
    cabecera("Escena 6 · Decisión humana (Fase C): aprobar o rechazar NO despliega")
    motivo = ("Aprobado tras revisar el diff en la demostración (Fase G)."
              if decision == "approve" else
              "Rechazado en revisión humana de la demostración (Fase G).")
    outcome = consumer.record_decision(world["store"], SOLICITUDES[0]["run_id"],
                                       decision, ACTOR_DEMO, motivo)
    estados_finales = {"approve": "approved", "reject": "rejected"}
    if outcome != estados_finales[decision]:
        raise DemoError(f"la decisión no se registró: {outcome}")
    store = world["store"]
    run = store.runs[SOLICITUDES[0]["run_id"]]
    task = store.tasks[SOLICITUDES[0]["task_id"]]
    print(f"  Decisión '{decision}' registrada por {ACTOR_DEMO} con motivo.")
    print(f"  Estado final: run={run['status']}, tarea={task['status']}, "
          f"approval_required={task.get('approval_required')}.")
    checks = verificar_repo_intacto(world)
    if not all(checks.values()):
        raise DemoError(f"la decisión alteró el repo temporal: {checks}")
    if decision == "approve":
        print("  Aprobar solo registra la decisión humana. NO hubo commit, rama, push, "
              "merge ni despliegue; la integración es un paso posterior manual.")
    else:
        print("  Rechazar cierra la run sin cambios; el repo temporal sigue intacto.")
    return outcome


def escena_defensa_paquete(world: dict) -> dict:
    """Paquete entregado y luego manipulado: el ejecutor no confía en nadie."""
    cabecera("Escena 7 · Defensa: paquete manipulado tras la entrega")
    solicitud = SOLICITUDES[1]
    proposal = construir_proposal(solicitud, time.time())
    path = escribir_proposal(world, "propuesta-demo-2.json", proposal)
    code, payload = run_bridge(world, path)
    if code != 0:
        raise DemoError(f"el puente no entregó la propuesta de defensa: {payload}")
    job_path = world["jobs"] / f"task-run-{solicitud['run_id']}.json"
    job = json.loads(job_path.read_text(encoding="utf-8"))
    job["instructions"]["injected"] = {"op": "run_command", "cmd": "echo manipulado"}
    job_path.write_text(json.dumps(job), encoding="utf-8")
    os.chmod(job_path, 0o644)
    code, payload = run_executor(world)
    if code != 1 or payload.get("status") != "failed":
        raise DemoError(f"el ejecutor aceptó un paquete manipulado: {payload}")
    result = leer_resultado(world, solicitud["run_id"])
    print(f"  Ejecutor rechazó el paquete alterado: {result.get('error', '')[:110]}")
    summary = run_consumer(world)
    store = world["store"]
    run = store.runs[solicitud["run_id"]]
    if run["status"] != "failed" or not any(
            entry["run_id"] == solicitud["run_id"] for entry in summary["consumed"]):
        raise DemoError(f"la run manipulada no quedó cerrada: {summary}")
    checks = verificar_repo_intacto(world)
    if not all(checks.values()):
        raise DemoError(f"el paquete manipulado alteró el repo: {checks}")
    print("  Run cerrada como failed; repo temporal intacto (nada se ejecutó).")
    return {"error": result.get("error", ""), "run_status": run["status"]}


def escena_defensa_resultado(world: dict) -> dict:
    """Resultado manipulado antes del consumidor: cuarentena, nada se aplica."""
    cabecera("Escena 8 · Defensa: resultado manipulado antes del consumidor")
    solicitud = SOLICITUDES[2]
    proposal = construir_proposal(solicitud, time.time())
    path = escribir_proposal(world, "propuesta-demo-3.json", proposal)
    code, payload = run_bridge(world, path)
    if code != 0:
        raise DemoError(f"el puente no entregó la propuesta de defensa: {payload}")
    code, payload = run_executor(world)
    if code != 0 or payload.get("status") != "completed":
        raise DemoError(f"el ejecutor no completó la defensa: {payload}")
    result_path = world["results"] / f"task-run-{solicitud['run_id']}.json"
    result = json.loads(result_path.read_text(encoding="utf-8"))
    result["base_sha"] = "manipulado-no-es-sha"
    result_path.write_text(json.dumps(result), encoding="utf-8")
    summary = run_consumer(world)
    store = world["store"]
    run = store.runs[solicitud["run_id"]]
    task = store.tasks[solicitud["task_id"]]
    motivo = (world["dead"] / f"task-run-{solicitud['run_id']}.json.motivo.txt")
    if run["status"] != consumer.RUN_EXPECTED_STATUS or task["status"] == "waiting_approval":
        raise DemoError(f"un resultado manipulado se aplicó: run={run['status']} tarea={task['status']}")
    if not motivo.is_file():
        raise DemoError("el resultado manipulado no quedó en cuarentena con motivo")
    print(f"  Resultado alterado → cuarentena dead/ con motivo: "
          f"{motivo.read_text(encoding='utf-8')[:110]}")
    print(f"  Run {solicitud['run_id']} sigue en {run['status']}: nada se aplicó ni aprobó.")
    return {"motivo": motivo.read_text(encoding="utf-8"), "run_status": run["status"]}


def pedir_decision() -> str | None:
    if not sys.stdin.isatty():
        return None
    while True:
        respuesta = input("\nDecisión humana — ¿aprueba (a) o rechaza (r) el cambio? [a/r]: ").strip().lower()
        if respuesta in ("a", "approve", "aprobar"):
            return "approve"
        if respuesta in ("r", "reject", "rechazar"):
            return "reject"
        print("  Respuesta no válida: escriba 'a' o 'r'.")


def ejecutar_demostracion(world: dict, decision: str) -> dict:
    now = time.time()
    escena_solicitud(world, now)
    proposal_path, _ = escena_propuesta(world, now)
    escena_puente(world, proposal_path, now)
    result = escena_ejecutor(world)
    escena_consumidor(world, result)
    escena_decision(world, decision)
    paquete = escena_defensa_paquete(world)
    resultado = escena_defensa_resultado(world)
    return {
        "demo": DEMO_VERSION,
        "decision": decision,
        "run_id": SOLICITUDES[0]["run_id"],
        "task_id": SOLICITUDES[0]["task_id"],
        "estado_run": world["store"].runs[SOLICITUDES[0]["run_id"]]["status"],
        "estado_tarea": world["store"].tasks[SOLICITUDES[0]["task_id"]]["status"],
        "archivos_afectados": result["changed_paths"],
        "diff": result["diff"],
        "base_sha": result["base_sha"],
        "repo_intacto": verificar_repo_intacto(world),
        "defensas": {
            "duplicado_bloqueado": True,
            "propuesta_maliciosa_rechazada": True,
            "paquete_manipulado_rechazado": paquete,
            "resultado_manipulado_cuarentena": resultado,
        },
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="opencode-demo-e2e.py",
                                     description="Demostración E2E de Control OS (Fase G)")
    parser.add_argument("--decision", choices=("approve", "reject"),
                        help="decisión humana explícita; sin ella se pide por teclado")
    parser.add_argument("--conservar", action="store_true",
                        help="conservar el directorio temporal de la demostración")
    args = parser.parse_args(argv)

    base = Path(tempfile.mkdtemp(prefix="cv-demo-e2e-"))
    try:
        world = build_world(base)
        decision = args.decision
        if decision is None:
            decision = pedir_decision()
            if decision is None:
                print("Sin terminal interactivo: indique --decision approve o --decision reject.")
                return 2
        resumen = ejecutar_demostracion(world, decision)
        print("\n-- Resultado de la demostración --")
        print(f"  Decisión humana: {resumen['decision']}. Estado de la run: {resumen['estado_run']}.")
        print(f"  Repo temporal intacto: {all(resumen['repo_intacto'].values())}. "
              "Aprobar nunca despliega: la integración exige un paso humano posterior.")
        print("  Aislamiento del sistema operativo: NO verificado en esta demo "
              "(Bubblewrap/AppArmor siguen siendo una propuesta no instalada).")
        print(f"DEMO_RESULT {json.dumps(resumen, ensure_ascii=False)}")
        return 0
    except DemoError as exc:
        print(f"FALLO DE LA DEMOSTRACIÓN: {exc}", file=sys.stderr)
        return 1
    finally:
        if args.conservar:
            print(f"Directorio temporal conservado: {base}")
        else:
            shutil.rmtree(base, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
