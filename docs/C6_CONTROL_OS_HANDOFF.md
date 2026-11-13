# C6 — Control OS: cierre de estabilización y límites operativos

## Entregado en este PR
- Exponer revisiones financieras `waiting_approval` que no tengan aprobación formal `pending`, sin inventar IDs ni botones para ejecutarlas.
- Consultar tareas pendientes por separado de la ventana de 120 tareas recientes.
- Presentar estas revisiones en el panel privado.
- Identificar y relegar fragmentos breves de WhatsApp en la vista de Lennys, conservando íntegramente los registros.
- Añadir regresiones automatizadas en el workflow de validación.

## Evidencia y límite
La base de datos contiene tareas de `whatsapp-lennys-live` creadas a partir de mensajes como «Cual», «Que hiciste» y «Solo los pedidos». Los eventos `lennys_order_mirrored` muestran `grouping.attach=false`, `grouping.createTask=true` y `reason=intención nueva`.

**No está reparado el clasificador de origen**: el evento lo emite VivaBot y el código exacto del ingreso no se ha localizado en los repositorios accesibles. Cambiar solo la vista no evita nuevos registros duplicados. No se debe modificar la base de datos a ciegas, cerrar tareas históricas ni crear aprobaciones financieras automáticamente.

## Para cerrar C6 por completo
1. Inspeccionar el proceso real de ingreso en el VPS / instalación efectiva de VivaBot y localizar la rutina que genera `lennys_order_mirrored`.
2. Añadir una clasificación conservadora: mensajes de acuse o aclaración se adjuntan al asunto abierto, sin crear tarea; órdenes nuevas explícitas sí crean tarea. Mantener identificador de mensaje idempotente, trazabilidad y adjuntos.
3. Probar secuencias de varios mensajes, audios y fotos, con asuntos financieros y órdenes independientes.
4. Verificar en staging, preparar reversión, fusionar PR solo si no quedan bloqueos de seguridad y desplegar de manera controlada. Confirmar snapshot y UI privados reales tras despliegue.

## No hacer
- No enviar mensajes automáticos a Lennys, clientes ni terceros.
- No aprobar comisiones, movimientos financieros o cambios de reglas de negocio.
- No usar eventos `bridge_running` como prueba de que la interfaz web funciona.
- No confundir la aprobación de CI con despliegue efectivo en Hostinger/VPS.

## C7 — Integridad de auditoría de aprobaciones
- Consulta de aprobaciones pendientes ampliada de 40 a 500 registros.
- Si la consulta alcanza 500 filas, el panel declara la auditoría incompleta y no muestra supuestas revisiones huérfanas basadas en una comparación parcial.
- Si la consulta de tareas en espera alcanza 500 filas, también se advierte que la auditoría está incompleta.
- Validación de GitHub del commit `fbde73c2acd9fe4023321d6af9975413fc01f448`: las tres suites principales terminaron con éxito.
- **Límite conocido**: la paginación total no está implementada; la señal de incompletitud es una barrera conservadora, no una auditoría exhaustiva.

## C8 — Control desde una conversación de ChatGPT (diseño y primer diagnóstico)

### Ya implementado en código, no instalado en VPS
- Nueva acción permitida `owner_ingest_audit` en el Ops Bridge: examina hasta 2.000 archivos fuente de `/root/vivabot/src` y `/root/vivabot/scripts`; devuelve únicamente rutas y números de línea, no mensajes ni secretos.
- No ejecuta shell arbitrario, no cambia archivos, no toca aprobaciones ni envía mensajes.
- La acción solo estará disponible cuando una versión aprobada del puente se instale en el VPS; el commit de GitHub por sí solo no la activa.

### Arquitectura de conversación segura
1. ChatGPT conectado a un conector de Casa Viva autenticado (MCP o equivalente) con identidad individual; no aceptar como prueba de identidad el texto «soy Lennys» o un hashtag.
2. Conector de solo lectura inicialmente: salud, estado, tareas, historial y `owner_ingest_audit`; filtros por rol y registros de acceso.
3. Para cambios: plan/diff, pruebas, PR, revisión de impacto y confirmación explícita de la propietaria en acciones sensibles. Las peticiones normales no equivalen a una aprobación financiera.
4. El servidor verifica permisos y una política de operaciones permitidas; no ejecuta comandos arbitrarios enviados por chat. Credenciales almacenadas únicamente en el servidor y rotables.
5. Para publicar: CI, staging, copia de seguridad, comprobación de versión y rollback; devolver al chat el resultado real y el identificador de operación.
6. Registrar quién solicitó, autorizó y ejecutó cada cambio, con fecha, objeto y resultado. Aplicar idempotencia para no repetir operaciones.

### Bloqueos antes de activar
- No existe todavía un conector Casa Viva autenticado expuesto como herramienta en este chat.
- Falta comprobar el esquema de permisos y la autenticación del ingreso a `crm_ops_bridge`; **no insertar acciones directamente en esa tabla desde una conversación sin el conector**.
- Falta desplegar el bridge aprobado y probar `owner_ingest_audit` en el VPS.
- No habilitar escrituras financieras, envío de WhatsApp ni cambios de aprobaciones en la primera versión.
