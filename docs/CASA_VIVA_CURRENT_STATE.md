# Casa Viva — estado actual

## Estado general

- 1A–1C.4: modelo canónico de pedidos, eventos, transiciones, logística, custodia, cierre y excepciones — validadas e integradas.
- 2A–2D: Centro Único del Pedido, notificaciones/enlaces, acciones de contacto y Centro Operativo del Mensajero — validadas e integradas.
- 3A–3E: navegación móvil del cliente, listado de pedidos, detalle único, seguimiento en vivo y ayuda/contacto contextual — validadas e integradas.
- 4A–4H: gestoras, referidos, comisiones, payouts, portal financiero, precios espejo, privacidad, cierre canónico de comisión y reasignación administrativa auditable — validadas e integradas.
- 5A–5D: privacidad operativa de dependientas, recogida en tienda canónica, integridad/reconciliación de inventario e incidencias operativas estructuradas — validadas e integradas.
- 6A–6B: release reproducible, manifest/checksum, despliegue controlado por SHA, conexión GitHub→Hostinger, smoke tests y rollback automático — validadas e integradas.
- 7A: camino de compra real y acceso al pedido — integrado en `main`.
- 7B: puertas de entrada y onboarding por rol — integrado en `main`.
- 7C: capa visual de lanzamiento y presentación del catálogo — cerrada funcionalmente con 7C.1, 7C.2 y 7C.3 integrados.
- 7D: certificación E2E de lanzamiento — EN CURSO; 7D.1, 7D.2 y 7D.3 integrados.

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

### 7C — Capa visual de lanzamiento y saneamiento del catálogo — CERRADO FUNCIONALMENTE

- 7C.1 refinó la navegación móvil y su base visual;
- 7C.2 simplificó copy y jerarquía de navegación;
- 7C.3 reforzó la presentación pública del catálogo sin mutar stock;
- WooCommerce sigue siendo fuente oficial de producto y stock;
- no se introdujeron motores paralelos ni cambios financieros.

### 7D — Certificación E2E de lanzamiento — EN CURSO

Estado integrado:

- 7D base: matriz mínima de certificación por rol definida en `docs/PHASE_7D_E2E_CERTIFICATION.md`;
- 7D.1: checkpoint de ejecución en `docs/PHASE_7D_CERTIFICATION_STATUS.md`;
- 7D.2: evidencia pre-despliegue en `docs/PHASE_7D_PREDEPLOY_EVIDENCE.md` con estado `PREDEPLOY READY — NO GO YET`;
- 7D.3: modelo operativo de despliegue autónomo en `docs/AUTOMATED_DEPLOYMENT_OPERATING_MODEL.md`;
- `main` de referencia al iniciar este checkpoint: `f3efa5b0af5539561e05f83f54efc2fc65901588`.

La vía canónica de despliegue es:

`GitHub/CI → release reproducible → SSH → script controlado en Hostinger → WP-CLI → smoke → rollback automático si falla`.

Hostinger Connector es auxiliar y no bloqueante.

## Próxima frontera

Completar 7D con evidencia reproducible del ciclo crítico:

`cliente → compra → atribución gestora → operación/dependienta → mensajero → seguimiento → entrega → administración → comisión/payout`.

Antes de declarar `GO` deben quedar verificados:

- `validate`, `integration` y `browser` verdes;
- fundación de release y contrato de staging/deploy verdes;
- release reproducible desde SHA exacto;
- despliegue controlado del mismo SHA certificado;
- smoke final verde;
- rollback validado/disponible;
- sin fallos P0/P1 abiertos;
- checklist operativo del primer día;
- checkpoint `CASA VIVA CORE — BASELINE ESTABLE PRE-NETWORK` con SHA exacto.

Casa Viva Network permanece `FUTURE` durante 7D. Al completar el baseline estable pre-Network, detenerse antes de Bloque 08 y avisar explícitamente que Casa Viva Core está listo para Network.