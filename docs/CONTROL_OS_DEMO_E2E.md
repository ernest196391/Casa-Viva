# Control OS × OpenCode — Demostración E2E (Fase G)

Estado: Fases A–F reutilizadas sin modificaciones funcionales; Fase G implementada en rama local `control-os/opencode-fase-a` sobre `baf60ca`. Sin push, sin merge, sin despliegue, sin Supabase real, sin automatizaciones activadas, sin secretos y sin el adaptador de OpenCode.

## Objetivo

Demostrar de extremo a extremo el flujo completo del Control OS con datos ficticios y un repositorio Git temporal:

```
Solicitud humana (ficticia, Fase A)
        │
        ▼
Propuesta cv-change-proposal-v1 (Fase F)
        │  scripts/ops/opencode-proposal-bridge.py (puente real)
        ▼
Buzón jobs/ → Ejecutor determinista (Fase B, CLI real)
        │  worktree desechable sobre repo temporal → diff
        ▼
Resultados → Consumidor (Fase C, in-process)
        │  validación + conciliación → awaiting_approval
        ▼
Decisión humana explícita (record_decision, Fase C)
        │  approve / reject — NO integra, NO publica, NO despliega
        ▼
Verificación: HEAD, ramas, commits, árbol y remotos intactos
```

## Comando para lanzarla

```bash
python3 scripts/ops/opencode-demo-e2e.py --decision approve
```

Con terminal interactivo, omita `--decision` y el script pedirá la decisión por teclado (`a` aprobar / `r` rechazar) después de mostrar el diff. `--decision reject` ejecuta el mismo recorrido con rechazo. `--conservar` mantiene el directorio temporal (`/tmp/cv-demo-e2e-…`) para inspección.

La demostración imprime cada escena y termina con una línea `DEMO_RESULT {…}` legible por máquina (usada por las pruebas).

## Qué debería observarse

1. **Escena 1 — Solicitud humana ficticia**: tres tareas ficticias (`demo-task-1..3`) registradas en la tienda simulada (Fase C), runs en `waiting_ai`. Datos 100 % ficticios en `/tmp`.
2. **Escena 2 — Propuesta de IA**: archivo `cv-change-proposal-v1` fijo (corregir el horario de una página de demostración). Nace de un archivo, no de un modelo: el adaptador de OpenCode no se invoca y sigue bloqueado.
3. **Escena 3 — Puente real (Fase F)**: entrega OK (código 0, paquete 0644 en `jobs/`, recibo 0600 en el ledger); inmediatamente demuestra que el **reintento idéntico se rechaza (código 4)** y que una **propuesta con `run_command` se rechaza (código 3)**.
4. **Escena 4 — Ejecutor determinista real (Fase B)**: reclama el paquete, aplica la operación sobre un worktree desechable del repo temporal y produce resultado `completed` con **archivos afectados y diff unificado** en pantalla. El repo temporal queda intacto (sin commits, ramas ni cambios).
5. **Escena 5 — Consumidor (Fase C)**: valida el resultado sin confiar en el ejecutor, lo concilia con la run y la tarea y deja el cambio en `awaiting_approval` / `waiting_approval`.
6. **Escena 6 — Decisión humana (Fase C)**: `record_decision` registra la decisión con actor y motivo. **Aprobar solo registra la decisión**: no hay commit, rama, push, merge ni despliegue; la integración sigue siendo un paso humano posterior no implementado en esta fase.
7. **Escena 7 — Paquete manipulado**: se entrega una segunda propuesta y el paquete se altera después (`run_command` inyectado); el ejecutor lo rechaza (resultado `failed`, repo intacto) y el consumidor cierra la run como `failed`.
8. **Escena 8 — Resultado manipulado**: un tercer resultado `completed` se altera antes del consumidor (`base_sha` inválido); va a cuarentena `dead/` con `.motivo.txt` y la run no se toca.

Al final se imprime el resumen con las verificaciones: `repo_intacto` (árbol limpio, HEAD intacto, sin nuevos commits, solo la rama base, sin remotos) y las cuatro defensas en verde.

## Garantías demostradas

- **Aprobar ≠ desplegar**: la decisión solo muta estados en la tienda simulada; el repo temporal queda sin cambios y sin remotos, verificado programáticamente en cada escena.
- **El ejecutor no confía en nadie**: revalida el paquete entregado y rechaza el manipulado.
- **El consumidor no confía en el ejecutor**: rechaza el resultado manipulado a cuarentena.
- **El puente no confía en la propuesta**: contrato estricto, duplicados y huella de contenido.
- **Sin comandos generados por IA**: los únicos subprocesos son `git` (lectura y setup del repo ficticio) y las dos CLI fijas de las fases B y F; verificado por escaneo estático en las pruebas.
- **Aislamiento del sistema operativo: NO verificado** en esta demo; Bubblewrap/AppArmor siguen siendo una propuesta no instalada (Fase E). La demo no los instala ni los activa.

## Pruebas

`scripts/ops/tests/opencode-demo-e2e.test.py` (18 casos, cableado en `validate.yml`):

- Mundo temporal: repo ficticio con un commit, buzón provisionado, registro de solicitudes, construcción de propuestas.
- Flujo completo aprobado y rechazado: estados finales, diff correcto, archivo afectado, recibo en el ledger, eventos `ai_result_validated` / `ai_change_approved` / `ai_change_rejected`, repo sin commit/rama/push/merge/cambios.
- Defensas en el mismo mundo: duplicado (código 4), propuesta maliciosa (código 3), paquete manipulado (run `failed`), resultado manipulado (cuarentena con motivo).
- Interacción humana: sin terminal exige `--decision`, decisión inválida termina en 2.
- CLI real de la demo con subproceso (approve → 0, reject → 0, sin decisión → 2).
- Escaneo estático: sin red ni ejecución dinámica, solo `git` y las CLI fijas de las fases A–F, sin adaptador de OpenCode ni variables de activación, sin secretos ni rutas reales del repo, reutilización explícita de las fases B/C/F.

## Limitaciones

- Modo simulación (tienda local `SimStore`): sin Supabase ni conciliación con `ops_dev_runs`; hereda las limitaciones declaradas de las fases C y F.
- La generación de la propuesta es un archivo fijo de demostración; la generación real por modelo sigue bloqueada hasta contar con sandbox efectivo (Fase E) y decisión humana de activación del adaptador (Fase D).
- La integración tras aprobación (propuesta de rama determinista) queda pendiente; la demo la señala explícitamente como paso humano posterior.
