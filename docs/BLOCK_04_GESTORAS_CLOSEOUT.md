# Bloque 04 — Gestoras, referidos y comisiones — cierre

## Resultado

El Bloque 04 queda cerrado después de auditar el recorrido completo de atribución, pricing, comisión, payout, privacidad y reasignación administrativa.

## Recorrido canónico validado

1. Un cliente entra por referencia, cupón o tienda espejo, o llega orgánicamente.
2. La identidad del cliente se resuelve sin confundirla con la identidad del operador.
3. First-touch conserva la propietaria permanente salvo override administrativo auditable.
4. El precio espejo se resuelve dentro de los límites definidos y se congela en carrito/pedido.
5. La comisión se calcula sobre la base Casa Viva y separa el margen propio de reventa.
6. Los snapshots históricos impiden que cambios posteriores de reglas alteren ventas ya creadas.
7. La comisión pasa a aprobada durante el cierre correspondiente.
8. La gestora solicita payout de las comisiones elegibles, agrupadas por moneda.
9. Administración aprueba y paga la liquidación con referencia/comprobante e historial.
10. Solo un payout realmente pagado puede convertir la comisión en `paid`.
11. El portal financiero muestra únicamente datos de la gestora propietaria.
12. Una reasignación administrativa cambia la propietaria solo para pedidos futuros y conserva intacta toda la historia anterior.

## Fases cerradas

- 4A — atribución permanente y first-touch.
- 4B — comisiones, excepciones, reglas de producto, margen y snapshots.
- 4C — payouts, concurrencia, pago, rechazo y rollback.
- 4D — vista financiera de gestora.
- 4E — integridad de precios espejo.
- 4F — privacidad y aislamiento del portal.
- 4G — cierre canónico de comisión pagada.
- 4H — reasignación administrativa auditable.

## Hallazgos E2E resueltos durante el cierre

### Salto directo de comisión aprobada a pagada

Se detectó que administración podía marcar una comisión `approved` como `paid` desde el pedido, saltándose el payout. 4G eliminó esa vía: `paid` exige `_cvd_payout_id` y `_cvd_payout_status=paid`.

### Falta de reasignación administrativa segura

El modelo 4A declaraba que administración era la única excepción al first-touch, pero no existía la operación. 4H añadió una capa append-only por identidad que registra cada reasignación y la aplica únicamente a pedidos futuros.

## Garantías de no regresión

- no se reescriben pedidos históricos para cambiar la propietaria de un cliente;
- no se recalculan snapshots históricos cuando cambian reglas futuras;
- no se puede pagar una comisión fuera del flujo de payout;
- una gestora no puede consultar datos financieros o clientes de otra;
- la tienda general no hereda precios espejo por una cookie de atribución;
- los límites y versiones de precios continúan aislados por gestora;
- los flujos concurrentes de payout permanecen protegidos por locks y transacciones.

## Evidencia final

- PR #30 — 4G — merge `46d04ce2f7367042669103f53e64e74968654af3`.
- PR #31 — 4H — merge `afcfa5f4e4089dc1eb52e71790b464405529f620`.
- Run PR 4H #108 / `31992130441`: `validate`, `integration`, `browser` en `success`.
- El CI post-merge de 4H debe confirmarse verde antes de iniciar el siguiente bloque funcional.

## Criterio de reapertura

No crear una nueva fase 4I por continuidad numérica. Reabrir Bloque 04 solo ante un bug, requisito comercial nuevo o evidencia de que una garantía anterior no se cumple.
