# Control OS × OpenCode — Buzón, ejecutor cvdev y consumidor de resultados

Estado: Fases A, B y C (simulación) implementadas en rama local `control-os/opencode-fase-a`. Sin push, sin merge, sin despliegue y sin acceso a Supabase real.

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
