# Casa Viva Platform — Checkpoint operativo

## Resumen de reanudación

```text
FASE: 0 — Casa Viva Core estable
SUBFASE CERRADA: CV-LAUNCH-POLISH-001 — Portada móvil, SEO técnico y resiliencia visual
ESTADO: CERRADA — 3.10.15 DESPLEGADO Y VERIFICADO EN MÓVIL POR AUDITORÍA AUTOMÁTICA
SUBFASE ANTERIOR: CV-RECOVERY-001 — CERRADA Y DESPLEGADA
MAIN ANTES DEL PR: 2f2610293d990e200cb81301c18c7324ccabd924
SHA CERTIFICADO Y DESPLEGADO: cdff54f011ee18576f9a6df9eee2a85dd006a00b (plugin 3.10.15, PR #121)
PRODUCCIÓN ANTERIOR (rollback): 635dd1fc55aaf12f0e54080cef6c3f9743c763b2 (3.10.14)
NOTA: al fusionar cambios solo documentales, `main` puede avanzar sin que producción cambie
DEPLOY: Deploy prototype #23 — SUCCESS; auditoría móvil posterior #36867808732 — SUCCESS (0 scroll horizontal en 14 páginas)
INCIDENTE: Deploy #22 falló en el vale sintético porque NEXO (Render) respondió en frío; el rollback automático devolvió producción a 3.10.14 y el reintento #23 pasó
DECISIÓN: Ernesto aprobó el 2026-10-01 trabajar sin pedir permiso (fusiones y despliegues por el workflow oficial incluidos); siguen reservadas las decisiones comerciales, datos reales y acciones irreversibles
COSTO NUEVO UTILIZADO: 0 USD
```

## CV-CATALOG-SYNC — Catálogo real desde BizneCubano (2026-10-02)

Fuente del catálogo de Casa Viva: https://casaviva.biznecubano.com (SKU `BC-<id>`). Ernesto aprobó por escrito publicar, igualar stock y ocultar lo que no está en BizneCubano.

- Herramientas: instantánea (`catalog-source-snapshot.yml`), sincronización `catalog-sync.yml` con modos dry-run, apply y restore (`scripts/catalog/biznecubano-sync.php`), auditoría de fotos `catalog-photo-audit.yml`. PRs #123, #124 y #125.
- Apply 2026-10-02 (run 37014528832): 122 productos publicados con foto, 119 ajustes de stock con conteo en el libro de inventario, 126 ocultos como privados (63 NEXO y 63 BC retirados), 0 errores. Re-ejecución dry-run: 0 cambios (idempotente).
- Reversión: `catalog-sync.yml` modo `restore` vuelve a publicar lo ocultado (`_cvd_sync_hidden_from`).
- Auditoría móvil posterior #37014654943: 14 páginas sin scroll horizontal ni imágenes rotas.
- Fotos: 119 de 208 necesitan mejora (65 resolución, 29 foto real nueva, 16 fondo, 9 reencuadre). Lista: `/mnt/project-files/catalogo/fotos-revision-2026-10-02.csv`. Mejora con Higgsfield pendiente de que Ernesto valide 2 muestras.
- Pendiente: 29 productos variables (precio y stock por variante, sin sincronizar) y "Sobrecama tiwn" sin precio en BizneCubano.

## Qué estamos construyendo

Casa Viva será el primer comercio certificado de una plataforma multiempresa que después incorporará Todo Hogar, Estilo y Hogar y Dulce Hogar, Gestor multitienda, mensajería global y contabilidad offline para Windows.

No se deben iniciar esas fases hasta cerrar completamente la Fase 0.

## Cierre de CV-RECOVERY-001

| Paso | Estado | Evidencia |
| --- | --- | --- |
| 001.1 Crear Blueprint y checkpoint vivos | CERRADO | PR #116 fusionado |
| 001.2 Corregir orden de carga del guard del mensajero | CERRADO | Contratos de recarga verdes |
| 001.3 Incorporar prueba omitida al CI | CERRADO | Regresiones incluidas en `validate.yml` |
| 001.4 Inventariar pruebas huérfanas | CERRADO | Cuatro pruebas añadidas al CI; inventario documentado |
| 001.5 Ejecutar validación completa | CERRADO | aplicación, integración, navegador, release y staging verdes |
| 001.6 Fijar y desplegar SHA candidato | CERRADO | `001589c4ce4d0d31ac4986a18fbb20168e8369b3` |

## Evidencia de producción

- workflow: **Deploy prototype Casa Viva #20**;
- ejecución: `36466381630`;
- resultado: **success**;
- duración del job: 37 s;
- SHA solicitado y desplegado: `001589c4ce4d0d31ac4986a18fbb20168e8369b3`;
- release reproducible y checksum: aprobados;
- identidad del plugin desplegado: aprobada por lectura SSH del marcador;
- análisis real de un vale sintético: aprobado;
- smoke contra `https://casavivadecuba.com`: aprobado;
- rollback automático: disponible y no ejecutado porque no hubo fallo;
- portada pública: accesible y con catálogo renderizado tras el despliegue.

## CV-LAUNCH-POLISH-001 — Portada móvil, SEO y categorías

Objetivo de esta unidad:

- corregir el SEO técnico ausente en la portada sin depender del tema;
- garantizar un único H1 semántico aunque el tema no invoque `wp_body_open`;
- sustituir automáticamente imágenes rotas de enlaces a categorías WooCommerce por un fallback local y ligero;
- verificar el comportamiento en viewport móvil 390×844;
- conservar intactas tarifas, stock, pedidos, comisiones, payouts y demás lógica comercial.

Evidencia previa observada en producción:

- la portada pública indexada no expone H1;
- el título público observado es genérico;
- las cuatro tarjetas visibles de “Comprar por habitación” enlazan a categorías reales de WooCommerce;
- el tarifario sigue cubierto por `test-shipping-quote-contract.mjs` y `test-tariffs-mobile-polish.mjs` sin cambiar importes.

Implementación:

- PR: #118;
- candidato plugin: `3.10.14`;
- rama: `cv-launch-polish-001`;
- prueba de contrato: `artifacts/tests/test-launch-polish-001.mjs`;
- prueba navegador móvil: `tests/browser/launch-polish.spec.js`;
- SHA certificado del candidato (head del PR): `9698dda112c98c585b2f8e3444aa40348dbe344e`;
- CI de evidencia sobre ese SHA: Validar aplicación #393 (validate, integración y browser verdes), release #260 verde y staging #252 verde;
- viewport verificado: 390×844;
- fusionado a `main` en `635dd1fc55aaf12f0e54080cef6c3f9743c763b2` y desplegado con Deploy prototype #21 (https://github.com/ernest196391/Casa-Viva/actions/runs/36856337660);
- pendiente: comprobación visual en móvil de H1, title, description, canonical, tarjetas de categoría y tarifario.

### Verificación en producción (2026-10-01)

Nuevo workflow **Auditoría móvil de producción** (`.github/workflows/production-mobile-audit.yml`, PR #120): Playwright 390×844 de solo lectura contra `https://casavivadecuba.com`, automático tras cada despliegue exitoso y bajo demanda. Publica capturas y `report.json` en la rama `evidence/production-mobile`.

Resultado sobre 3.10.14:

- portada: title, meta description, canonical y un único H1 correctos;
- tienda, tarifario, Mi cuenta, accesos de gestoras y mensajeros, 6 categorías y 2 productos: HTTP 200, un H1, sin errores fatales ni de consola;
- imágenes de categoría: ninguna rota hoy (el fallback queda como protección);
- **P1 móvil encontrado**: la portada tenía scroll horizontal (647 px en viewport de 390). Causa: los textos `screen-reader-text` de precios rebajados de WooCommerce en la estantería “Ofertas” (`.cv-market-shelf`, con `overflow-x:auto`) son absolutos y su bloque contenedor quedaba fuera del carrusel, así que no se recortaban. Corrección en 3.10.15: `.cv-market-shelf{position:relative}` en `launch-polish.css` (solo portada). Reproducido y comprobado en Chromium (648 → 390).
- la auditoría de producción ahora falla si cualquier página auditada tiene scroll horizontal.

Cierre en producción: 3.10.15 desplegado con Deploy prototype #23 (https://github.com/ernest196391/Casa-Viva/actions/runs/36867758303). La auditoría automática posterior confirmó HTTP 200, un H1, cero imágenes rotas y ancho 390/390 en portada, tienda, tarifario, Mi cuenta, accesos de gestoras y mensajeros, 6 categorías y 2 productos.

Pendiente menor de catálogo: un producto sin imagen (placeholder de WooCommerce) visible en portada/tienda y en Electrodomésticos; requiere subir la foto real del producto.

Criterio de cierre de la unidad:

- validate, integración y navegador verdes;
- PR #118 fusionado a `main`;
- producción no cambia hasta una aprobación explícita de despliegue;
- después del futuro despliegue, comprobar H1, title, description, canonical, tarjetas de categoría y tarifario desde móvil antes de marcar producción cerrada.

## Hallazgos que continúan abiertos en Fase 0

### P2 — Lanzamiento web

- SEO de portada, resiliencia de imágenes y scroll horizontal: cerrados en 3.10.15;
- sanear catálogo y accesos;
- botones “Añadir al carrito” de tienda/categorías en azul genérico de WooCommerce con texto oscuro: bajo contraste y fuera de la identidad (portada sí usa el verde Casa Viva);
- tarifario móvil: la tarjeta de resultado muestra “Copiar” y “Compartir” vacíos antes de elegir zona;
- producto “Aspiradora de Mano Inalámbrica” sin foto (placeholder WooCommerce): requiere la imagen real;
- ejecutar cierre funcional de compra, vale, IA, mensajería y roles.

### Mantenimiento no bloqueante

- GitHub advierte que `actions/checkout@v4` todavía usa Node.js 20 y será forzado a Node.js 24;
- `ubuntu-latest` migrará a Ubuntu 26 a partir del 19 de octubre de 2026;
- estas advertencias no afectaron el despliegue #20, pero deben entrar en mantenimiento técnico.

## Próxima compuerta

Siguiente tarea: CV-LAUNCH-POLISH-002 — coherencia visual móvil de tienda/categorías (botones de compra con la identidad y contraste AA) y estado vacío del tarifario, verificado con la auditoría móvil de producción.

No iniciar Foundation multiempresa, nuevas tablas, compras de servicios ni cambios sobre pedidos reales sin aprobación expresa.

## Protocolo de continuidad

Al retomar el proyecto:

1. leer este checkpoint y el Blueprint maestro;
2. comprobar por separado que producción continúa en el SHA certificado y que `main` contiene ese SHA como ancestro o documenta explícitamente cualquier delta posterior;
3. ejecutar solamente la próxima acción aprobada;
4. actualizar estado, evidencia, costo y próxima compuerta;
5. no avanzar de fase por inferencia ni por CI verde.
