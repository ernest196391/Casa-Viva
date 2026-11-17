# Control OS — primera entrega revisable
Base e859db7bd239ac7c2819f81f484cc7ec1e15e168; PR #193 sigue en conflicto, se recupera únicamente la frontera conservadora de integridad. PR #241 separado: no E2/E3 en producción ni E4. El mapa #231 y su diseño de 130 pantallas son referencia; no se certifica equivalencia visual completa.

## Alcance
Se extiende /control/ existente; incidencias del snapshot, conversaciones de Lennys (muestra, no chat vivo), aprobaciones con bloqueo de escritura ante estado incompleto o antiguo, programadores por estado/ejecutor, IA sin telemetría inventada; pedidos enlaza a centro existente. No cambia permisos, pedidos, clasificador, finanzas o publicación.

## Auditoría pendiente
#193 documenta «Cual», «Que hiciste», «Solo los pedidos» como falsas tareas, evento lennys_order_mirrored. No localizar fuente equivale a bloqueo de diagnóstico, no a reparación. Revisar artefacto real de VivaBot en VPS (solo rutas/líneas), adjuntar fragmentos a asunto abierto, preservar IDs y adjuntos, probar órdenes explícitas separadas; no borrar histórico. Aprobaciones: límite 500, bloqueo si cualquier vínculo no aparece en ventana de tareas; no equivale a paginación exhaustiva. RPC canónica debe validar vigencia al consumir decisión.

## Paquetes Qwen (manual en rama de trabajo, nunca gate productivo)
1. Solo app.js y pruebas de UI: filtros de incidencias, sin mutaciones; casos vacío, sin fuente y muestra parcial.
2. Solo style.css y pruebas de UI: 320/360/390/768 px, sin overflow horizontal, foco visible, 44 px; no alterar PHP.
3. Solo app.js y pruebas de UI: mostrar ejecutor/fecha cuando registrado, nunca deducir que está ejecutando; no nuevos endpoints.
4. Solo documentación: matriz pantalla→fuente→rol→estado para pedidos y IA, sin crear datos ficticios.
Cada paquete: diff limitado, pruebas, informe; revisión humana antes de PR. Sandbox desactivado.

## Verificación requerida
GitHub CI del SHA exacto; smoke autenticado Ernesto y Lennys, móvil y desktop, snapshot reciente/antiguo/incompleto, decisión inválida HTTP 409 sin escritura, enlaces pedidos, ausencia de navegación horizontal. Probar en staging sin acciones reales. No despliegue hasta autorización. Reversión: restaurar archivos de versión vigente y snapshot compatible; si faltara approval_complete la UI falla cerrada. E2/E3: seguir runbook #241 y aprobación previa; E4 requiere nueva autorización.
