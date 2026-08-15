# Inventario de historiales de pedido — Fase 1B

Este inventario describe el código existente antes de crear la persistencia canónica. No cambia ninguna fuente actual.

| Fuente | Contenido real | Escritor | Límites / faltantes |
|---|---|---|---|
| `_cvd_operation_history` | `from`, `to`, `user_id`, `at` | `CVD_Sales::change_status()` y `CVD_Delivery::sync_operation()` | Conserva solo 100. Sin ID, rol, origen ni metadata. La inicialización y algunas sincronizaciones no siempre generan entrada. |
| `_cvd_delivery_history` | `from`, `to`, `actor_user_id`, `at`, `data` | `CVD_Delivery::append_event()` | Conserva solo 150. Sin ID, rol ni origen normalizado. `incident` está mezclado con la etapa logística actual. |
| `_cvd_commission_history` | `from`, `to`, `user_id`, `at` | `CVD_Commissions::store()` únicamente al forzar un cambio | Conserva solo 50. La creación inicial `pending` no siempre genera entrada. Sin ID, rol, origen ni desglose. |
| Notas WooCommerce | Texto, fecha, autor de la nota y visibilidad | `add_order_note()` en ventas, mensajería, comisiones, checkout, seguimiento y push | Texto no estructurado. Algunas notas duplican historiales y otras son comunicación o texto libre; no deben interpretarse como transiciones sin evidencia estructurada. |
| `_cvd_cash_status` y metadatos asociados | Estado actual `pending_return`, `returned` o `verified`, más actor/fecha para devolución y verificación | `CVD_Delivery::change_status()` y `close_after_cash_received()` | No existe historial de efectivo propio. Solo quedan instantáneas y parte de la secuencia aparece mezclada en mensajería. |
| `cvd_messenger_ledger` | Asientos de ganancia por pedido con UUID, actor, fecha, estado y metadata | `CVD_Messenger_Accounting::credit_order()` / `void_order()` y liquidaciones | Es un libro contable, no un timeline general. No debe modificarse ni usarse para reconstruir etapas. Sus cambios posteriores de estado no tienen un evento por pedido equivalente. |
| `cvd_payout_events` | Eventos de pagos a gestoras (`from_status`, `to_status`, actor, fecha, metadata) | `CVD_Payouts` | Está asociado al payout, no siempre a un pedido individual. No debe confundirse con comisión del pedido. |

## Notas WooCommerce relevantes encontradas

- `Operación Casa Viva: … → ….`
- `Mensajería: … → ….`
- `Comisión Casa Viva: … → ….`
- Asignación/aceptación de carrera.
- Cancelación/cierre generados por cambios WooCommerce.
- Selección de recogida en tienda y confirmación del cliente.

El lector legacy utiliza los tres arrays estructurados como evidencia primaria. No convierte notas libres en eventos ni inventa eventos para cubrir huecos.

## Conclusión del inventario

Ninguna fuente existente ofrece simultáneamente cronología ilimitada, esquema común, dominio, actor/rol/origen e idempotencia. La capa 1B debe ser aditiva, conservar esas fuentes, almacenar eventos nuevos fuera del pedido y poder presentar entradas legacy identificadas como tales.
