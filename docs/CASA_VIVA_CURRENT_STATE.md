# Casa Viva — estado actual

- 1A–1C.4: validadas e integradas.
- 2A: Centro Único del Pedido validado e integrado.
- 2B: notificaciones descriptivas y enlaces directos validados e integrados.
- 2C: WhatsApp, llamada y navegación directa validados e integrados.
- 2D: Centro Operativo del Mensajero validado e integrado.
- 3A: Navegación móvil del cliente validada e integrada.
- 3B: Pedidos del cliente validado e integrado.
- 3C: Detalle único del pedido del cliente en implementación y validación.

Rama 3C: `codex/customer-order-detail-center`.

Objetivo 3C: sustituir el detalle genérico de WooCommerce por una representación mobile-first del pedido para su propietario. Reúne estado canónico seguro, productos, total, modalidad/dirección de entrega y seguimiento derivado del timeline canónico y legacy, sin exponer comisión, actores, claves de idempotencia, diagnósticos internos ni metadata operativa. WooCommerce continúa siendo la fuente oficial del pedido y esta fase no escribe estados ni transiciones.
