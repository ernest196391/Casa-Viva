# Centro Único del Pedido — Fase 2A

`CVD_Order_Center` añade una proyección operativa y una pantalla mobile-first sin sustituir Centro de ventas, mensajería, inventario ni WooCommerce.

## Arquitectura

La proyección consume `WC_Order`, `CVD_Canonical_Order_Reader`, `CVD_Order_Event_Timeline` y `CVD_Order_Transition_Service`. No escribe estados directamente. El endpoint `GET/POST /casa-viva/v1/order-center/{id}` exige `cvd_manage_sales` o `manage_woocommerce`.

El modelo contiene `order`, `customer`, `items`, `pricing`, `operation`, `delivery`, `courier`, `payment`, `commission_summary`, `gestora`, `incident`, `canonical_stage`, `consistency`, `timeline` y `available_actions`.

## Roles y privacidad

- Administración recibe razones internas de coherencia e identificador de atribución.
- Dependienta recibe teléfono/dirección necesarios para operar, pero no diagnósticos internos ni el identificador interno de gestora.
- Un actor sin las capacidades anteriores no accede al endpoint.
- No existe payload universal para cliente, mensajero o gestora; sus futuras proyecciones deberán aplicar listas explícitas de campos.

## Acciones

`available_actions` deriva los destinos del catálogo privado del servicio mediante `available_targets()`, aplicando el mismo permiso y origen. Cada acción incluye ID, label, dominio, destino, confirmación, campos requeridos, capability y bloqueo. La UI nunca compara estados para decidir botones. Al ejecutar, el servidor recalcula la proyección y rechaza acciones obsoletas; luego llama exclusivamente a `transition()`.

Las acciones todavía legacy (oferta manual, asignación, cobros complejos, cancelación y edición financiera) no se duplican en 2A y permanecen en sus vistas actuales.

## Consistencia, timeline y actualización

`WARNING` se muestra discretamente. `CONFLICT` sustituye acciones por “Revisión requerida” y solo administración recibe los motivos técnicos. El timeline unifica canónico y legacy y carga 50 eventos por página; la consulta del repositorio obtiene los eventos del pedido en una sola operación y los productos proceden de la colección ya cargada por WooCommerce.

La pantalla consulta cada 8 segundos, solo cuando está visible, compara una huella de etapa/coherencia/acciones/timeline y actualiza sin recargar la página. `pagehide` detiene el temporizador. No se introducen WebSockets.

## Uso y compatibilidad

Crear una página WordPress con slug `centro-pedido` y shortcode `[casa_viva_order_center]`; abrirla con `?order_id=123`. Todas las vistas legacy coexisten. Las futuras fases pueden añadir proyecciones específicas de cliente, mensajero y gestora sin ampliar este payload interno.
