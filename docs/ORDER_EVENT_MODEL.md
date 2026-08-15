# Historial canónico de eventos — Fase 1B

Consultar primero `CASA_VIVA_BLUEPRINT.md`, `ORDER_STATE_MODEL.md` y `ORDER_EVENT_HISTORY_INVENTORY.md`.

## Esquema

Cada fila de `{$wpdb->prefix}cvd_order_events` es inmutable y contiene:

- `event_id`: `cv_evt_` + SHA-256 de la clave idempotente.
- `idempotency_key`: SHA-256 con índice único.
- `order_id`.
- `event_type`: nombre `dominio.acción`.
- `domain`: `order`, `operation`, `delivery`, `payment`, `commission` o `incident`.
- `from_state`, `to_state`.
- `actor_user_id`, `actor_role`.
- `occurred_at` (UTC), `source`, `metadata` JSON y `created_at`.

No existen operaciones de actualización o borrado en el repositorio de eventos.

## Idempotencia

El productor genera una clave a partir de pedido, dominio, transición, origen y un ancla estable de la acción. Las nuevas entradas legacy de Casa Viva reciben un UUID de observación reutilizado por el evento canónico; así, un reintento de la misma observación conserva la clave, mientras una transición legítima posterior recibe otra. Para WooCommerce se usa la fecha de modificación del pedido. El `event_id` es determinista y la base de datos impone unicidad tanto al ID como al hash de idempotencia mediante `INSERT IGNORE`.

Una doble pulsación, reintento o repetición del hook con la misma acción no crea otra fila. Una transición legítima posterior utiliza otra ancla y conserva ambos eventos.

## Escritura aditiva

Los escritores legacy continúan funcionando. Después de guardar una transición ya autorizada emiten `cvd_order_transition_observed`; el observador añade el evento, pero no decide ni ejecuta la transición. Los cambios WooCommerce se observan con `woocommerce_order_status_changed`.

Después de persistir `delivered`, `cash_returned` o `closed`, se observa además el cambio de pago correspondiente (`pending_return`, `returned`, `verified`) sin que la capa de eventos escriba esos estados. Una entrada o salida de `incident` se representa en el dominio `incident`, por separado de la secuencia logística.

## Lectura

`CVD_Order_Event_Timeline::for_wc_order()` combina:

1. filas canónicas nuevas;
2. entradas estructuradas legacy aún disponibles.

Las entradas legacy llevan `source=legacy` y `metadata.legacy_source`. No se convierten notas libres ni se rellenan huecos. La deduplicación compara dominio, estados y timestamp normalizado, y consume coincidencias una a una: una fila canónica elimina como máximo una representación legacy. Así no desaparecen dos eventos legítimos parecidos. El resultado se ordena por UTC y secuencia interna, y se pagina en lectura sin eliminar filas.

Los empates de timestamp canónicos se resuelven mediante el ID autoincremental interno de la tabla (`sequence_id` en lectura), no mediante el hash del evento. Los fallos SQL generan un registro de diagnóstico y el hook `cvd_order_event_store_failed`, sin interrumpir la transición observada.

No se realiza migración masiva. Un pedido sin eventos devuelve un timeline vacío.
