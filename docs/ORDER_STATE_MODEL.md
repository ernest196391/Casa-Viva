# Modelo real de estados del pedido

## Propósito

Este documento describe lo que el código actual hace, no una máquina futura. Las dimensiones WooCommerce, operación, mensajería, efectivo, comisión e incidencia se documentan separadamente.

El lector `CVD_Canonical_Order_Reader` calcula una interpretación en lectura. No crea ni modifica estados.

## WooCommerce

WooCommerce es la fuente oficial del pedido. Casa Viva consulta actualmente estos estados.

| Estado | Significado real | Generador actual | Actor | Anterior esperado | Posterior esperado | Efectos observados | Etapa canónica |
|---|---|---|---|---|---|---|---|
| `pending` | Pedido pendiente de pago o procesamiento | WooCommerce/checkout | Cliente o WooCommerce | Creación | `processing`, `on-hold`, `failed`, `cancelled` | El pedido existe; los metadatos Casa Viva pueden inicializarse | La determinan operación y mensajería |
| `processing` | Pedido activo en procesamiento | WooCommerce/pasarela | WooCommerce | `pending` o checkout | `completed`, `cancelled`, `refunded`, `failed` | `CVD_Commissions::mark_pending()` conserva comisión pendiente | La determinan operación y mensajería |
| `on-hold` | Pedido retenido; el gateway WhatsApp lo usa como pendiente de confirmación | `CVD_WhatsApp_Gateway::process_payment()` | Cliente/gateway | Checkout | `processing`, `completed`, `cancelled`, `failed` | Nota “Pendiente de confirmación por WhatsApp”; comisión pendiente | La determinan operación y mensajería |
| `completed` | Pedido finalizado en WooCommerce | `CVD_Delivery::change_status(closed)`, `CVD_Sales::change_status(delivered)` o administración WooCommerce | Administración; en el flujo de ventas, dependienta/admin tras confirmar dinero | Operación terminada; para mensajería se espera `closed` | Terminal normal | Invalida caché de reputación; el lector exige cierre personalizado coherente | `COMPLETED` si existe evidencia; si no, `CONFLICT` |
| `cancelled` | Pedido cancelado | `CVD_Sales::change_status(cancelled)` o WooCommerce | Administración/WooCommerce | Cualquier estado cancelable | Terminal | Sincroniza operación, mensajería, ganancia y comisión a cancelación | `CANCELLED` si es coherente |
| `refunded` | Pedido reembolsado | WooCommerce | Administración/WooCommerce | Pedido cobrado o completado | Terminal | Se trata como cancelado en ventas, mensajería y comisión | `CANCELLED` si es coherente |
| `failed` | Fallo de pago/procesamiento de WooCommerce | WooCommerce/pasarela | WooCommerce | `pending`/checkout | Puede reintentarse fuera de este flujo | Ventas lo interpreta como cancelado y comisiones se cancelan; mensajería no tiene hook equivalente para `failed` | `CANCELLED` con `WARNING`, o `CONFLICT` si ya hay mensajería activa |

## Operación Casa Viva

Metadato: `_cvd_operation_status`. Clase: `CVD_Sales`.

Actores generales: usuario con `cvd_manage_sales` (dependienta/operación) o `manage_woocommerce` (administración). La cancelación desde el Centro de ventas exige administración.

| Estado | Significado real | Función/acción generadora | Actor autorizado | Anterior esperado | Posterior esperado | Efectos secundarios | Etapa canónica |
|---|---|---|---|---|---|---|---|
| `new` | Pedido nuevo para el Centro de ventas | `CVD_Sales::initialize_order()` en checkout | Sistema | Sin metadato | `preparing`, `incident`, `cancelled` | Guarda fecha operativa; envía aviso de pedido nuevo | `CREATED` |
| `confirmed` | Pedido confirmado | Solo transición de recuperación desde `incident`; no existe transición normal `new → confirmed` | Dependienta/admin | `incident` | `preparing`, `incident`, `cancelled` | Historial y nota operativa | `CONFIRMED` |
| `preparing` | Pedido en preparación | `CVD_Sales::change_status()` | Dependienta/admin | `new`, `confirmed` o recuperación de `incident` | `ready`, `incident`, `cancelled` | Historial, fecha y nota | `PREPARING` |
| `ready` | Pedido listo | `CVD_Sales::change_status()` | Dependienta/admin | `preparing` o recuperación de `incident` | `incident`, `cancelled`; mensajería avanza por su eje | Si es entrega a domicilio llama `CVD_Delivery::publish_offer()` | `READY_FOR_PICKUP` o `READY_FOR_COURIER` |
| `with_courier` | Custodia física transferida al mensajero | `CVD_Delivery::sync_operation()` cuando mensajería entra en `picked_up` | Sistema como consecuencia de confirmación de dependienta/admin | `ready` normalmente | `delivered`, `incident`, `cancelled` | Historial operativo; conserva el eje logístico detallado | `PICKED_UP` como mínimo |
| `delivered` | En operación significa dinero recibido y proceso completado | `CVD_Sales::change_status()` tras confirmar método e importes | Dependienta/admin | `with_courier`; para pickup, etapa operativa aplicable | Terminal | Guarda cobro; intenta cerrar mensajería; aprueba comisión; completa WooCommerce | `COMPLETED`, solo si los demás ejes son coherentes |
| `incident` | Incidencia operativa activa; no es una etapa logística | `CVD_Sales::change_status()` | Dependienta/admin | `new`, `confirmed`, `preparing`, `ready`, `with_courier` | `confirmed`, `preparing`, `ready`, `with_courier`, `cancelled` | Historial y nota; sustituye temporalmente el valor operativo | Etapa previa demostrada por historial; si falta, `WARNING`/`CONFLICT` |
| `cancelled` | Operación cancelada | `CVD_Sales::sync_cancelled()` o cancelación administrativa | Sistema/admin | Estado operativo activo | Terminal | Sincroniza con WooCommerce en la acción administrativa | `CANCELLED` si WooCommerce concuerda |

## Mensajería

Metadato: `_cvd_delivery_status`. Clase: `CVD_Delivery`.

| Estado | Significado real | Función/acción generadora | Actor autorizado | Anterior esperado | Posterior esperado | Efectos secundarios | Etapa canónica |
|---|---|---|---|---|---|---|---|
| `unassigned` | Pedido sin mensajero/oferta activa | `initialize_order()` o desasignación | Sistema; admin/dependienta al asignar | Sin metadato o asignado | `offered`, `assigned`, `incident` | Inicializa importes de mensajería y ganancia pendiente | No adelanta etapa por sí solo |
| `offered` | Carrera publicada | `publish_offer()` / acción Publicar oferta | Dependienta/admin/sistema desde `ready` | `unassigned` | `assigned`, `accepted`, `incident` | Selecciona invitados, guarda ranking, notifica y puede ampliar oleada | `READY_FOR_COURIER` |
| `assigned` | Mensajero asignado directamente | `assign()` desde administración o portal | Dependienta/admin | `unassigned` u `offered` | `accepted`, `incident` | Guarda mensajero, historial, nota y correo | `COURIER_ASSIGNED` |
| `accepted` | Mensajero aceptó la carrera | `offer_decision()` o transición autorizada | Mensajero asignado/admin | `offered` o `assigned` | `to_store`, `picked_up`, `incident` | Guarda hora de aceptación | `COURIER_ASSIGNED` |
| `to_store` | Mensajero va hacia la tienda | `change_status()` | Mensajero asignado/admin | `accepted` | `picked_up`, `incident` | Guarda `_cvd_to_store_at`; notifica | `COURIER_GOING_TO_PICKUP` |
| `picked_up` | **Custodia transferida al mensajero**; la dependienta entregó físicamente el pedido | `handover_by_staff()` mediante QR o Centro de ventas; también transición de staff | Dependienta/admin | `accepted`, `to_store` | `handed_over`, `incident` | Guarda quién/cuándo entregó; sincroniza operación a `with_courier`; notifica | `PICKED_UP` |
| `handed_over` | **Mensajero en ruta hacia el cliente** | `change_status()` con acción “En camino al cliente” | Mensajero asignado/admin | `picked_up` | `delivered`, `failed`, `returned`, `incident` | Guarda `_cvd_to_customer_at`; habilita tracking en vivo; notifica | `ON_THE_WAY_TO_CUSTOMER` |
| `delivered` | **Cliente recibió el pedido, pero puede faltar reconciliación** | `change_status()` con “Entregado” | Mensajero asignado/admin | `handed_over` | `cash_returned`, `incident` | Guarda entrega; pone efectivo `pending_return`; notifica | `DELIVERED` |
| `cash_returned` | **El efectivo regresó a Casa Viva** | `change_status()` o `close_after_cash_received()` | Dependienta/admin | `delivered` | `closed`, `incident` | Efectivo `returned`; marca revisión de comisión lista | `PAYMENT_RECONCILED` |
| `closed` | **Cierre definitivo de la entrega** | `change_status()` o `close_after_cash_received()` | Administración; la función automática se ejecuta tras confirmación de dinero | `cash_returned` | Terminal | Efectivo `verified`; ganancia aprobada; WooCommerce completado; ledger acreditado; comisión aprobada | `COMPLETED` |
| `incident` | Incidencia logística activa, separada de la etapa | `change_status()` con nota obligatoria | Mensajero asignado, dependienta o admin según etapa | Cualquier etapa activa permitida | `assigned`, `accepted`, `to_store`, `picked_up`, `handed_over`, `delivered`, `returned`, `failed` | Historial, nota y notificación; el pedido permanece visible | Etapa previa demostrada por el último evento; si falta, `WARNING`/`CONFLICT` |
| `failed` | Entrega no completada después de salir hacia el cliente | `change_status()` | Mensajero asignado/admin | `handed_over` o resolución de `incident` | Terminal en el portal actual | Historial y notificación; no equivale a WooCommerce `failed` | `DELIVERY_FAILED` |
| `returned` | Pedido devuelto a Casa Viva | `change_status()` | Mensajero asignado/admin | `handed_over` o resolución de `incident` | No hay transición posterior normal documentada | Historial y notificación | `DELIVERY_FAILED` |
| `cancelled` | Carrera cancelada por cancelación/reembolso del pedido | `sync_cancelled()` | Sistema por WooCommerce | Cualquier etapa no terminal | Terminal | Cancela ganancia; anula contabilidad asociada; revierte efectos de reputación aplicables | `CANCELLED` |

## Efectivo y liquidación de entrega

Metadato: `_cvd_cash_status`.

| Estado | Significado real | Generador | Actor | Anterior esperado | Posterior esperado | Efectos | Correspondencia canónica |
|---|---|---|---|---|---|---|---|
| sin valor | No existe registro de efectivo en esta etapa o pedido | Estado inicial | Sistema | Creación | `pending_return` cuando aplica | Ninguno | No determina etapa |
| `pending_return` | El cliente recibió el pedido y el efectivo está con el mensajero | `CVD_Delivery::change_status(delivered)` | Mensajero/admin | Sin valor | `returned` | Guarda entrega y mantiene dinero pendiente | Compatible con `DELIVERED` |
| `returned` | Efectivo entregado a Casa Viva | `cash_returned` / `close_after_cash_received()` | Dependienta/admin | `pending_return` | `verified` | Guarda actor y hora; habilita revisión de comisión | Compatible con `PAYMENT_RECONCILED` |
| `verified` | Efectivo verificado y cierre contable de la entrega | `closed` / `close_after_cash_received()` | Administración o cierre automático posterior a confirmación de dinero | `returned` | Terminal | Aprueba ganancia, acredita ledger y permite cierre | Compatible con `COMPLETED` |

El estado de ganancia del mensajero (`_cvd_messenger_earning_status`) es otra dimensión contable y no se usa como etapa logística.

## Comisión de gestora

Metadato: `_cvd_commission_status`. Clase: `CVD_Commissions`.

| Estado | Significado real | Generador | Actor | Anterior esperado | Posterior esperado | Efectos | Correspondencia canónica |
|---|---|---|---|---|---|---|---|
| sin valor | Pedido orgánico o metadato todavía no inicializado | Checkout sin propietaria o dato histórico | Sistema | Creación | `pending` si existe gestora | Ninguno | No determina etapa |
| `pending` | Comisión calculada, todavía por verificar | Hooks de checkout, `processing` y `on-hold` | Sistema | Sin valor | `approved`, `cancelled` | Calcula importe, base, markup, tasa y posible riesgo de autop pedido | Separada; si está adelantada puede generar `WARNING` |
| `approved` | Venta validada para liquidación | Cierre de entrega, finalización operativa o edición administrativa | Sistema/admin | `pending` | `paid`, `cancelled` | Registra historial y habilita inclusión en payout | Separada |
| `paid` | Comisión incluida en una liquidación pagada | `CVD_Payouts` llama `CVD_Commissions::mark_paid()` | Administración | `approved` | `cancelled` según reglas actuales | Historial, nota y estado de payout | Separada |
| `cancelled` | Comisión anulada | Cancelación, reembolso, fallo WooCommerce o administración | Sistema/admin | `pending`, `approved` o `paid` según reglas actuales | Terminal | Historial y nota cuando es forzada | Separada; debe concordar con pedido cancelado/fallido |

## Incidencias

No existe todavía una entidad independiente para incidencias: el código actual utiliza `incident` dentro de operación y/o mensajería y conserva una nota en el historial.

Reglas de interpretación:

1. `incident` indica incidencia activa; no sustituye conceptualmente la etapa normal.
2. El lector solo recupera la etapa anterior si el último evento válido demuestra una transición `etapa → incident`.
3. Si el historial está vacío, truncado, malformado o su último evento no abre la incidencia actual, la etapa de ese eje queda indeterminada.
4. Otro eje puede aportar una etapa mínima con `WARNING`; si la combinación no puede resolverse con certeza, el resultado es `CONFLICT`.
5. No se corrige ningún metadato automáticamente.

## Regla de precedencia del lector

1. Validar que todos los valores pertenezcan a catálogos conocidos.
2. Tratar cancelación/reembolso WooCommerce y contradicciones terminales.
3. Separar y recuperar incidencias solamente con evidencia de historial.
4. Comparar operación y mensajería mediante combinaciones que el flujo actual puede producir.
5. Contrastar efectivo y comisión sin convertirlos en etapa logística.
6. Devolver `OK`, `WARNING` o `CONFLICT`, junto con razones y datos utilizados.

## Limitaciones comprobadas

- El historial operativo conserva como máximo 100 eventos.
- El historial de mensajería conserva como máximo 150 eventos.
- `confirmed` no forma parte del camino normal actual desde `new`.
- WooCommerce `failed` cancela operación/comisión por lectura o hook, pero no activa el hook de cancelación de mensajería.
- Pedidos históricos pueden no tener alguno de los metadatos.
- La ausencia de evidencia nunca autoriza una corrección automática.
