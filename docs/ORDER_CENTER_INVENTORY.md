# Inventario previo del Centro Único del Pedido

Auditoría de Fase 2A sobre la base `8138507f5a3b15f42f4c5d4a797a0e70d58e1200`. Ninguna vista se elimina.

| Superficie | Información y acciones | Acceso / endpoint | Duplicación o frontend | Función que se conserva |
|---|---|---|---|---|
| Centro de ventas `[casa_viva_sales]` | pedido, cliente, productos, operación, mensajería, gestora, comisión, cobro; cambia estado | dependienta/admin; `GET /sales`, `POST /sales/{id}/status` | `sales.js` pinta tarjetas; backend legacy arma acciones | búsqueda, resumen, QR, cobro y flujo operativo existente |
| Centro de operaciones / portal | pedidos por rol, ofertas, entregas, notificaciones | roles internos/mensajero/gestora; rutas de `CVD_Portal` y `CVD_Delivery` | polling cada 8 s y recarga completa si cambia delivery | navegación y trabajo de mensajería existente |
| Mensajería | oferta, asignación, custodia, entrega, tarifa, efectivo | dependienta/admin/mensajero; rutas `delivery` | varias etiquetas y acciones específicas | oferta, asignación, QR, tracking y liquidación |
| Inventario | producto, stock y movimientos | capacidad de inventario; rutas de `CVD_Inventory` | escaneo/búsqueda y actualización cliente | movimientos auditables y escáner |
| Contabilidad de mensajero | ledger, efectivo y liquidaciones | administración/mensajero según operación | cálculos presentacionales separados | ledger y settlements existentes |
| Comisiones/payouts | propietaria, importe, estado, pagos | gestora/admin; rutas propias | resumen parcial repetido en ventas | atribución, comisión y payout existentes |
| Seguimiento público | etapa logística y valoración | token público acotado; tracking REST | polling 15 s | seguimiento y rating del cliente |
| Detalle WooCommerce | datos oficiales, notas, stock, pago | `manage_woocommerce` | pantalla WC independiente | fuente oficial y herramientas administrativas |

## Hallazgos

- El pedido ya es la entidad común, pero no existe una proyección única por rol.
- `CVD_Sales::payload()` mezcla lectura, privacidad y selección de acciones.
- La unión correcta del historial ya existe en `CVD_Order_Event_Timeline`; no debe recrearse.
- `sales.js` y `portal.js` refrescan por polling. 2A reutiliza el patrón sin WebSockets y lo pausa cuando la pestaña no está visible.
- Las transiciones 1C están centralizadas; asignación, oferta y algunos flujos financieros continúan enlazados a superficies legacy.
