# Casa Viva — 7D.6 matriz de ejecución E2E

## Objetivo

Convertir la certificación 7D en una matriz operativa única que distinga evidencia ya cubierta por CI de la evidencia que solo puede obtenerse sobre la release desplegada. Este documento no declara `GO` por sí solo.

## Candidato

- Base al abrir 7D.6: `b0a88e1b4b5c9f63e54a3afa30897ebb88c1d0b0`.
- El SHA final certificado debe sustituir esta referencia después del último merge previo al despliegue.
- La release desplegada debe corresponder exactamente al SHA certificado.

## Estados permitidos

- `PASS-AUTO`: cubierto por CI/release checks reproducibles.
- `PENDING-DEPLOY`: requiere evidencia del sitio desplegado.
- `PASS-DEPLOY`: verificado sobre la release desplegada.
- `FAIL`: fallo reproducible que impide `GO` si es P0/P1.
- `N/A`: no aplica al recorrido certificado, con justificación explícita.

## Matriz crítica

| Dominio | Recorrido | Estado actual | Evidencia requerida |
| --- | --- | --- | --- |
| Cliente | tienda → producto → carrito | PASS-AUTO | browser/contratos cliente verdes |
| Cliente | carrito → checkout → pedido | PENDING-DEPLOY | pedido de prueba controlado sobre release desplegada |
| Cliente | confirmación → seguimiento | PASS-AUTO | browser + contratos 7A/3C/3D |
| Cliente | privacidad entre clientes | PASS-AUTO | integración/contratos de privacidad |
| Gestora | registro/login/redirect | PASS-AUTO | integración + contratos de acceso por rol |
| Gestora | atribución de venta | PENDING-DEPLOY | pedido controlado con atribución verificable |
| Gestora | comisión/payout visible según permisos | PASS-AUTO | contratos 4A–4H + integración |
| Dependienta | pedido → preparación → listo/handoff | PENDING-DEPLOY | recorrido controlado sobre pedido de prueba |
| Dependienta | privacidad operativa | PASS-AUTO | integración 5A |
| Mensajero | asignación → aceptación → custodia → ruta → entrega | PENDING-DEPLOY | recorrido controlado con cuenta de prueba |
| Mensajero | privacidad y límites de datos | PASS-AUTO | integración/contratos 2D/5A |
| Administración | vista ampliada y acciones auditables | PASS-AUTO | contratos 4H/5D + integración |
| Administración | comisión/payout tras cierre | PENDING-DEPLOY | pedido de prueba cerrado sin mutaciones manuales fuera del flujo canónico |
| Inventario | WooCommerce sigue siendo fuente oficial | PASS-AUTO | integración 5C + contratos actuales |
| Release | build reproducible por SHA | PASS-AUTO | `scripts/verify-7d-predeploy.sh` |
| Release | manifest/checksum/source_sha | PASS-AUTO | gate 7D.5 |
| Despliegue | SHA desplegado = SHA certificado | PENDING-DEPLOY | evidencia de deployment + manifest remoto |
| Smoke | HTTP/HTTPS/REST/privacidad | PENDING-DEPLOY | `scripts/smoke-staging.sh` contra release desplegada |
| Rollback | backup y recuperación disponibles | PENDING-DEPLOY | evidencia de rollback/restore controlado |

## Reglas de ejecución

1. No cambiar pedidos, stock, comisiones o payouts para hacer pasar la prueba.
2. Usar exclusivamente fixtures/cuentas/pedidos de prueba identificables.
3. Toda transición crítica debe pasar por los servicios canónicos existentes.
4. Si una comprobación produce `FAIL`, registrar evidencia antes de corregir.
5. No convertir `PENDING-DEPLOY` en `PASS-DEPLOY` sin evidencia verificable del mismo SHA desplegado.
6. No declarar `GO` mientras exista cualquier `PENDING-DEPLOY` crítico o `FAIL` P0/P1.
7. Casa Viva Network permanece `FUTURE` durante esta matriz.

## Secuencia restante

`main verde`
→ `verify-7d-predeploy.sh <SHA>`
→ release reproducible
→ despliegue controlado del mismo SHA
→ smoke
→ recorridos E2E pendientes por rol
→ rollback verificado/disponible
→ matriz sin PENDING críticos ni FAIL P0/P1
→ `CASA VIVA CORE — BASELINE ESTABLE PRE-NETWORK`

## Salida esperada

Al completar esta matriz, actualizar cada fila con evidencia concreta y fijar:

- SHA certificado;
- resultado `GO` o `NO-GO`;
- incidencias P0/P1 encontradas y estado;
- release desplegada;
- smoke final;
- rollback;
- baseline estable pre-Network.
