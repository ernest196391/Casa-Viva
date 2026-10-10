# Casa Viva Platform — Checkpoint operativo

## Resumen de reanudación

```text
FASE: 0 — Casa Viva Core estable
SUBFASE ACTUAL: CV-ENTREGA — Entrega a la dueña (ver docs/CASA_VIVA_ENTREGA_DUENA.md)
DOMINIO: https://casaviva.company (WP_PATH /home/u824654880/domains/casaviva.company/public_html; workflows corregidos en PR #144)
ESTADO: 3.13.9 DESPLEGADO (deploy 37328697869: portada con ofertas reales). En main sin desplegar: 3.13.10 (#151, scroll horizontal en móvil) y 3.13.11 en PR #152 (cabecera de ordenador, ventana de ofertas solo en tienda/categorías, sin «Inicio» duplicado). Recorrido clienta + gestora verde el 2026-10-06
SUBFASES ANTERIORES: CV-PREMIUM-001 (3.13.0–3.13.7), CV-CURRU (3.11.0–3.12.1), CV-LAUNCH-POLISH-002, CV-CATALOG-SYNC y CV-LAUNCH-POLISH-001 — CERRADAS
NOTA: al fusionar cambios solo documentales, `main` puede avanzar sin que producción cambie
DECISIÓN: Ernesto (2026-10-02): checkout sin confirmar pedido; Curru da existencias exactas a gestoras y solo estados a clientes; mantener identidad beige/verde; destacar con ventana las ofertas ya existentes
DECISIÓN: Ernesto (2026-10-05): quitar de la tienda todo lo que no lleve a la compra ni ayude a navegar; Higgsfield permitido para fotos y vídeos sin abuso; animaciones y ventanas orgánicas manteniendo la marca; número del negocio/bot 5354056173; recogida en la dirección de la web (Calle Conill A esquina 45 #864, Nuevo Vedado), 9:00–17:00; OpenAI ya activo en Curru
DECISIÓN: Ernesto (2026-10-06): garantía y devoluciones las atiende una persona caso a caso hasta tener política; al por mayor → WhatsApp de Lennys (53 5688 5368) con resumen; la tienda no se elimina (catálogo), solo se quita «Inicio» del menú
VIVABOT: 34/34 preguntas activas en nexo-production; servidor en d39b1d1 con CURRU_KEY; pendiente git pull de 1571947, prueba desde otro teléfono y cambio de claves expuestas en capturas
CATÁLOGO: Sobrecama tiwn (BC-102299) retenida con `catalog-sync.yml` modo hold (run 37301636740); Aspiradora de Mano Inalámbrica ya oculta (no está en BizneCubano)
PENDIENTE ANTES DE ENTREGAR: ver la lista final de docs/CASA_VIVA_ENTREGA_DUENA.md
CUENTAS DE PRUEBA: gestora de prueba creada con `test-accounts.yml` (secretos JOURNEY_GESTORA_*); recorrido clienta + gestora 100 % verde el 2026-10-06; dependienta piloto existente: cv_dependienta_piloto
FOTOS: 44 fotos reales ampliadas a 2K con Higgsfield (118 créditos) aplicadas con `catalog-photos-apply.yml` (revert disponible); 15 descartadas porque la IA deformaba etiquetas o texto
```

## CV-FOTOS-SYNC — Fotos cambiadas en BizneCubano (2026-10-10)

- Lennys cambió fotos en BizneCubano (albornoz, alfombra persa, árbol de Navidad, cestos, mochilas, puertas de corredera…) y no llegaban: la sincronización solo copiaba la foto al crear el producto.
- `cvd_sync_photo`: compara la foto de BizneCubano con `_cvd_bc_image` (o, la primera vez, con el `_source_url` de la foto original) y, si cambió, la descarga y la pone como principal (olvida la versión mejorada vieja). Informe: `photo_updates`.
- La caja (NEXO) toma la miniatura de la web en su importación horaria.

## Mejora #23 — Recogida sin datos de mensajería (3.13.27, 2026-10-10)

En recogida en tienda el pedido ya no guarda dirección, reparto, mapa, fecha/horario ni vuelto precargados de pedidos anteriores; el resumen del formulario no muestra vuelto. Contrato en `test-store-pickup-5b.mjs`.

## CV-MERCADO-001 — Mercado «bajo pedido» (3.13.26, 2026-10-09)

- `CVD_Market_Bridge`: `POST /casa-viva/v1/bot/market/publish` y `/bot/market/{item}/unpublish` (clave X-Vivabot-Key). SKU `MK-<id>`, categoría «Bajo pedido», sin control de stock, aviso «confirmamos disponibilidad en 1 hora», comisión fija = mitad de la ganancia (la calcula VivaBot). Fotos solo desde Supabase Storage. El proveedor no se guarda en la web.
- La sincronización de BizneCubano ya no oculta los `MK-*`.
- Decisiones de Ernesto: publicar en Casa Viva; proveedor +5/+10 USD, >300 USD +5 %; comisión del gestor = mitad; al vender, VivaBot pregunta a Ernesto antes de escribir al proveedor.
- Pruebas: `php -l`, `test-market-bridge.mjs`. Sin prueba E2E contra producción todavía.

## CV-EQUIPO-001 — Arreglos pedidos por Lennys y Thaly (3.13.25, 2026-10-09)

- Vale de WhatsApp: nueva sección *PAGO* con forma de pago y vuelto (misma regla que el bot, `CVD_Bot_Bridge::change_label`), y enlace 📷 a cada producto para que mensajero y dependienta no confundan modelos parecidos.
- Botón de ayuda (asistente contextual): quien entra por el enlace de una gestora ve el WhatsApp de esa gestora, no el central.
- Ficha de producto: se ocultan al mostrar las líneas de la descripción con precio (vienen de BizneCubano); el precio válido es el de WooCommerce/gestora. No cambia datos.
- Pendiente de comprobar en producción: botón «atrás» a mitad del pedido y vuelta tras «continuar por WhatsApp» (carrito vacío + «Volver a la tienda» ya existían).
- Pruebas: `php -l` en los 4 archivos; prueba local de `strip_description_prices`. No se ejecutó el recorrido E2E.

## CV-HOME-DEALS — Portada con ofertas reales (2026-10-05)

Auditoría en móvil (390×844) de Amazon, Gymshark y Brooklinen (Shein y Temu bloquean con captcha): ninguna usa una foto con «Comprar ahora»; la primera pantalla enseña buscador, categorías, a dónde entregan y productos con precio. En Casa Viva el primer precio estaba a 1.381 px.

- 3.13.8: Curru en móvil, la barra inferior (z-index 9998) tapaba la caja de escribir del panel (80). Con Curru abierto el panel sube a 10000 y se ocultan la barra inferior y el WhatsApp flotante.
- 3.13.9: portada = buscador y categorías → «📍 Entregamos en La Habana · mira cuánto cuesta en tu zona» (tarifario) → «Ofertas de hoy» (hasta 8 rebajas reales de WooCommerce con -%, precio y Añadir/Elegir) → Más vendidos → Nuevos → Regala desde fuera → Ventajas. Sin rebajas solo sale la línea de entrega. Se ocultan la foto con «Comprar ahora», la estantería de ofertas repetida, «Comprar por habitación» y el Blog en la cabecera; la ventana de ofertas ya no sale en la portada. Reordenado con CSS sobre el tema `casa-viva-storefront` (no versionado en el repo). Primer precio a 657 px y botón Añadir a 731 px en la copia local verificada.
- Curru: responde la dirección y el horario de recogida; la IA ya no recomienda productos en respuestas de mensajería, pagos o recogida.
- Auditoría móvil de producción: nuevas comprobaciones de precio visible sin scroll y caja de Curru tocable.
- `catalog-sync.yml`: modos `hold`/`unhold` con `skus`; lo retenido (`_cvd_hold`) no se republica.

## CV-PREMIUM-001 — Auditoría de recorrido y tienda premium (2026-10-02)

Auditoría `customer-journey-audit.yml` (run 37041230617) como clienta en móvil: portada → categoría → producto → carrito → checkout sin confirmar → Curru. Investigación de patrones en `/mnt/project-files/auditoria/investigacion-tiendas-premium-2026-10-02.md`.

- Curru y existencias: gestoras y operación reciben cantidades exactas por modelo (`CVD_Product_Search::cards( $ids, true )`); clientes y visitantes solo «Disponible», «Últimas unidades» (umbral de stock bajo de WooCommerce) o «Agotado». El endpoint público de búsqueda nunca expone cantidades. La IA recibe `CONTEXTO` y la misma regla.
- Ficha de producto: barra fija de compra en móvil (encima de la barra inferior; Curru sube y el saludo se oculta), garantías junto al botón con los métodos reales (WhatsApp o transferencia, entrega por municipio o recogida, Curru) y disponibilidad sin cantidades para clientes.
- Portada: imagen generada con Higgsfield (sin productos, enlace remoto `_min.webp`, filtro `cvd_home_hero_image`), titular, botón a la tienda y línea de entrega.
- Ofertas: ventana una vez por sesión (al bajar más de la mitad o a los 15 s; nunca en ficha, carrito, checkout ni cuenta) con hasta 4 productos rebajados reales; insignias con porcentaje real; `/tienda/?cvd_ofertas=1` lista solo ofertas.
- Verificación 3.13.0 en producción: portada con imagen, opciones de entrega como tarjetas, Curru a la clienta dice «algunas están en últimas unidades» sin cifras; 14 páginas sin scroll horizontal ni imágenes rotas. Hallazgo: la barra fija no aparecía porque el botón quedaba tapado pero «visible» bajo la barra inferior; 3.13.1 descuenta la barra inferior, oscurece el degradado de la portada y oculta el saludo de Curru en la portada móvil (tapaba el buscador).
- 3.13.2: la barra fija se muestra cuando el botón no se ve entero (`intersectionRatio < 0.99`); el recorrido de auditoría registra `report.buybar` (existencia, visibilidad y posiciones del botón y la barra inferior).
- 3.13.3: la barra fija se calcula con la posición real del botón y de la barra inferior en cada desplazamiento (antes la barra inferior aún no existía al cargar el script). Probado en Chromium local: visible al cargar con el botón tapado, oculta con el botón entero, visible al pasarlo.
- 3.13.3 (portada): texto más corto («Todo para tu casa, sin salir de ella.»), dos botones («Comprar ahora» y «Ver ofertas», este solo si hay rebajas) y portada más baja en móvil (380 px) para que el buscador quede en la primera pantalla.
- 3.13.4: Curru y el botón de WhatsApp del tema (`.cv-whatsapp`) quedan en una sola columna a la derecha, con el mismo tamaño y margen; WhatsApp ya no queda tapado por la barra inferior y ambos suben con la barra fija de compra. En producción estaban a 12 px de distancia horizontal y WhatsApp quedaba medio oculto (sonda en la rama cv-probe-floats).
- 3.13.5: pedido recibido al estilo Colo Shop (plantilla propia `templates/checkout/thankyou.php`): título «Tu pedido está listo», referencia, botón principal «Confirmar por WhatsApp», tarjeta con productos y totales, tarjeta de entrega o recogida, y «Ver seguimiento» (solo dueña con sesión, regla 7A) + «Seguir comprando». Se quitan las tarjetas de resumen de WooCommerce, «Dirección de facturación», el botón azul de mapa y el «Seguir mi pedido» duplicado. Vale de WhatsApp 3.2.0 con el formato de Colo Shop (PEDIDO CASA VIVA, TOTAL, PRODUCTOS, IMPORTES, ENTREGA/RECOGIDA, CLIENTE, SEGUIMIENTO, PARA CONFIRMAR). Curru y el WhatsApp flotante no aparecen en carrito, pago ni pedido recibido. Carrito: nombre sin subrayado, sin la descripción genérica y sugerencias en cuadrícula de 2 columnas (máximo 4) con botones verdes.
- 3.13.7: VivaBot (WhatsApp) puede preguntar a Curru en `/casa-viva/v1/curru/ask` con la cabecera `X-VivaBot-Key` y `context` = cliente o gestora. La clave se genera en WooCommerce › Curru (asistente), se muestra una vez y solo se guarda su hash (`cvd_vivabot_key_hash`). Clave incorrecta: 401, nunca modo visitante. Límite propio: 600 por minuto. Se registra el contexto, no la pregunta.
- 3.13.6: la barra negra de administrador de WordPress ya no aparece sobre la tienda (filtro `show_admin_bar`), ni siquiera para administradores; el panel sigue en /wp-admin/. Pedido de Ernesto tras verla en el carrito.
- Marca: botones de carrito, checkout y añadir en verde Casa Viva; precio en verde, rebaja en terracota; opciones de entrega del checkout como tarjetas con radio propio.

## CV-CURRU — Asistente y buscador (2026-10-02)

Referencias auditadas: 23 y 28 (asistente «Veci») y Colo Shop (asistente «Colo»). Se adoptó: avatar con foto, panel lateral, respuestas con tarjetas de producto y precio real de WooCommerce, añadir al carrito desde el chat, dictado por voz, búsqueda tolerante (acentos, plurales, sinónimos cubanos) y tarifas por municipio sin inventar precios.

- 3.11.0 (PR #128): `CVD_Product_Search` (REST `casa-viva/v1/products/search`), Curru (`casa-viva/v1/curru/ask`, reglas locales: productos, mensajería por zona, pedidos, pagos, gestora, mensajero, WhatsApp), página WooCommerce › Curru para la foto.
- 3.11.1: buscador de la tienda (tienda, categorías y resultados) con sugerencias con foto y precio, combobox accesible por teclado; también mejora los buscadores del tema. La búsqueda de la tienda usa el mismo motor tolerante («pailas» encuentra sartenes). La auditoría móvil de producción abre Curru (`curru.png`) y prueba el buscador (`buscador.png`).
- 3.12.0: Curru con dibujo propio (`assets/curru-avatar.svg`, se usa si no hay URL de foto en wp-admin) e IA compatible con OpenAI (`CVD_Curru_AI`). Ernesto eligió OpenAI el 2026-10-02. La clave la pega él en WooCommerce › Curru (nunca en el repo ni el chat). La IA solo redacta sobre la respuesta local verificada y elige productos de una lista cerrada; tarjetas y precios salen de WooCommerce; si falla, responde la regla local.
- 3.12.1: avatar por defecto = ilustración 3D generada en Higgsfield (enlace remoto, versión `_min.webp`); si no carga, el navegador usa `curru-avatar.svg`. Para fijarla de forma permanente, subirla a Medios y pegar su URL en WooCommerce › Curru.
- Pendiente: Ernesto pega la clave de OpenAI en WooCommerce › Curru.

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

CV-LAUNCH-POLISH-002 cerrada (3.10.16): Añadir al carrito en verde #006068 con texto blanco en tienda/categorías; Copiar/Compartir del tarifario ocultos hasta consultar una tarifa.

Siguiente: entrega a la dueña para revisión; pendientes de catálogo en la sección CV-CATALOG-SYNC (29 fotos reales nuevas, 29 productos variables).

No iniciar Foundation multiempresa, nuevas tablas, compras de servicios ni cambios sobre pedidos reales sin aprobación expresa.

## Protocolo de continuidad

Al retomar el proyecto:

1. leer este checkpoint y el Blueprint maestro;
2. comprobar por separado que producción continúa en el SHA certificado y que `main` contiene ese SHA como ancestro o documenta explícitamente cualquier delta posterior;
3. ejecutar solamente la próxima acción aprobada;
4. actualizar estado, evidencia, costo y próxima compuerta;
5. no avanzar de fase por inferencia ni por CI verde.
