# Servicio único de transiciones — Fase 1C

## Decisión

`CVD_Order_Transition_Service` es la autoridad progresiva que responde si una
transición puede ejecutarse y la realiza de forma idempotente. No sustituye al lector
canónico ni crea otra máquina de estados. Su catálogo inicial es el subconjunto real
documentado en `ORDER_TRANSITION_WRITERS.md`; cualquier transición no incluida sigue
en su escritor legacy.

## Contrato

```php
CVD_Order_Transition_Service::transition(
    int $order_id,
    string $domain,
    string $target_state,
    array $context = array()
): array
```

El resultado siempre contiene:

- `success`;
- `previous_state`;
- `new_state`;
- `event_id`;
- `idempotent_replay`;
- `error_code`.

Errores implementados: `INVALID_TRANSITION`, `UNAUTHORIZED`,
`PRECONDITION_FAILED`, `CONFLICT`, `ALREADY_APPLIED`, `ORDER_NOT_FOUND` y
`SIDE_EFFECT_FAILED`. El wrapper REST conserva mensajes públicos genéricos y no
expone excepciones ni SQL.

## Secuencia segura

1. Cargar el pedido WooCommerce.
2. Resolver actor y conservar `cvd_manage_sales`/`manage_woocommerce`.
3. Reconocer un receipt de idempotencia ya aplicado.
4. Adquirir `GET_LOCK` por pedido y releer estado.
5. Validar origen/destino y estado WooCommerce no terminal.
6. Abrir transacción MariaDB.
7. Escribir metadato, fecha, historial y nota legacy una sola vez.
8. Ejecutar la mutación atómica registrada para el subconjunto.
9. Insertar directamente exactamente un evento canónico.
10. Guardar receipt, confirmar transacción y liberar lock.

Si cualquier escritura o mutación atómica falla, se hace `ROLLBACK`; el servicio devuelve
`SIDE_EFFECT_FAILED` y no considera aplicada la transición.

Correo, push y cron no mantienen abierta la transacción. Se invocan mediante
`after_commit` solo tras una aplicación nueva; un receipt/replay nunca vuelve a
ejecutarlos. Sus fallos se notifican con `cvd_order_transition_after_commit_failed`
sin revertir un estado ya confirmado.

## Idempotencia y concurrencia

- Un `X-CVD-Idempotency-Key` o `idempotencyKey` se guarda como hash en el pedido.
- Repetir la misma clave devuelve el mismo `event_id` y `idempotent_replay=true`.
- Reusar la clave para otro destino devuelve `CONFLICT`.
- Sin clave explícita, encontrar el pedido ya en el destino devuelve
  `ALREADY_APPLIED` sin escribir otro evento.
- Una transición legítima posterior usa un ancla nueva y genera un evento nuevo.
- `GET_LOCK` serializa doble pulsación, dos dependientas y reintentos concurrentes.
- La restricción única del event store es una segunda defensa, no el lock principal.

## Eventos e incidencias

Las transiciones normales producen `operation.state_changed`. Entrar a `incident`
produce `incident.opened`; salir produce `incident.resolved`. El metadato operativo
legacy se conserva por compatibilidad, pero el evento pertenece al dominio
`incident`, por lo que la etapa subyacente sigue demostrable mediante historial.

## Side effects y frontera inicial

La primera migración conserva únicamente historia y nota operativa. No incluye
publicación de oferta, cambio de custodia, WooCommerce, stock, efectivo, comisión,
ledger, asignación, cierre ni notificación externa. Esos efectos siguen ejecutándose
una sola vez en sus escritores legacy y no se duplican desde el servicio.

## Extensión 1C.1

El catálogo incorpora `preparing|incident → ready`, `unassigned → offered`,
`unassigned|offered → assigned`, `offered|assigned → accepted` y
`accepted → to_store`. El servicio valida internamente capabilities, relación con el
mensajero y origen; adaptadores privados aportan elegibilidad/invitación y mutaciones
específicas sin convertirse en otra autoridad.

Oferta, asignación y aceptación comparten `cvd_transition_{order_id}`. Por ello dos
mensajeros no pueden adquirir simultáneamente un pedido: después del lock el perdedor
relee `accepted` y el mensajero ganador y recibe `CONFLICT`. `picked_up` y posteriores
continúan fuera del catálogo, de modo que 1C.1 no transfiere custodia.

## Compatibilidad

Un pedido sin `_cvd_operation_status` se interpreta como `new` y sin
`_cvd_delivery_status` como `unassigned`, igual que los escritores
actuales. No existe migración masiva. Pedidos terminales WooCommerce se rechazan antes
de escribir. El wrapper `CVD_Sales::change_status()` conserva firma, endpoint,
capabilities y payload; solo deriva el subconjunto aprobado al servicio.

## Extensión 1C.2

El catálogo incorpora `accepted|to_store → picked_up`, `picked_up → handed_over` y
`handed_over → delivered|failed|returned`.

Para `picked_up`, el servicio es dueño de una unidad atómica compuesta:

1. estado, timestamp e historial de delivery;
2. operación `with_courier`, historial y nota solo si cambia;
3. evento canónico delivery;
4. evento canónico operation solo si cambia;
5. receipt común;
6. commit o rollback conjunto.

Así no puede persistir custodia con operación atrasada. QR, Centro de ventas y acción
manual llegan a la misma autoridad y al mismo `cvd_transition_{order_id}`.

`delivered` acopla únicamente `_cvd_cash_status=pending_return` y su evento payment.
La reconciliación, cierre, WooCommerce, comisión y ledger permanecen fuera. Correo,
push y reputación siguen siendo consecuencias posteriores al commit.

Los estados terminales logísticos incompatibles se serializan: si uno de
`delivered|failed|returned` ya ganó, otro destino devuelve `CONFLICT`. `failed`
logístico nunca se proyecta ni escribe como `failed` de WooCommerce.
