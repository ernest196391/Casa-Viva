# MVP Ruta — integración con obligaciones canónicas de pago

Estado: diseño de integración aprobado para el piloto. Despliegue pausado.

## Autoridad

Casa Viva Core es la única autoridad financiera. El MVP Ruta no calcula, persiste ni reinterpreta repartos de pagador por su cuenta.

Contrato canónico: `docs/PAYMENT_OBLIGATION_MODEL.md`.

Implementación: `CVD_Payment_Obligations`.

## Regla principal del mensajero

Cuando el pedido pertenece a WooCommerce y existe un plan `_cvd_payment_obligations`, la pantalla de Cobros debe obtener únicamente `CVD_Payment_Obligations::customer_collectible( $order )` para construir lo que el mensajero debe cobrar al cliente.

El mensajero nunca debe deducir el total de mensajería restando notas, comisiones o importes libres.

Ejemplo canónico:

- mensajería total: 3.500 CUP;
- cliente: 1.500 CUP mediante cobro del mensajero;
- gestora: 2.000 CUP mediante `commission_deduction`.

En la tarjeta del mensajero debe aparecer como acción principal:

`COBRAR AL CLIENTE: 1.500 CUP`

Puede mostrarse como contexto no cobrable:

`2.000 CUP cubiertos por descuento de comisión de la gestora — NO COBRAR AL CLIENTE`.

## Compatibilidad

1. Pedidos con obligaciones estructuradas: usar Core exclusivamente.
2. Pedidos anteriores sin obligaciones estructuradas: conservar la proyección legacy de cobro existente.
3. Pedidos manuales del piloto que todavía no correspondan a un pedido WooCommerce: permitir datos operativos temporales, marcados visualmente como `MANUAL / NO CANÓNICO`, y no liquidarlos contra Core.
4. Una vez vinculado un registro manual a un pedido WooCommerce con obligaciones estructuradas, Core reemplaza la información financiera manual para visualización y liquidación.

## Liquidación

Al confirmar entrega, el MVP no debe marcar obligaciones como pagadas directamente en metadatos. Debe delegar al flujo canónico de entrega, que utiliza `CVD_Payment_Obligations::settle_customer_allocations()` y valida que el mensajero registre todas y solo las obligaciones del cliente.

Los descuentos de gestora no los ejecuta el mensajero. Se publican en el libro financiero canónico mediante el cierre correspondiente y permanecen separados por moneda.

No existe conversión implícita entre USD, CUP o EUR.

## Visibilidad por rol

- Mensajero: obligaciones del cliente que debe cobrar + aviso claro de conceptos que NO debe cobrar.
- Gestora: su débito/obligación por moneda y estado según Core.
- Dependienta: preparación y entrega física; no necesita detalle financiero interno salvo lo estrictamente operativo.
- Operación/administración: plan completo y capacidad de corrección auditada según Core.

## Criterios de aceptación del piloto

- El caso 1.500 CUP cliente + 2.000 CUP gestora se muestra sin ambigüedad.
- El mensajero no puede terminar cobrando 3.500 CUP al cliente por error.
- No se mezclan monedas.
- Reintentos no duplican cobros ni débitos.
- Los pedidos simples anteriores siguen funcionando.
- El MVP no crea una segunda fuente de verdad financiera.

## Relación con pre-salida

Los mensajes de WhatsApp generados para el cliente no deben incluir detalles internos de comisión. Si se menciona mensajería, solo se comunica el importe que el cliente debe pagar según `customer_collectible()`.

El resumen para dependienta prioriza carga, IDs, productos, alertas y vuelto. Los descuentos internos de gestora no se presentan como efectivo a entregar por el cliente.
