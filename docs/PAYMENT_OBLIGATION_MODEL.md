# Obligaciones de pago y pagador dividido

Estado: contrato canónico aprobado por implementación. Casa Viva Core es la única autoridad.

## Problema que resuelve

Un pedido puede contener obligaciones financieras con pagadores y medios diferentes. Por ejemplo, una mensajería de 3 500 CUP puede liquidarse con 1 500 CUP cobrados al cliente y 2 000 CUP descontados de la comisión de la gestora. Una nota libre o un total agregado no permite conciliar ese caso.

## Contrato por obligación

Casa Viva guarda en el pedido WooCommerce la instantánea `_cvd_payment_obligations` (versión `1`). Cada fila contiene:

- `id`: identificador estable dentro del pedido;
- `concept`: `products`, `delivery` u `other`;
- `amount`: decimal positivo, sin signo y con precisión monetaria;
- `currency`: código ISO admitido por Core (`USD`, `CUP`, `EUR`);
- `payer`: `customer`, `gestora` o `casa_viva`;
- `payer_user_id`: obligatorio cuando `payer=gestora` y debe coincidir con la gestora canónica del pedido;
- `method`: `cash_usd`, `cash_cup`, `transfer`, `commission_deduction` u `other`;
- `status`: `pending`, `settled` o `cancelled`;
- `settled_at`, `settled_by` y `settlement_reference`: evidencia de liquidación.

La suma por concepto y moneda debe coincidir con la obligación comercial conocida. Para `delivery` en CUP, Core exige que la suma coincida con `_cvd_shipping_fee_cup`. No se convierten monedas ni se usan tasas implícitas.

## Fronteras de liquidación

1. `customer` + cobro físico/transferencia: el mensajero registra el importe realmente recibido al entregar. Core valida las asignaciones contra las obligaciones pendientes y conserva los agregados legacy como proyección compatible.
2. `gestora` + `commission_deduction`: al cierre canónico, Core publica una entrada débito inmutable en `cvd_owner_financial_ledger`, vinculada al pedido y a la obligación. No reduce una comisión de otra moneda.
3. La disponibilidad de liquidación de gestora es `créditos de comisión aprobados − débitos abiertos` por la misma moneda. Si el saldo no es positivo, no se crea una solicitud de pago y el débito permanece visible/pendiente. No existe compensación entre monedas.

## Compatibilidad

Los pedidos sin `_cvd_payment_obligations` mantienen el flujo simple existente y sus metadatos `_cvd_collection_*`. No se migran ni reinterpretan cobros históricos. Una vez que un plan estructurado participa en una entrega, su instantánea no puede editarse fuera de una corrección administrativa auditada.

## Auditoría e idempotencia

- El plan se registra mediante `payment.obligations_configured`.
- Cada cobro queda en la instantánea y en el evento canónico de transición de pago con identificadores e importes, sin PII.
- Cada descuento genera una entrada única por `(order_id, obligation_id, entry_type)` y un evento canónico del dominio `payment` con `method=commission_deduction`.
- Repetir una transición o cierre no duplica cobros ni débitos.

## Privacidad y ownership

El mensajero ve únicamente las obligaciones que debe cobrar en pedidos asignados. La gestora ve su saldo por moneda. Solo administración/operación puede configurar o corregir el plan; NEXO puede proponer datos, pero nunca decide importes ni persiste obligaciones.
