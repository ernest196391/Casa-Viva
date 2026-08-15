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
8. Ejecutar el único side effect registrado para el subconjunto.
9. Insertar directamente exactamente un evento canónico.
10. Guardar receipt, confirmar transacción y liberar lock.

Si cualquier escritura o side effect falla, se hace `ROLLBACK`; el servicio devuelve
`SIDE_EFFECT_FAILED` y no considera aplicada la transición.

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

## Compatibilidad

Un pedido sin `_cvd_operation_status` se interpreta como `new`, igual que el escritor
actual. No existe migración masiva. Pedidos terminales WooCommerce se rechazan antes
de escribir. El wrapper `CVD_Sales::change_status()` conserva firma, endpoint,
capabilities y payload; solo deriva el subconjunto aprobado al servicio.
