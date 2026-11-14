# Resultados de programación en Control OS

El consumidor recibe resultados de desarrollo, los guarda en Supabase y pide revisión humana. Reutiliza ops_tasks, ops_dev_runs, ops_approvals y ops_events. WooCommerce conserva la autoridad comercial.

## Qué está implementado

- Consumidor separado con cliente Supabase inyectado; SimStore conserva su demostración.
- Recibo por run con paquete completo, diff, base_sha y changed_paths.
- Una RPC SECURITY INVOKER bloquea tarea y run, concilia identidad, negocio Casa Viva, executor dev, modo IA, auto_merge=false, estado waiting_ai, SHA, rutas, tamaños y caducidad.
- Un resultado completo crea recibo, aprobación pending y evento en una transacción. Run y tarea quedan waiting_approval.
- Un resultado fallido queda failed con evidencia, sin aprobación y sin aplicar cambios.
- El reintento idéntico no duplica eventos/aprobaciones; un resultado diferente para la misma run se rechaza.
- Un error del transporte conserva el archivo. El cliente comprueba run_id y estado de respuesta antes de archivarlo.
- El buzón fija descriptores de directorios, rechaza enlaces y crea archivos de cuarentena/motivo únicos mediante O_EXCL/O_NOFOLLOW.

## Decisión humana

La migración extiende ops_decide_approval exclusivamente para aprobaciones vinculadas a un recibo IA. Comprueba tarea/run pendientes y recibo conciliado.

Aprobar deja tarea verifying, executor human y run blocked, pendiente de PR, CI y promoción técnica. No devuelve a triaged ni inicia otra programación. Rechazar bloquea el resultado sin aplicar el diff. Repetir una decisión no añade eventos. Las aprobaciones ordinarias conservan su comportamiento previo.

Aprobar NO despliega. El diff sigue siendo evidencia opaca y debe concordar con archivos y pruebas antes de promoción. Este cambio no implementa aplicar diffs, crear PR automáticamente ni desplegar.

## Migración y permisos

scripts/ops/sql/opencode-result-receipts.sql crea ops_ai_result_receipts con RLS y sin acceso público. Revoca privilegios de anon/authenticated/PUBLIC, limita service_role a SELECT/INSERT de recibos y EXECUTE de la RPC. La función no eleva permisos; usa los permisos existentes del servicio sobre tareas, runs, aprobaciones y eventos.

Se inspeccionó el esquema real: ops_dev_runs admite waiting_approval; ops_approvals.impact es JSONB. No se añaden columnas diff/result a ops_dev_runs. El resultado resumido se refleja en ops_tasks.result. El orden de locks tarea→run coincide con la decisión humana.

scripts/ops/sql/rollback-ai-result-decision.sql revoca la nueva RPC y restaura la decisión anterior. Antes de revertir, detener cualquier consumidor activo; conservar recibos, evidencias y aprobaciones. No borrar tablas ni resultados reales.

## Pruebas y límites

CI ejecuta mocks Python y PostgreSQL 17 desechable. La fixture crea tablas con los tipos/estados pertinentes y termina en ROLLBACK. Comprueba negativos, duplicados, fallo tardío de evento con rollback total, límites de permisos, decisión IA sin reencolado y aprobación ordinaria. No conecta con producción.

La generación de código mediante Codex 0.169 en el VPS ya produjo esta propuesta. El aislamiento remoto #192 permitió escribir en el workspace y bloqueó lectura de canario auth, escritura auth/root y red del ejecutor; AppArmor global sigue activo. Ese ensayo no certifica todavía el recorrido completo WhatsApp/panel→tarea→modelo→promoción→despliegue.

La migración de recibos continúa sin aplicarse. No hay servicio consumidor activado y la CLI permanece cerrada. La promoción técnica y su E2E real siguen pendientes.
