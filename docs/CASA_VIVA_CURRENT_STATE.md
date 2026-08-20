# Casa Viva — estado actual

## Estado general

- 1A–1C.4: modelo canónico de pedidos, eventos, transiciones, logística, custodia, cierre y excepciones — validadas e integradas.
- 2A–2D: Centro Único del Pedido, notificaciones/enlaces, acciones de contacto y Centro Operativo del Mensajero — validadas e integradas.
- 3A–3E: navegación móvil del cliente, listado de pedidos, detalle único, seguimiento en vivo y ayuda/contacto contextual — validadas e integradas.
- 4A–4H: gestoras, referidos, comisiones, payouts, portal financiero, precios espejo, privacidad, cierre canónico de comisión y reasignación administrativa auditable — validadas e integradas.
- 5A–5D: privacidad operativa de dependientas, recogida en tienda canónica, integridad/reconciliación de inventario e incidencias operativas estructuradas — validadas e integradas.
- 6A–6B: release reproducible, manifest/checksum, despliegue controlado por SHA, conexión GitHub→Hostinger, smoke tests y rollback automático — validadas e integradas.
- 7A: camino de compra real y acceso al pedido — integrado en `main`; la confirmación conecta al cliente autenticado con su seguimiento sin exponer claves públicas.
- 7B: puertas de entrada y onboarding por rol — integrado en `main`; accesos y redirects por rol corregidos sin duplicar motores funcionales.

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

## Bloque 07 — Puesta en marcha real — EN CURSO

La auditoría de lanzamiento está documentada en `docs/BLOCK_07_LAUNCH_READINESS_AUDIT.md` y define la secuencia 7A→7D.

### 7A — Camino de compra real y acceso al pedido — INTEGRADO

- PR #44 fusionado a `main`;
- la confirmación de compra mantiene WhatsApp como paso operativo;
- clientes autenticados pueden abrir directamente su pedido/seguimiento;
- no se exponen `order_key` ni rutas públicas de terceros;
- la regresión quedó cubierta por la suite de navegador.

### 7B — Puertas de entrada y onboarding por rol — INTEGRADO

- PR #45 fusionado a `main`;
- accesos y redirects por rol quedan alineados con las capacidades existentes;
- no se duplican motores de gestora, mensajero, dependienta o administración;
- la privacidad ya cerrada en Bloques 04–05 se conserva.

## Próxima frontera

### 7C — Capa visual de lanzamiento y saneamiento del catálogo

Frontera estricta:

- aplicar identidad Casa Viva a la superficie visible;
- simplificar navegación y copy;
- resolver estados vacíos y jerarquía de CTA;
- revisar presentación de nombres, categorías, imágenes y datos legacy;
- mantener WooCommerce como fuente oficial de stock;
- no recalcular ni alterar stock, pedidos, comisiones, payouts o finanzas;
- no desplegar a Hostinger hasta que `validate`, `integration` y `browser` estén verdes y exista una decisión explícita de despliegue.

Después de 7C, la siguiente frontera prevista es 7D — certificación de lanzamiento E2E sobre el sitio desplegado, con matriz por rol, rollback y checklist operativo.