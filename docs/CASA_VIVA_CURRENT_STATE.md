# Casa Viva — estado actual

## Estado general

- 1A–1C.4: modelo canónico de pedidos, eventos, transiciones, logística, custodia, cierre y excepciones — validadas e integradas.
- 2A–2D: Centro Único del Pedido, notificaciones/enlaces, acciones de contacto y Centro Operativo del Mensajero — validadas e integradas.
- 3A–3E: navegación móvil del cliente, listado de pedidos, detalle único, seguimiento en vivo y ayuda/contacto contextual — validadas e integradas.
- 4A–4H: gestoras, referidos, comisiones, payouts, portal financiero, precios espejo, privacidad, cierre canónico de comisión y reasignación administrativa auditable — validadas e integradas.
- 5A–5D: privacidad operativa de dependientas, recogida en tienda canónica, integridad/reconciliación de inventario e incidencias operativas estructuradas — validadas e integradas.
- 6A–6B: release reproducible, manifest/checksum, despliegue controlado por SHA, conexión GitHub→Hostinger, smoke tests y rollback automático — validadas e integradas.

## Bloque 05 — Operaciones, dependientas y administración — CERRADO

### 5A — Privacidad operativa de dependientas

- frontera central de datos por capability;
- dependienta recibe solo datos necesarios para preparar/entregar;
- administración conserva la vista completa;
- sin exposición de comisiones, gestoras ni metadatos administrativos innecesarios.

### 5B — Recogida en tienda canónica

- cierre de recogida mediante servicio de transición canónico;
- exige pedido de recogida, estado operativo listo, entrega física y cobro confirmado;
- registra actor, hora y evidencia;
- completa WooCommerce sin inventar estados paralelos;
- aprobación de comisión separada de payout.

### 5C — Integridad y reconciliación de inventario

- WooCommerce continúa como fuente oficial de stock;
- ventas/devoluciones ligadas al ciclo real del pedido;
- ajustes humanos limitados a movimientos explícitos de inventario;
- detección de discrepancias entre stock oficial y último saldo auditado;
- reconciliación física explícita cuando corresponde.

### 5D — Incidencias operativas estructuradas

- reutiliza el servicio canónico de incidencias;
- falta de producto, preparación incorrecta, cliente no recoge y mensajero no recoge;
- historial estructurado enlazado al evento canónico;
- reintentos idempotentes y sin sustitución destructiva de incidencias activas.

## Bloque 06 — Pruebas, releases y despliegues — CERRADO PARA PROTOTIPO

### 6A — Fundación de release y trazabilidad

- release reproducible desde SHA exacto de `main`;
- unidad desplegable: `wordpress/casa-viva-dropship-core`;
- `release-manifest.json` y `SHA256SUMS`;
- CI post-merge obligatorio antes de empaquetar.

### 6B — Despliegue controlado del prototipo

Flujo validado:

`main verde`
→ `release reproducible`
→ `checksum`
→ `SSH GitHub Actions`
→ `Hostinger`
→ `backup de carpeta previa`
→ `reemplazo del plugin`
→ `verificación de SHA desplegado`
→ `smoke HTTP/REST/privacidad`
→ `rollback automático si falla`.

Evidencia operativa final:

- sitio prototipo: `https://casavivadecuba.com`;
- WordPress observado: `7.0.4`;
- PHP CLI observado: `8.2.30`;
- WooCommerce observado: `10.9.4`;
- plugin previo observado: `casa-viva-dropship-core 3.4.0`;
- despliegue exitoso del SHA `1450e552adae727b73abd51c0c3513a707e54df8`;
- workflow `Deploy prototype Casa Viva` finalizado en `success`;
- conexión GitHub→Hostinger y copia remota verificadas;
- smoke final verde;
- rollback automático permanece habilitado.

## Próxima frontera

No inventar una fase 7A sin auditoría previa.

La siguiente tarea es una auditoría de puesta en marcha real y experiencia visible sobre el prototipo desplegado, contrastando:

- lo que el cliente puede hacer hoy en `casavivadecuba.com`;
- checkout y creación de pedido reales;
- acceso/UX de cliente, dependienta, mensajero, gestora y administración;
- correspondencia entre las interfaces WordPress existentes y los contratos ya implementados en los Bloques 01–06;
- huecos de navegación, identidad visual, onboarding, datos demo/legacy y preparación para pasar de prototipo a operación real.

La numeración del siguiente bloque/fases debe surgir de esa auditoría y no de supuestos.
