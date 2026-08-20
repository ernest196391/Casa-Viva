# Casa Viva — Fase 7D.2: evidencia pre-despliegue

## Candidato actual

- `main` de referencia: `679cd169cae013fc03f9d39b7a07319ba4d0fb55`.
- Este SHA incorpora el checkpoint 7D.1 y mantiene Casa Viva Network fuera de alcance.

## Evidencia ya verificada

Para el PR #52, cuyo contenido fue integrado en el SHA anterior:

- Validar aplicación #190: `success`.
- Validar fundación de release #57: `success`.
- Validar contrato de staging #49: `success`.
- El PR era `mergeable` y fue fusionado sin forzar el estado.

## Alcance de esta evidencia

Esta fase confirma que el candidato entra a certificación sin fallos conocidos en las garantías de código y release asociadas al PR integrado.

No declara todavía `GO` y no afirma que el sitio productivo esté certificado.

## Lo que falta antes de declarar GO

1. ejecutar la matriz E2E controlada por rol;
2. confirmar cliente → compra → pedido → seguimiento;
3. confirmar atribución y privacidad de gestora;
4. confirmar operación/dependienta;
5. confirmar mensajero → custodia → ruta → entrega;
6. confirmar administración y comisión/payout;
7. generar release reproducible desde el SHA exacto candidato;
8. desplegar únicamente mediante el mecanismo controlado ya definido;
9. verificar SHA desplegado, smoke y rollback;
10. cerrar sin P0/P1 abiertos;
11. crear `CASA VIVA CORE — BASELINE ESTABLE PRE-NETWORK`.

## Guardrails

- WooCommerce sigue siendo fuente oficial de pedidos y stock.
- No se crean motores paralelos de pedidos, identidad, permisos, ledger o logística.
- No se alteran pedidos, stock, comisiones, payouts o finanzas para facilitar pruebas.
- Casa Viva Network permanece `FUTURE` hasta cerrar 7D.

## Estado

`PREDEPLOY READY — NO GO YET`

Siguiente frontera: completar la certificación E2E controlada y solo después decidir despliegue/certificación final del candidato.
