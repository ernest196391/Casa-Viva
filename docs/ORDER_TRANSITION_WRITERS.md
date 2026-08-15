# Inventario de escritores y matriz de transiciones — Fase 1C

## Alcance y método

Inventario construido desde el comportamiento real de `CVD_Sales`, `CVD_Delivery`,
`CVD_Commissions`, `CVD_Messenger_Accounting`, `CVD_Payouts`, el gateway de
WhatsApp y los hooks WooCommerce. No propone estados nuevos ni cambia reglas.

## Escritores actuales

| Dominio / dato | Archivo · clase · método | Desde → hacia | Actor / capability | Validaciones y precondiciones | Side effects, hooks y notificaciones | Stock / contabilidad | Idempotencia y concurrencia |
|---|---|---|---|---|---|---|---|
| Operación inicial | `class-cvd-sales.php` · `CVD_Sales::initialize_order()` | vacío → `new` | sistema / hook checkout | solo si falta el metadato | fecha, `save`, evento observado, push de pedido nuevo | sin stock / comisión se inicializa en otro hook | comprobación previa; sin lock; hooks repetidos pueden reenviar push |
| Operación cancelada | `class-cvd-sales.php` · `sync_cancelled()` | activo → `cancelled` | sistema / hook WC cancel/refund | pedido existente | fecha y evento observado | la mensajería y comisión cancelan en hooks separados | estado evita evento doble; sin transacción entre hooks |
| Operación manual | `class-cvd-sales.php` · `change_status()` | mapa operativo legacy | dependienta `cvd_manage_sales` o admin `manage_woocommerce`; cancelar solo admin | pedido/estado, mapa; dinero confirmado para `delivered` | historial, nota, evento; `ready` publica oferta; `delivered` cierra entrega, aprueba comisión y completa WC; cancelar cambia WC | cierre toca efectivo, comisión y ledger indirectamente; WC puede afectar stock según configuración | sin lock; no idempotencia de solicitud; dos actores pueden leer el mismo origen |
| Mensajería inicial | `class-cvd-delivery.php` · `initialize_order()` | vacío → `unassigned` | sistema / checkout | no aplica a pickup | calcula reparto de tarifa y ganancia pendiente; evento observado | inicia estado contable de ganancia | comprobación previa; sin lock |
| Asignación directa | `class-cvd-delivery.php` · `assign()` | `unassigned`/`offered` → `assigned`; asignar 0 intenta `unassigned` | admin/edit order o dependienta/admin desde portal | pedido; el `transition()` privado no vuelve a validar mapa | mensajero, historial, nota, evento, correo, push/reputación | sin stock; fija responsable | sin lock ni idempotencia; concurrencia posible |
| Publicación de oferta | `class-cvd-delivery.php` · `publish_offer()` | `unassigned` → `offered` | sistema/dependienta/admin | delivery, estado `unassigned`, mensajeros elegibles | invitados/ranking, historial, evento, push, correos, cron de ampliación | sin stock/ledger | guard de estado sin lock; dos ejecuciones pueden duplicar avisos |
| Ampliación de oferta | `class-cvd-delivery.php` · `expand_offer()` | `offered` → `offered` | sistema/cron | sigue ofrecido y sin asignado | amplía invitados, historial y avisos | ninguno | unión de IDs; sin lock; avisos duplicables |
| Decisión de oferta | `class-cvd-delivery.php` · `offer_decision()` | `offered` → `accepted` o rechazo sin cambio | mensajero aprobado e invitado | nonce, disponibilidad, sin asignación previa | asigna mensajero, aceptación, historial, nota; rechazo añade lista | sin stock/ledger | `GET_LOCK` por pedido; evita dos aceptaciones; no receipt HTTP |
| Custodia | `class-cvd-delivery.php` · `handover_by_staff()` | `accepted`/`to_store` → `picked_up` | dependienta/admin | mensajero asignado | historial, evento, actor/hora; sincroniza operación a `with_courier` | cambio de custodia; sin stock/ledger | caller QR usa lock; dashboard de ventas no; no receipt |
| Mensajería manual | `class-cvd-delivery.php` · `change_status()` | mapa de mensajería legacy | admin; mensajero asignado; dependienta según destino | nonce, mapa, capability/rol; nota en `incident` | historial, evento, push, reputación; timestamps; sincronización operativa | `delivered` inicia efectivo; `cash_returned` lo devuelve; `closed` verifica, completa WC, acredita ledger y aprueba comisión | sin lock general; side effects ocurren en varios `save`; reintentos pueden duplicar historial/avisos |
| Cierre por dinero | `class-cvd-delivery.php` · `close_after_cash_received()` | `delivered` → `cash_returned` → `closed` | dependienta/admin por llamada desde ventas | entrega en `delivered` | dos historiales/eventos de delivery y pago | verifica efectivo, aprueba ganancia, acredita ledger, aprueba comisión | ledger tiene UNIQUE; secuencia global sin transacción/lock |
| Mensajería cancelada | `class-cvd-delivery.php` · `sync_cancelled()` | activo → `cancelled` | sistema / hook WC cancel/refund | pedido existente | historial/evento, anula rating aplicable | ganancia cancelada; ledger disponible pasa a void | guard de estado; ledger update repetible; sin transacción entre módulos |
| Comisión | `class-cvd-commissions.php` · `store()` vía `mark_*`/admin | vacío → `pending` → `approved` → `paid`; cualquier permitido → `cancelled` | sistema; admin/edit order | pedido con propietaria no orgánica; mapa admin | recalcula snapshot/desglose; historial/nota al forzar; evento | cambia comisión y elegibilidad de payout | estados terminales protegidos parcialmente; sin lock; cálculo+save no atómicos |
| WooCommerce | `CVD_WhatsApp_Gateway::process_payment()` y `WC_Order::update_status()` desde ventas/delivery/admin | checkout → `on-hold`; activo → `completed`/`cancelled`; WC también `failed`/`refunded` | cliente/gateway, admin, sistema | reglas WooCommerce y cada caller | hooks de estado disparan sincronizaciones, comisión, evento WC y posibles correos/stock WC | impacto de stock depende de WooCommerce; cierre/cancelación impacta contabilidad Casa Viva por hooks | WooCommerce evita algunos no-op; no hay transacción común con metadatos Casa Viva |
| Ledger mensajero | `class-cvd-messenger-accounting.php` · `credit_order()` / `void_order()` | inexistente → `available`; `available` → `void` | sistema durante cierre/cancelación | delivery `closed`, cash `verified`, mensajero e importe | inserta/anula asiento y snapshot en pedido | impacto contable directo | UNIQUE `(order_id,entry_type)` e `INSERT IGNORE`; void repetible |
| Liquidación mensajero | `CVD_Messenger_Accounting::create_settlement()` / `admin_action()` | ledger `available` → `reserved` → `paid` | mensajero / admin | método, filas elegibles, referencia | settlement e items | contabilidad directa | `GET_LOCK` + transacción + `FOR UPDATE` |
| Payout gestora | `class-cvd-payouts.php` · `request()` / `transition()` | `requested` → `approved` → `paid` o `rejected` | gestora / admin | perfil, comisiones elegibles, referencia para pago | tablas payout, metadatos de pedido, correo; `paid` llama comisión | liquidación de comisiones | request usa lock+transacción; transición admin no usa lock/transacción completa |
| Incidencia | `CVD_Sales::change_status()` y `CVD_Delivery::change_status()` | etapa activa ↔ `incident` | según dominio; mensajería exige nota | mapa legacy; recuperación explícita | historial y evento `incident.opened/resolved`; mensajería notifica | no cambia stock/contabilidad por sí sola | sin lock general; hoy sustituye temporalmente el metadato del eje aunque conceptualmente sea dimensión separada |

## Matriz real de transiciones

`capability` conserva exactamente la autorización actual. `legacy_writer` identifica
quién sigue siendo dueño hasta su migración.

| domain | from | to | actor | capability | preconditions | side_effects | event_type | legacy_writer |
|---|---|---|---|---|---|---|---|---|
| operation | vacío | new | system | hook checkout | falta meta | fecha, push | `operation.state_changed` | `CVD_Sales::initialize_order` |
| operation | new / confirmed | preparing | staff/admin | `cvd_manage_sales` / `manage_woocommerce` | pedido activo | historia, nota | `operation.state_changed` | `CVD_Sales::change_status` |
| operation | preparing | ready | staff/admin | igual | pedido activo | historia, nota, posible oferta | `operation.state_changed` | `CVD_Sales::change_status` |
| operation | with_courier | delivered | staff/admin | igual | confirmación y método de cobro | cobro, cierre, WC, comisión/ledger | `operation.state_changed` | `CVD_Sales::change_status` |
| operation | activo | incident | staff/admin | igual | permitido por mapa | historia, nota | `incident.opened` | `CVD_Sales::change_status` |
| operation | incident | confirmed / preparing / ready / with_courier | staff/admin | igual | permitido por mapa | historia, nota; `ready` puede ofertar | `incident.resolved` | `CVD_Sales::change_status` |
| operation | activo / incident | cancelled | admin/system | `manage_woocommerce` o hook | cancelable | WC cancelado y cascadas | `operation.state_changed` | `CVD_Sales::change_status/sync_cancelled` |
| delivery | vacío | unassigned | system | hook checkout | delivery | tarifas/ganancia pendiente | `delivery.state_changed` | `CVD_Delivery::initialize_order` |
| delivery | unassigned | offered | staff/admin/system | legacy | mensajeros elegibles | invitaciones, avisos, cron | `delivery.state_changed` | `publish_offer` |
| delivery | unassigned / offered | assigned | staff/admin | legacy | mensajero elegido | asignación, correo | `delivery.state_changed` | `assign` |
| delivery | offered / assigned | accepted | mensajero asignado/admin | mensajero aprobado o admin | oferta libre/asignación | asignación y hora | `delivery.state_changed` | `offer_decision/change_status` |
| delivery | accepted | to_store | mensajero asignado/admin | legacy | mapa | hora, push | `delivery.state_changed` | `change_status` |
| delivery | accepted / to_store | picked_up | staff/admin | `cvd_manage_sales` / `manage_woocommerce` | mensajero asignado | custodia, operación `with_courier` | `delivery.state_changed` + operation | `handover_by_staff/change_status` |
| delivery | picked_up | handed_over | mensajero asignado/admin | legacy | mapa | hora ruta, tracking/push | `delivery.state_changed` | `change_status` |
| delivery | handed_over | delivered / failed / returned | mensajero asignado/admin | legacy | mapa | entrega/resultado; `delivered` inicia cash | `delivery.state_changed` (+ payment) | `change_status` |
| delivery | delivered | cash_returned | staff/admin | legacy | efectivo recibido | cash returned, revisión comisión | `delivery.state_changed` + `payment.state_changed` | `change_status/close_after_cash_received` |
| delivery | cash_returned | closed | admin/system tras dinero | `manage_woocommerce` o caller autorizado | efectivo devuelto | cash verified, WC, ledger, comisión | `delivery.state_changed` + payment/order/commission | `change_status/close_after_cash_received` |
| delivery | activo | incident | rol permitido por etapa | legacy | nota obligatoria en mensajería | historia, push | `incident.opened` | `change_status` |
| delivery | incident | assigned / accepted / to_store / picked_up / handed_over / delivered / returned / failed | rol permitido | legacy | mapa | historia, push; destinos conservan efectos de su transición | `incident.resolved` | `change_status` |
| delivery | activo | cancelled | system | hook WC cancel/refund | WC cancelado/reembolsado | earning cancel, ledger void, rating | `delivery.state_changed` | `sync_cancelled` |
| payment | vacío | pending_return | messenger/admin | transición delivery | delivery → delivered | actor/hora entrega | `payment.state_changed` | `CVD_Delivery::change_status` |
| payment | pending_return | returned | staff/admin | transición delivery | delivery delivered | actor/hora, review ready | `payment.state_changed` | `change_status/close_after_cash_received` |
| payment | returned | verified | admin/system | cierre | delivery cash_returned | ledger/comisión/WC | `payment.state_changed` | `change_status/close_after_cash_received` |
| commission | none | pending | system | hooks checkout/WC | propietaria no orgánica | cálculo/snapshot/riesgo | `commission.state_changed` | `CVD_Commissions::store` |
| commission | pending | approved | system/admin | cierre o edición | propietaria | elegible para payout | `commission.state_changed` | `CVD_Commissions::store` |
| commission | approved | paid | admin/system payout | payout pagado | asociada a payout | historial | `commission.state_changed` | `CVD_Commissions::store` |
| commission | pending / approved / paid | cancelled | system/admin | cancel/refund/fail o edición | anulación | `commission.state_changed` | `CVD_Commissions::store` |

## Contradicciones y riesgos demostrados

1. `operation_status()` proyecta WooCommerce `completed` como `delivered`, aunque el
   metadato operativo histórico pueda contener otra cosa; un escritor puede validar
   contra una proyección distinta del valor persistido.
2. El mapa de asignación documenta `offered → assigned`, pero `assign()` privado no
   valida origen y también permite desasignar hacia `unassigned` desde otros estados.
3. `picked_up` puede entrar por un caller con lock (QR) o sin lock (ventas/manual).
4. `change_status()` de mensajería separa la escritura principal y sus efectos en
   varios `save`; un fallo intermedio deja estado parcial.
5. El cierre automático realiza dos transiciones y efectos contables sin una única
   transacción.
6. WooCommerce `failed` cancela operación/comisión por interpretación/hooks, pero no
   existe hook equivalente en mensajería.
7. `incident` ocupa el metadato del eje legacy aunque el modelo lo define como una
   dimensión separada. 1C conserva compatibilidad y registra el dominio canónico
   separado; no migra metadatos históricos.

## Primer subconjunto migrado

Solo las siguientes escrituras de `CVD_Sales::change_status()` pasan inicialmente por
`CVD_Order_Transition_Service`:

- `new|confirmed → preparing`;
- `new|confirmed|preparing → incident`;
- `incident → confirmed|preparing`.

Son representativas (autorización, precondición, idempotencia, concurrencia y eventos,
incluida incidencia), pero no ejecutan oferta, WooCommerce, efectivo, comisión, ledger,
stock ni notificaciones externas. Todos los demás escritores permanecen legacy y se
enumeran arriba para migración posterior.
