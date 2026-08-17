# Casa Viva — estado actual

## Estado general

- 1A–1C.4: modelo canónico de pedidos, eventos, transiciones, logística, custodia, cierre y excepciones — validadas e integradas.
- 2A–2D: Centro Único del Pedido, notificaciones/enlaces, acciones de contacto y Centro Operativo del Mensajero — validadas e integradas.
- 3A–3E: navegación móvil del cliente, listado de pedidos, detalle único, seguimiento en vivo y ayuda/contacto contextual — validadas e integradas.
- 4A–4H: gestoras, referidos, comisiones, payouts, portal financiero, precios espejo, privacidad, cierre canónico de comisión y reasignación administrativa auditable — validadas e integradas.

## Bloque 04 — Gestoras, referidos y comisiones — CERRADO

### 4A — Atribución permanente

- first-touch persistente por identidad de cliente;
- atribución por enlace/código, cookie/sesión, cupón y carrito;
- cliente orgánico sin comisión;
- identidad de operador separada de identidad de cliente;
- pedidos históricos como fuente de vínculo permanente.

### 4B — Comisiones y margen de tienda espejo

- comisión base configurable;
- excepciones por gestora;
- reglas fijas o porcentuales por producto;
- prioridad de reglas de producto;
- margen propio de tienda espejo separado de la comisión base;
- snapshots históricos inmutables;
- detección de riesgo de auto-compra.

### 4C — Liquidaciones y pagos

- solicitud de payout por gestora;
- agrupación por moneda;
- bloqueo transaccional y concurrencia segura;
- aprobación, pago y rechazo con historial;
- referencia y comprobante de pago;
- vinculación payout ↔ pedidos;
- rollback seguro de liquidaciones rechazadas o fallidas.

### 4D — Vista financiera de gestora

- ventas y ganancias visibles solo para su propietaria;
- desglose de comisión base y margen propio;
- estados financieros e historial trazables.

### 4E — Integridad de precios espejo

- precio base Casa Viva preservado;
- precios personalizados por gestora;
- límites mínimo/máximo configurables;
- caché y versiones de precio aisladas por gestora;
- snapshots de precio en carrito y pedido.

### 4F — Privacidad y aislamiento del portal

- una gestora no puede ver clientes, pedidos ni finanzas de otra;
- datos internos y metadatos sensibles no se renderizan;
- validación real en navegador móvil;
- protección frente a desbordamiento horizontal y problemas de lectura móvil.

### 4G — Cierre canónico de comisiones pagadas

- una comisión aprobada no puede marcarse pagada manualmente desde el pedido;
- `paid` exige un payout vinculado y realmente pagado;
- el flujo canónico queda: comisión aprobada → payout → pago → comisión pagada → historial.

### 4H — Reasignación administrativa auditable

- administración es la única vía para cambiar la propietaria permanente de un cliente;
- motivo obligatorio y permisos administrativos;
- solo gestoras/influencers aprobados pueden ser nueva propietaria;
- historial append-only con propietaria anterior, nueva, actor, motivo, fecha, UUID y pedido de referencia;
- la reasignación se aplica únicamente a pedidos futuros;
- pedidos, comisiones y payouts históricos permanecen intactos;
- una corrección posterior crea un nuevo evento y no borra el anterior.

## Evidencia de cierre del Bloque 04

- PR #30 — Fase 4G — integrada en `main`.
- Merge 4G: `46d04ce2f7367042669103f53e64e74968654af3`.
- PR #31 — Fase 4H — integrada en `main`.
- Merge 4H: `afcfa5f4e4089dc1eb52e71790b464405529f620`.
- GitHub Actions PR 4H: run #108 / `31992130441` — `validate=success`, `integration=success`, `browser=success`.
- GitHub Actions post-merge 4H: run #109 / `31992346744` iniciado sobre `main`; debe quedar completamente verde antes de usar este commit como base de la siguiente fase funcional.

## Regla para continuar

El Bloque 04 se considera funcionalmente cerrado. No crear 4I ni ampliar gestoras/referidos/comisiones sin un nuevo hallazgo concreto y verificable.

El siguiente bloque funcional debe comenzar desde `main` únicamente después de confirmar que el CI post-merge de 4H está completamente verde. Antes de programar, auditar el estado real del siguiente bloque y diseñar sus fases sobre esa realidad.
