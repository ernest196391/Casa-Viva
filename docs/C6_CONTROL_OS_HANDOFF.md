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
