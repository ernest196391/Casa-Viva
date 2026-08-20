# Casa Viva — Fase 7D: certificación E2E de lanzamiento

## Objetivo

Certificar el lanzamiento actual de Casa Viva sobre el sistema realmente desplegado, sin ampliar alcance ni introducir funcionalidades FUTURE de Casa Viva Network.

Resultado final permitido:

- `GO` — todos los recorridos críticos pasan y la release puede considerarse candidata a lanzamiento.
- `NO-GO` — existe al menos un bloqueo crítico reproducible con evidencia y plan de corrección.

## Guardrails

- WooCommerce sigue siendo fuente oficial de pedidos y stock actuales.
- No crear estados, motores de identidad, pedidos, permisos, ledger ni logística paralelos.
- No alterar pedidos, stock, comisiones, payouts o finanzas para facilitar una prueba.
- Toda prueba que cree datos debe usar fixtures/cuentas de prueba controladas y ser identificable.
- No desplegar una release candidata hasta que `validate`, `integration` y `browser` estén verdes en el SHA exacto.
- Toda release candidata debe conservar manifest, checksum, verificación de SHA y rollback automático.

## Matriz mínima de certificación

### Cliente

1. Inicio/Tienda → producto → carrito.
2. Carrito no vacío → checkout.
3. Cuba/provincia/municipio/localidad y método de entrega visibles y coherentes.
4. Mensajería o recogida reflejada en el total cuando corresponde.
5. Creación del pedido WooCommerce.
6. Confirmación post-compra.
7. Cliente autenticado → `Ver seguimiento del pedido`.
8. `Pedidos` → detalle/timeline/ayuda.
9. Privacidad: un cliente no puede abrir pedidos de otro.

### Gestora

1. Solicitud/registro.
2. Estado pendiente/aprobado.
3. Login y redirect al área gestora correcta.
4. Catálogo/precio permitido sin violar precio oficial.
5. Atribución y privacidad respecto a otra gestora.
6. Comisiones/payouts visibles solo según permisos ya definidos.

### Mensajero

1. Solicitud/registro.
2. Login y redirect a `/area-mensajeros/`.
3. Pedido asignado visible.
4. Acciones canónicas de aceptación/custodia/ruta/entrega.
5. Evidencia/confirmación cuando corresponde.
6. No acceso a datos/comisiones fuera de su frontera.

### Dependienta / operación

1. Pedido recibido.
2. Preparación.
3. Listo para recogida/handoff.
4. Cierre de recogida mediante transición canónica.
5. Incidencia estructurada cuando corresponde.
6. Privacidad operativa: solo los datos necesarios.

### Administración

1. Vista ampliada operativa.
2. Reasignaciones/overrides sensibles exigen motivo/evidencia según contratos existentes.
3. Inventario mantiene WooCommerce como fuente oficial.
4. Comisiones/payouts conservan flujo auditable.

## Seguridad y privacidad

- rutas privadas rechazan usuarios no autorizados;
- no se exponen `order_key`, secretos ni metadatos financieros innecesarios;
- separación de gestoras y separación de clientes verificadas;
- roles internos no reciben más datos de los necesarios.

## Certificación técnica

Para el SHA candidato:

1. `validate = success`;
2. `integration = success`;
3. `browser = success`;
4. fundación de release = success;
5. contrato de staging/deploy = success;
6. release reproducible generada desde SHA exacto;
7. `release-manifest.json` y `SHA256SUMS` válidos;
8. despliegue GitHub → Hostinger mediante SSH;
9. verificación del SHA desplegado;
10. smoke HTTP/HTTPS/REST/privacidad = success;
11. rollback automático disponible.

## Criterio GO

Solo declarar `GO` si:

- todos los recorridos críticos anteriores están certificados;
- no existen fallos P0/P1 abiertos;
- la release desplegada corresponde al SHA validado;
- rollback está probado/disponible;
- existe checklist operativo para el primer día.

## Criterio NO-GO

Declarar `NO-GO` si cualquiera de los siguientes ocurre:

- compra real no puede completarse;
- pedido/seguimiento rompe privacidad;
- un rol accede al portal o datos equivocados;
- una transición crítica evita los servicios canónicos;
- stock/pedido/comisión se duplica o muta incorrectamente;
- CI crítico rojo;
- SHA desplegado no coincide con release validada;
- smoke final falla y no existe rollback fiable.

## Salida de 7D

La fase termina con un informe corto:

- SHA certificado;
- matriz PASS/FAIL por recorrido;
- incidencias críticas encontradas y corregidas;
- resultado `GO` o `NO-GO`;
- release desplegada y smoke final;
- instrucciones de rollback;
- siguiente frontera autorizada.
