# Bloque 05 — Operaciones, dependientas y administración — auditoría inicial

Base auditada: `main` en `85acd4e879605e2b286a0cdd4114e421a7c121e1`.

Esta auditoría describe el estado real encontrado antes de ampliar el Bloque 05. No sustituye el Blueprint, el modelo canónico ni los contratos de los Bloques 01–04.

## Resultado ejecutivo

Casa Viva ya tiene una base operativa relevante: rol de dependienta, Centro de ventas, Centro Único del Pedido, inventario sobre stock WooCommerce, cadena de custodia y servicio canónico de transiciones. El Bloque 05 no debe reconstruir esas piezas.

Los huecos reales se concentran en cuatro fronteras:

1. separación de información entre dependienta y administración;
2. cierre real de recogidas en tienda;
3. pruebas de integridad y discrepancias de inventario;
4. correcciones administrativas e incidencias operativas una vez cerradas las tres fronteras anteriores.

## Matriz de auditoría

### A. Dependientas — PARCIAL / DEFECTUOSO EN PRIVACIDAD

Implementado:

- rol `cvd_clerk`;
- capabilities `cvd_manage_sales` y `cvd_manage_inventory`;
- login y redirección al Centro de operaciones;
- acceso a Centro de ventas, inventario y acciones de preparación/handoff autorizadas.

Defecto encontrado:

- el Centro de ventas entregaba a la dependienta gestora, importe/estado de comisión y URL administrativa;
- el Centro Único del Pedido seguía entregando contexto de gestora y estado de comisión aunque la dependienta no los necesita para preparar o entregar.

Fase asignada: **5A — privacidad operativa dependienta/admin**.

### B. Centro operativo — PARCIAL

Implementado:

- pedido nuevo → preparación → listo;
- oferta/asignación de mensajería;
- transferencia de custodia;
- entrega, efectivo y cierre para domicilio;
- timeline canónico y detección de conflictos;
- acciones derivadas del servicio canónico en el Centro Único.

Pendiente real:

- la recogida en tienda llega a `ready`, pero no existe una transición normal y canónica que permita a la dependienta completar `READY_FOR_PICKUP → entrega al cliente → COMPLETED`;
- la etiqueta genérica “Listo para mensajería” se usa también en un flujo que puede ser recogida.

Fase asignada: **5B — recogida en tienda canónica**.

### C. Stock / inventario — PARCIAL

Implementado:

- WooCommerce conserva el stock oficial;
- `CVD_Inventory` modifica stock mediante APIs WooCommerce y no mantiene una segunda cantidad canónica;
- movimientos manuales usan lock por producto y registro auditable;
- rebajas y restauraciones hechas por WooCommerce se registran como trazabilidad sin volver a modificar stock;
- existen códigos estables por producto/variación y vista de movimientos.

Huecos de evidencia:

- no existe una regresión específica del Bloque 05 que pruebe reducción/restauración WooCommerce + log de movimientos + ajustes manuales concurrentes;
- falta probar explícitamente discrepancia de conteo, stock agotado y variaciones bajo la operación real de dependienta;
- debe comprobarse que ninguna corrección administrativa provoque doble rebaja o doble reposición.

Fase asignada: **5C — integridad de stock y discrepancias**.

### D. Recogida en tienda — DEFECTUOSO

- preparación y estado listo existen;
- el lector canónico distingue `READY_FOR_PICKUP`;
- el flujo actual no ofrece una acción normal para completar una recogida desde `ready`;
- no existe todavía evidencia E2E de identificación/confirmación de recogida, cobro y cierre.

Se resuelve dentro de **5B** para no crear una microfase separada.

### E. Entrega a mensajero — IMPLEMENTADO

- asignación/oferta ya pertenece al Bloque 02/1C.1;
- `accepted|to_store → picked_up` está centralizado;
- `picked_up` representa transferencia real de custodia;
- la operación se sincroniza atómicamente a `with_courier`;
- no debe duplicarse el Centro Operativo del Mensajero.

### F. Administración — PARCIAL

Implementado:

- `manage_woocommerce` conserva visión global;
- cancelación canónica y cascada de excepción;
- cierre financiero de entrega;
- reasignación auditable de atribución;
- edición WooCommerce disponible para administración.

Pendiente de cierre:

- después de 5B y 5C debe auditarse si siguen existiendo correcciones operativas sensibles fuera de una vía explícita/auditable.

No se crea una fase todavía; se decide tras 5C.

### G. Incidencias — PARCIAL

Implementado:

- apertura/resolución canónica aditiva;
- etapa subyacente preservada;
- nota obligatoria en mensajería;
- cancelaciones y fallos terminales protegidos.

Pendiente de comprobar:

- cobertura concreta de falta de producto, preparación incorrecta, cliente que no recoge y mensajero que no recoge;
- decidir si las notas actuales bastan o si algún caso necesita dato estructurado.

No se crea una fase todavía; se reaudita tras 5B/5C.

### H. Información operativa — DEFECTUOSO → 5A

La dependienta recibía información financiera/comercial no necesaria. La corrección 5A aplica una lista negativa central a las respuestas REST operativas y conserva la vista completa únicamente para administración.

### I. UX mobile-first — PARCIAL

Implementado:

- Centro Único responsive y probado en 360/390/430 px;
- Centro de ventas e inventario tienen interfaces móviles y acciones rápidas;
- QR/cámara y polling ya existen.

Hueco:

- Centro de ventas de dependienta no tenía regresión browser específica de privacidad;
- recogida en tienda aún no tiene UX final ni prueba E2E.

### J. Pruebas — PARCIAL

Implementado:

- unit/contract PHP y Node;
- integración real WordPress + WooCommerce + MariaDB;
- concurrencia de transiciones;
- Playwright real para Centro Único, mensajero, cliente y gestora.

Huecos:

- privacidad dependienta/admin;
- recogida completa;
- stock y discrepancias de Bloque 05.

## Secuencia mínima resultante

### 5A — Privacidad operativa dependienta/admin

Frontera: una dependienta solo recibe los datos necesarios para preparar, contactar, entregar y registrar operación. Administración conserva información comercial y administrativa.

No cambia estados, stock, mensajería, atribución, comisiones ni payouts.

### 5B — Recogida en tienda canónica

Frontera: `READY_FOR_PICKUP → entrega física al cliente → cobro confirmado → COMPLETED`, con actor, evento, idempotencia, prevención de doble entrega e incidencia/no recogida cuando corresponda.

No se inventará un eje paralelo de estados.

### 5C — Integridad de stock y discrepancias

Frontera: mantener WooCommerce como stock oficial y demostrar con integración/concurrencia reducción, restauración, ajustes, conteos, agotados y variaciones sin doble movimiento.

No se construirá un segundo inventario.

### Auditoría de cierre posterior a 5C

Revisar administración e incidencias con el flujo completo ya cerrado. Crear 5D solamente si existe un hueco demostrable que no pertenezca a 5A–5C.

## Referencias externas contrastadas

La decisión de mantener WooCommerce como fuente de stock coincide con su comportamiento documentado: WooCommerce reduce existencias durante el procesamiento de pedidos y administra la liberación/restauración según el ciclo del pedido. La separación por capabilities sigue el modelo recomendado por WordPress, evitando usar nombres de rol como frontera primaria de autorización.
