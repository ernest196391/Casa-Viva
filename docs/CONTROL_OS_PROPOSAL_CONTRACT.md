# Control OS × OpenCode — Contrato de propuestas de IA y puente (Fase F)

Estado: Fases A–E reutilizadas sin modificaciones funcionales; Fase F implementada en rama local `control-os/opencode-fase-a`. Sin push, sin merge, sin despliegue, sin Supabase real y sin automatizaciones activadas.

## Papel de la Fase F en la cadena

```
Generación remota (Fase D, bloqueada y sin cablear)
        │  produce SOLO texto; nadie lo ejecuta
        ▼
Propuesta JSON (cv-change-proposal-v1)  ← contrato estricto + validador
        │  scripts/ops/opencode-proposal.py
        ▼
Puente (Fase F)                         ← valida, deduplica, construye paquete
        │  scripts/ops/opencode-proposal-bridge.py
        ▼
Buzón cvdev jobs/ (Fase A/B)            ← paquete opencode-ai-job-v1, entrega atómica
        ▼
Ejecutor determinista (Fase B)          ← worktree desechable, git whitelisted, sin red
        ▼
Resultado con diff (Fase B) → Consumidor (Fase C) → aprobación humana → integración
```

La generación remota y el ejecutor quedan **estructuralmente separados**: el ejecutor no conoce el contrato de propuestas ni el adaptador (verificado por escaneo estático), y el puente no invoca al adaptador, no ejecuta texto generado por la IA, no usa red ni lanza subprocesos.

## Contrato de propuesta (`cv-change-proposal-v1`)

`scripts/ops/opencode-proposal.py`. Claves permitidas (whitelist estricta, cualquier clave adicional → rechazo):

| Clave | Regla |
| --- | --- |
| `version` | Exactamente `cv-change-proposal-v1` |
| `proposal_id`, `run_id` | Identificadores seguros (`^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$`, rutina del ejecutor Fase B) |
| `task_id` | String 1..200 |
| `task_public_id` | Entero o ausente (nunca booleano) |
| `repo` | Solo `casa-viva` |
| `title`, `reason` | Strings acotados (200 / 2000) |
| `created_at`, `expires_at` | ISO-8601; `expires_at > created_at`; vencida → rechazo |
| `patch_format` | Solo `structured-v1` |
| `allow_paths` | Lista no vacía de rutas relativas estrictas |
| `operations` | Lista 1..8 de operaciones estructuradas |

Operaciones admitidas (reutilizadas del ejecutor de Fase B, mismas rutinas `validate_operations`): solo `create_file`, `replace_file` y `text_replace` con `expected_count` 1..5. Cualquier otra operación (`run_command`, `delete_file`, `chmod`, `shell`, …), cualquier clave extra por operación y el canal determinista (`write_smoke_file`) **no forman parte del contrato de propuestas IA** y se rechazan. No existe ninguna vía para comandos arbitrarios.

## Validador (rechazos)

- **Rutas**: absolutas, traversal (`..`), `.git`, rutas protegidas del repo (`.github/`, `package.json`, `package-lock.json`), >220 caracteres, barra invertida, NUL y `~` inicial.
- **Symlinks**: el puente hace un precheck contra el repo real (`precheck_symlinks`) y rechaza rutas que crucen un symlink o cuyo target sea symlink; el ejecutor de Fase B vuelve a comprobarlo dentro del worktree (`workspace_target`).
- **Secretos**: cualquier texto (`title`, `reason`, `content`, `old`, `new`) con forma de credencial (patrones JWT, `sk-…`, `sbp_…`, `Bearer …` compartidos con la Fase A) → **rechazo**, no redacción.
- **Tamaños**: ≤8 operaciones, contenido ≤120 000, paquete ≤2 MB (heredados del ejecutor).
- **Caducidad**: propuesta vencida o con `expires_at ≤ created_at` → rechazo.

## Puente (`opencode-proposal-bridge.py`)

1. Carga la propuesta desde un **archivo local** (nunca red, nunca stdin, nunca argumentos del modelo).
2. Valida con el contrato de Fase F.
3. Bloquea **duplicados** antes de entregar:
   - misma `proposal_id` ya entregada (registro de recibos),
   - misma huella de contenido (`proposal_fingerprint`: SHA-256 del cambio sin identificadores ni tiempos) con otra `proposal_id`,
   - `run_id` ya presente en `jobs/`, `claims/`, `results/` o `dead/` del buzón.
   El registro de recibos vive en `CV_PROPOSAL_LEDGER` (por omisión `<buzón>/../proposal-ledger`), un archivo JSON 0600 por propuesta. El ejecutor conserva además sus propias barreras de Fase B (rename atómico, flock, resultado previo).
4. Construye el paquete **reutilizando el constructor de la Fase A** (`build_ai_job`: whitelist, redacción defensiva, TTL 45 minutos) — sin duplicar lógica.
5. **Revalida el paquete con el validador del ejecutor de Fase B** (`validate_job`), que no confía en el productor.
6. Entrega atómica en `jobs/` (tmp + rename, archivo 0644, reutilizando `write_ai_job` de la Fase A) y escribe el recibo.

Códigos de salida del CLI: 0 entregada, 2 uso, 3 contrato, 4 duplicada, 5 caducada, 6 ruta insegura, 7 entorno/buzón, 8 interno.

## Separación generación ↔ ejecución

- El adaptador de Fase D sigue con `ISOLATION_GUARANTEED = False` y **sin cablear** con el ejecutor, el puente o el contrato (pruebas estáticas lo verifican en ambas direcciones).
- El ejecutor de Fase B no fue modificado: ignora por completo la Fase F (escaneo estático).
- El puente y el validador no importan ni usan `urllib`/`socket`/`http`/`ssl`/`subprocess` y no contienen `eval`/`exec`/`Popen`/`system` (escaneo estático AST).

## Aprobación humana

La entrega del puente solo habilita la **ejecución aislada** que produce un diff para revisión (Fase B) y el estado `awaiting_approval` (Fase C). La integración de cambios sigue requiriendo la decisión humana del consumidor (`record_decision` con actor y motivo); ni el puente ni el contrato integran nada: sin commit, push, merge ni despliegue.

## Pruebas

`scripts/ops/tests/opencode-proposal.test.py` (44 casos, cableado en `validate.yml`):

- Contrato: versión, whitelist de claves, identificadores, timestamps, tamaños, huella de contenido.
- Maliciosas: rutas absolutas/traversal/backslash/`~`/`.git`/protegidas, operaciones no autorizadas (`run_command`, `delete_file`, …), claves extra en operaciones, claves `commands`/`shell`/`deterministic` a nivel de propuesta, secretos en título/razón/contenido, caducidad.
- Symlinks: symlink padre, symlink target, repo ausente (precheck del puente con repo temporal).
- Duplicados: misma propuesta dos veces, mismo contenido con otra `proposal_id`, misma `proposal_id` con otro contenido, `run_id` presente en `jobs`/`results`/`dead`.
- Extremo a extremo: propuesta → puente → ejecutor real sobre repo temporal → resultado `completed` con diff; rechazo del ejecutor ante paquete entregado y luego alterado (no confía en nadie); repo sin commits, ramas ni cambios residuales.
- CLI: códigos 0/4/2/3/5 con subproceso real del puente.
- Estáticos: sin red/shell/ejecución dinámica, sin `/root`, separación estructural (adaptador ↔ puente ↔ ejecutor), módulo de contrato puro.

## Limitaciones declaradas

- El registro de recibos es local (directorios); no hay Supabase ni conciliación con `ops_dev_runs` en esta fase.
- El puente corre en el rol del worker root; la entrega es puntual e invocada a mano o por el worker con propuesta ya seleccionada para preparación. No hay automatización activada.
- El precheck de symlinks del puente inspecciona el checkout configurado, no el worktree futuro; la garantía real dentro del worktree sigue siendo la del ejecutor de Fase B.
- El huella anti-duplicados bloquea re-envíos idénticos por diseño: si un cambio debe repetirse, se emite una propuesta con contenido distinto o se revisa el recibo a mano (nada se borra silenciosamente).
- `npm run lint/typecheck/build` no ejecutables en este equipo (limitación conocida documentada en fases anteriores).
