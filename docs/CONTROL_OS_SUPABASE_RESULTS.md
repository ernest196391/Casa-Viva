# Resultados Control OS en Supabase — preparación local

Objetivo: el actor consumidor interno recibe `opencode-result-v1`, concilia run bigint y tarea UUID y solicita revisión humana. Fuente de verdad: `ops_dev_runs`, `ops_tasks`; evidencia del resultado: `ops_ai_result_receipts`. No cambia la autoridad comercial ni el flujo de WooCommerce.

## Flujo y aceptación

`opencode-supabase-result-consumer.py` importa la validación existente sin modificar `SimStore`. `SupabaseResultStore(client)` recibe un cliente compatible con `client.rpc(nombre, parámetros).execute()`. No carga configuración, archivos de secretos ni variables de credenciales. Su CLI permanece cerrada; no se instala ni activa servicio alguno. La llamada real al cliente es la frontera de transporte futura, probada aquí solo con mocks, sin añadir dependencias.

Cada archivo válido invoca exclusivamente `ops_accept_opencode_result(p_result jsonb)`. La RPC bloquea run y tarea, comprueba identidad, public_id cuando viene informado, repo, modo IA, auto_merge deshabilitado, estados, base SHA y allowlist persistida. Rechaza tareas con otras runs activas. Los prefijos de directorio deben terminar en `/`; otros valores son rutas exactas. Verifica tamaños y ventana temporal también en SQL. El diff es evidencia opaca: nunca se ejecuta ni aplica; su concordancia semántica con los archivos requiere revisión posterior.

Un resultado completo crea recibo + aprobación `pending` + evento, deja run `awaiting_approval`, tarea `waiting_approval` y `approval_required=true`. Un fallo crea recibo y evento y deja ambos en `failed`, sin cambios aplicados. Ninguna ruta crea aprobación aceptada ni integra, promueve, hace push o despliega. La decisión humana real posterior no se implementa aquí: la fila pendiente usa `ops_approvals`, que ya consulta el panel; `record_decision` de SimStore solo pertenece a demostraciones.

La PK del recibo deduplica por run; la comparación del JSONB completo acepta reintentos idénticos y rechaza conflictos. No se deduplica el mismo diff entre tareas independientes. Un error RPC conserva el archivo, incluso ante respuesta perdida después del commit. El duplicado exacto en SQL no vuelve a mutar estados ni crear aprobaciones. El validador local sigue rechazando resultados caducados antes de RPC; un recibo ya confirmado cuyo archivo caducó se concilia manualmente, sin promoverlo. No se añade otra política de expiración de runs.

## Migración propuesta, no aplicada

`scripts/ops/sql/opencode-result-receipts.sql` debe revisarse y aplicarse dentro de una transacción administrativa independiente. Crea tabla de recibos con RLS sin políticas públicas y una única función `SECURITY INVOKER`, con `search_path` explícito. Revoca acceso público/anon/authenticated; concede solo SELECT/INSERT del recibo y EXECUTE de la RPC a `service_role`. No amplía permisos sobre tablas existentes: ese rol debe disponer ya de SELECT/UPDATE de runs/tareas, INSERT de eventos/aprobaciones y permisos de secuencias correspondientes. La función no puede elevar permisos.

Antes de aplicar, comprobar en un entorno autorizado los constraints/enum de estados y las columnas/tipos/defaults de `ops_tasks`, `ops_approvals`, `ops_events`. La propuesta usa las columnas observadas en consumidores locales: `requested_for_role`, `warning`, `impact`, `proposed_change`, `status`, `thread_id`, `claim_expires_at`, etc.; no se certificó el DDL real de estas tablas. `requested_for_role='owner'` y `status='pending'` requieren compatibilidad con el esquema real. `logs` se guarda como texto JSON en la columna existente. No escribe diff/result en `ops_dev_runs`. Una incompatibilidad aborta toda la RPC, nunca usa escrituras parciales como alternativa.

No retirar una tabla con recibos reales para revertir: primero detener el consumidor y revocar EXECUTE; conservar evidencia y aprobaciones pendientes para revisión. No se aplica reversión destructiva automática.

## Evidencia y límites

Suite Python nueva: mocks del cliente, rechazo antes del transporte, respuestas inválidas y conservación del buzón ante errores de conciliación/estado/duplicado/fallo tardío. Estas pruebas verifican el cliente, no prueban transacciones PostgreSQL. Fixture SQL separada para base vacía desechable con roles Supabase existentes: crea esquema mínimo, incluye migración, prueba negativos, duplicados y fallo tardío de evento, y termina en ROLLBACK. No ejecutada por instrucción explícita de no ejecutar SQL real. No certifica concurrencia real, permisos efectivos ni constraints de producción; requiere validación posterior autorizada. No se hicieron llamadas de red ni lecturas de credenciales.
