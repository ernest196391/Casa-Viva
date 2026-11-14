# Control OS × OpenCode — Buzón, ejecutor cvdev, consumidor de resultados y adaptador de CLI

Estado: Fases A, B y C (simulación) más Fase D (adaptador de CLI, bloqueado para invocación real) implementadas en rama local `control-os/opencode-fase-a`. Sin push, sin merge, sin despliegue y sin acceso a Supabase real.

**Fase E (propuesta de sandbox, no instalada)**: la propuesta de aislamiento con Bubblewrap + AppArmor está documentada en `docs/CONTROL_OS_SANDBOX_PROPOSAL.md`, con artefactos en `scripts/ops/sandbox/`. El adaptador sigue bloqueado (`ISOLATION_GUARANTEED = False`); nada de la propuesta está instalado, probado contra el sistema real ni cableado al adaptador.

**Fase F (contrato de propuestas + puente, implementada)**: la separación entre generación remota de IA y ejecución determinista está documentada en `docs/CONTROL_OS_PROPOSAL_CONTRACT.md`, con el contrato `cv-change-proposal-v1` (`scripts/ops/opencode-proposal.py`) y el puente hacia el buzón (`scripts/ops/opencode-proposal-bridge.py`). El ejecutor no fue modificado y no conoce la Fase F (verificado por escaneo estático).

**Fase G (demostración E2E, implementada)**: la demostración reproducible de extremo a extremo está documentada en `docs/CONTROL_OS_DEMO_E2E.md`, con el arnés `scripts/ops/opencode-demo-e2e.py`: repo Git temporal con datos ficticios, propuesta `cv-change-proposal-v1` fija (sin modelo), puente real de Fase F (CLI), ejecutor de Fase B (CLI), consumidor de Fase C (in-process), decisión humana con `record_decision` y verificación de que aprobar no despliega (HEAD, ramas, commits, árbol y remotos intactos). Incluye defensas demostradas (duplicados, propuesta maliciosa, paquete manipulado, resultado manipulado). Ninguna fase A–F fue modificada.

## Papeles

- **Productor (root, Fase A)**: `scripts/ops/dev-worker.py`. Reclama tareas `mode == "ai"` de Supabase, construye el paquete con whitelist estricta (`build_ai_job`) y lo entrega en el buzón de cvdev. Nunca ejecuta el editor de IA.
- **Ejecutor (cvdev, Fase B)**: `scripts/ops/opencode-executor.py`. Reclama el paquete del buzón, revalida todo, aplica ediciones estructuradas sobre un worktree desechable y produce un paquete de resultados con diff para revisión humana.
- **Consumidor de resultados (root, Fase C, simulación)**: `scripts/ops/opencode-result-consumer.py`. Lee `results/`, valida el paquete sin confiar en el ejecutor, concilia `run_id`/`task_id`/`repo`/`task_public_id` contra el registro de runs y tareas, detecta duplicados y estados caducados y deja el cambio pendiente de aprobación humana. Nunca integra nada: sin commit, push, merge ni despliegue. El registro real en Supabase queda pendiente; la Fase C usa una tienda simulada (`SimStore`) con datos ficticios.

## Buzón

Ruta por defecto: `/home/cvdev/control-os-dev` (configurable con `CV_OPENCODE_HOME` en el ejecutor y `CV_AI_MAILBOX` en el productor).

```
/home/cvdev/control-os-dev/
  jobs/      # entrada: paquetes escritos por root (0644)
  claims/    # en curso: reclamo atómico por rename (0600)
  results/   # salida: resultados del ejecutor (0600, escritos por cvdev)
  dead/      # cuarentena: caducados, corruptos, duplicados, tampering
  work/      # worktrees desechables run-<run_id>
```

### Permisos mínimos (provisionamiento)

- El directorio debe existir **antes**, creado como usuario `cvdev` (propiedad cvdev, modo `0700`).
- El worker root **no lo crea**: si falta o es escribible por grupo/otros, falla el trabajo IA con error claro (`validate_mailbox_dir`).
- La confidencialidad la aporta el **directorio** (`0700`, solo cvdev y root pueden entrar), no la redacción. Los paquetes van `0644` para que cvdev pueda leerlos; los resultados y reclamos van `0600`.
- El ejecutor revalida los permisos en cada pasada (`ensure_mailbox`) y rechaza cualquier archivo de trabajo alterable por grupo/otros.
- cvdev no tiene acceso a `/root` ni a credenciales; el ejecutor no las referencia (hay prueba estática AST que lo impide).

## Entrega atómica y protecciones

1. **Escritura atómica**: el productor escribe `*.json.tmp-<pid>` y hace `os.replace` (nunca se lee un paquete a medias).
2. **Reclamo atómico**: el ejecutor hace `rename(jobs → claims)`; si el origen ya no existe, otro ejecutor lo tomó. Dos ejecutores nunca ejecutan el mismo trabajo por rename.
3. **Flock sobre el reclamo**: segunda barrera contra ejecuciones concurrentes del mismo run.
4. **Resultado previo**: si `results/task-run-<run_id>.json` ya existe, no se re-ejecuta y el reclamo va a `dead/`. El resultado original nunca se sobrescribe.
5. **Cuarentena `dead/`**: nombres inválidos, identificadores inseguros, permisos alterables, JSON corrupto, caducados, duplicados y resultados vencidos. Nada se borra en silencio; cada entrada lleva un `.motivo.txt`.

## Validación del ejecutor (no confía en el productor)

- Versión de paquete exacta (`opencode-ai-job-v1`), modo `ai`, repo en lista habilitada (solo `casa-viva`, ruta tomada de la configuración local, nunca del paquete).
- Identificadores seguros: `run_id` y nombres de archivo contra `^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$`; rutas de trabajo y worktree derivadas solo del identificador validado.
- Rutas: relativas estrictas (sin absolutas, sin `..`, sin `.git`, ≤220 chars), dentro de `allow_paths`, con bloqueo adicional de rutas protegidas (`.github/`, `package.json`, `package-lock.json`) aunque la allowlist las incluya.
- Tamaños: ≤8 operaciones, contenido ≤120000, smoke ≤12000, diff ≤12 archivos y ≤1200 líneas, paquete ≤2MB.
- Excluyentes: `operations` (requiere `patch_format: structured-v1`) o `deterministic` (`write_smoke_file` solo en `docs/control-os-smoke/*.md`).
- Claves no autorizadas en el paquete, en instrucciones o en operaciones → rechazo.

## Ejecución aislada

- Worktree desechable: `git worktree add --detach` del repo configurado en `work/run-<run_id>`, HEAD local (sin fetch, sin red).
- Únicos subprocesos: git con lista blanca (`rev-parse`, `worktree add/remove/prune`, `status --porcelain -uall`, `diff`/`diff --check`, `add -N`). Sin shell, sin binarios externos (prueba estática).
- Los comandos jamás provienen del paquete: las operaciones admitidas son solo `create_file`, `replace_file` y `text_replace` (con `expected_count` 1..5), aplicadas por código del ejecutor.
- Symlinks: se rechaza cualquier ruta que cruce un symlink o cuyo target sea symlink (protección contra escape del workspace).
- Integridad: `git status --porcelain -uall` debe coincidir exactamente con el conjunto de archivos esperado; cualquier cambio inesperado falla el trabajo.
- Al terminar (éxito o fallo) el worktree se elimina; no quedan ramas, commits ni cambios en el repo.

## Caducidad coherente

- TTL de 45 minutos en paquete (`expires_at`), reclamo y resultado.
- El ejecutor no ejecuta trabajos vencidos: los manda a `dead/` sin escribir resultado (la run la falla el worker con `ai_job_expired`).
- `sweep_expired` mueve reclamos vencidos y resultados no recogidos a `dead/`.

## Resultados (contrato Fase B, consumido por Fase C)

`results/task-run-<run_id>.json` con `version` (`opencode-result-v1`), `executor` (`opencode-executor-v1`), `run_id`, `task_id`, `task_public_id`, `repo`, `status` (`completed`|`failed`), `error`, `base_sha`, `changed_paths`, `diff` (unificado, para revisión), `logs`, `finished_at`, `ttl_seconds` y `expires_at`. Los paquetes `completed` exigen `base_sha` (40 hex), `changed_paths` (1..12 rutas seguras) y `diff`; los `failed` exigen `error` y no llevan cambios residuales.

- El ejecutor nunca hace push, merge, commit, branch ni despliegue (hay prueba que verifica HEAD, ramas y árbol del repo tras una ejecución).
- La revisión humana decide qué se integra; Fase C lee estos paquetes y pide aprobación antes de proponer la rama.

## Consumidor de resultados (Fase C, modo simulación)

- **Recepción y validación**: cada pasada lee todo lo presente en `results/` (nombres no conformes van a `dead/`), comprueba versión exacta, whitelist de claves, identificadores seguros, repo habilitado (`casa-viva`), marcas de tiempo coherentes, TTL y tamaños (diff ≤400k, logs ≤10×2k, error ≤8k, archivo ≤1MB). Sin subprocesos, sin red, sin rutas de sistema.
- **Conciliación**: el resultado solo se acepta si existe la run, está en `waiting_ai`, y `task_id`, `repo` y `task_public_id` coinciden con la run y la tarea registradas. Resultado huérfano o inconciliable → `dead/` con `.motivo.txt`.
- **Duplicados**: si la run ya no está en `waiting_ai` (p. ej. `awaiting_approval`, `approved`, `failed`), cualquier resultado nuevo del mismo run es un duplicado y va a `dead/` sin sobrescribir la revisión en curso.
- **Caducidad**: resultado con `expires_at` vencido → `dead/`; runs `waiting_ai` sin resultado tras el TTL fallan (`ai_job_expired`, espejo de Fase A) liberando la tarea.
- **Aprobación humana**: con resultado `completed` válido, la run pasa a `awaiting_approval` (diff, `base_sha`, `changed_paths` conservados) y la tarea a `waiting_approval` con `approval_required` y el diff en `result` para revisión. `record_decision` registra `approve`/`reject` con actor y motivo; nada se integra por el sistema. El paso siguiente (propuesta de rama determinista tras aprobación) queda pendiente.
- **Modo simulación**: el registro es `SimStore` (archivo JSON 0600 con runs/tareas/eventos ficticios, mismos campos y estados que `ops_dev_runs` / `ops_tasks` / `ops_events`). El cableado real a Supabase debe implementar la misma interfaz sin cambiar el consumidor.
- **Escribabilidad**: el consumidor solo mueve archivos de `results/` a `dead/` (nada se borra en silencio) y usa flock sobre `consumer.lock` para no correr dos consumidores a la vez.

## Pruebas

- Fase A: `scripts/ops/tests/dev-worker.test.py` (30 casos).
- Fase B: `scripts/ops/tests/opencode-executor.test.py` (47 casos): identificadores, rutas, paquete, buzón atómico, duplicados, caducidad, ejecución real sobre repo temporal, diff, límites, whitelist de git, sweeps y escaneo estático de la fuente (sin red, sin shell, sin rutas de sistema, sin credenciales).
- Fase C: `scripts/ops/tests/opencode-result-consumer.test.py` (28 casos): validación del paquete de resultados, conciliación con run/tarea (huérfanos, task_id/repo/public_id inconciliables), duplicados, caducidad de resultados y de runs waiting_ai, cuarentena con motivo, aprobación y rechazo humanos, persistencia de la tienda simulada y escaneo estático de la fuente (sin red, sin subprocesos, sin `/root`, sin credenciales). Datos ficticios; sin Supabase.
- Todo cableado en `.github/workflows/validate.yml` junto al `py_compile` del Ops Bridge.

## Adaptador real de CLI (Fase D)

`scripts/ops/opencode-adapter.py` envuelve la única interfaz de invocación del modelo admitida:

```
opencode run --pure -m opencode/glm-5.3-flash-free --format json
```

- **Superficie estricta**: el comando se construye por lista blanca (sin shell, sin `--auto`, sin argumentos provenientes del paquete); el prompt es un único elemento de argv (≤200k, sin NUL) y el modelo debe estar en `ALLOWED_MODELS`.
- **Entorno del hijo por lista de conservación**: solo `PATH`, `HOME`, `USER`, `LOGNAME`, `SHELL`, `LANG`, `LC_ALL`, `LC_CTYPE`, `TMPDIR`, `TERM`. Nunca hereda claves de servicio, tokens ni credenciales; el adaptador no consulta rutas de configuración del sistema.
- **Límites de tiempo y recursos**: timeout configurable 5..900 s (por defecto 600); al vencer se mata el grupo de procesos completo (SIGKILL vía `os.killpg`, sesión propia con `start_new_session=True`). El hijo corre con `RLIMIT_FSIZE` igual al máximo de salida (≤10 MB, por defecto 1 MB), `RLIMIT_CPU` (timeout + 30 s), `RLIMIT_AS` 2 GiB, `RLIMIT_NPROC` 512 y `RLIMIT_CORE=0`.
- **Validación estricta de la salida**: JSON Lines; cada línea no vacía debe ser un objeto con `type` string (≤64 caracteres); el texto de respuesta se extrae solo de eventos `type == "text"` con `part.text` o `text` string; ≤10 000 eventos. JSON inválido, objeto malformado o no UTF-8 → error de protocolo. El vocabulario de eventos se calibrará en la primera invocación real supervisada.
- **Errores tipificados** con código de salida CLI: uso 2, deshabilitado 3, bloqueado 4, timeout 5, límite de salida 6, protocolo 7, fallo de CLI 8. `stderr` se registra truncado (≤4000) y sin tratar como confiable.
- **Bloqueo de aislamiento (estado actual)**: `ISOLATION_GUARANTEED = False`. `--pure` solo desactiva plugins externos; **no** aísla archivos, comandos ni red. Mientras no existan restricciones efectivas de permisos y un entorno aislado (sandbox sin red, escritura limitada al workspace, p. ej. nsjail/bubblewrap), el adaptador se niega a ejecutar el binario real (`AdapterBlocked`) y ningún proceso invoca el modelo automáticamente.
- **Integración desactivada por defecto**: `invoke()` exige `CV_OPENCODE_MODEL_ENABLED=1` y `CV_OPENCODE_SANDBOX_ATTESTED=1`; aun con ambos activados, el binario real sigue bloqueado por la invariante anterior. El binario puede ajustarse solo para pruebas (`CV_OPENCODE_BIN`).
- **No cableado**: el ejecutor de Fase B y el consumidor de Fase C no usan el adaptador (prueba estática lo verifica). El camino determinista (operaciones estructuradas + smoke), el buzón atómico y la aprobación humana se conservan intactos.
- **Pruebas**: `scripts/ops/tests/opencode-adapter.test.py` (46 casos). **Todas las invocaciones de la suite usan un simulador de CLI** (script generado en un directorio temporal que registra argv/cwd/entorno recibido y emite escenarios controlados); ninguna prueba invoca el modelo real. Se prueban: puerta apagada por defecto, bloqueo de aislamiento, comando exacto, scrubbing de entorno, SIGXFSZ por `RLIMIT_FSIZE`, timeout con verificación de muerte real del grupo, JSONL estricto, errores de CLI y de contrato del runner, códigos de salida de `main()` y escaneo estático de la fuente (sin shell, sin red, sin `--auto`, sin secretos ni rutas de sistema).
- **Invocación real del modelo**: pendiente. Requiere sandbox efectivo (Fase E) y calibración del esquema de eventos en una sesión supervisada; hasta entonces el adaptador solo es ejecutable con simulador o runner inyectado.
