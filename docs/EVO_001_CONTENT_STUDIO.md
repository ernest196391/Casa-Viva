# EVO-001 — estabilización del motor de contenido

## Alcance y estado

Solicitud del propietario del 15-nov-2026. Base Casa Viva `8f3e525985c6e1a4ebf8f249925672a6d8956aa5`, servidor v2 del PR #224 `bbd10f21d82fd53fc8e6d1aed7d904e3ce8001e0`. Rama `evo/evo-001-content-studio`. Core candidato 3.14.171. Sin merge a main ni despliegue autorizado.

Se reconcilia la lógica v2 con main preservando la pantalla PHP de `page()`, JS, CSS, fixtures de navegador y los cambios más recientes del Core. Los assets deben ser idénticos a main. La suite visual de la interfaz v2 sigue disponible en #224; no se instala esa interfaz ni se descartan sus diseños. La suite activa de main se conserva y añade una prueba de bloqueo de API con sesión real.

## Cambios

| Archivos | Resultado |
|---|---|
| `class-cvd-content-studio.php` | Servidor v2, ruta legacy de listado conservada, cero fallback pagado, preparación sin publicación autónoma, restauración parcial de galería y versión de texto en opciones importadas |
| `class-cvd-dudas-catalogo.php` | Conserva resultado al aplazar y decide destino remoto por tipo de pieza |
| `class-cvd-content-policy.php`, `class-cvd-photo-ai.php` | Política cerrada: `evo_budget_zero` 403 antes de cualquier proveedor, sin excepciones por credenciales, filtros ni flags de la petición |
| `casa-viva-dropship-core.php` | Carga las clases y aumenta versión del candidato sin perder main |
| `artifacts/tests/test-content-studio*.php`, `test-content-policy.php` | Mantiene contratos anteriores; expectativas de pago/auto sustituidas por bloqueo/aprobación humana; regressiones de los tres errores |
| `integration/tests/content-studio.php`, fixture del motor | WordPress/WooCommerce reales, transporte y permisos, proveedor simulado, galería y resultados aplazados |
| `tests/browser/content-studio.spec.js` | Pantalla anterior íntegra más caso de seis intentos de API bloqueados |
| `.github/workflows/validate.yml` | Ejecuta unidades nuevas además de las suites existentes |
| `scripts/catalog/evo-001-recorte-live.php`, workflow dedicado | Prueba acotada por SSH existente/WP-CLI stdin del adaptador instalado; sin generación/QA API/publicación |

Las clases `CVD_Content_Studio`, `CVD_Photo_AI`, `CVD_Dudas_Catalogo`, REST, prioridades, recorte y restauración se reutilizan. Catálogo, SEO, stock, precios, SKU, variantes, comisiones y pedidos conservan autoridad en Core/WooCommerce.

### Errores corregidos

1. Resultado → Revisión → Aprobar: se conserva el resultado vigente para datos, texto y foto; no se vuelve a la propuesta original.
2. Restaurar/rechazar HERO: no repone toda la galería histórica. Revierte solo la inserción de la principal anterior y mantiene las adiciones, eliminaciones y orden posteriores. Para snapshots antiguos se infiere esa inserción a partir de la galería guardada. Si otro actor cambió la principal, sigue devolviendo 409.
3. Foto remota por URL: HERO tiene destino web; LIFESTYLE y BENEFICIO tienen destino anuncios.

### Presupuesto y autonomía

- Presupuesto adicional del motor: 0. No hay un interruptor de petición/administración que lo eleve en EVO-001.
- `ia/generate`, `ia/poll`, `vps_image` y sugerencias de texto externo devuelven 403 antes de red. Cloudflare se bloquea mientras su cuota no esté certificada; composición local existente permanece.
- Recorte fiable prepara una opción. Recorte no fiable, ausente u ocupado no permite escalar a IA. Crear, responder a pedir foto o configurar credenciales tampoco activa pago.
- `auto_activa()` es false; `publicar_hero(..., true)` está bloqueado. Aprobación humana explícita, publicación manual previa y restauración se conservan.
- Complemento en `ernest196391/vivabot`, misma rama lógica EVO-001: QA de catálogo queda en REVISIÓN humana; `--sin-pago` y su ausencia no llaman a visión; `/imagen/panel` y las funciones `imagen-panel`/`catalogo-hero*` se bloquean. Recorte local se conserva. No cambia las imágenes ordinarias de WhatsApp o su presupuesto.
- El código servidor de recorte **sí está versionado** en VivaBot, `scripts/recorte/recorte.mjs`; se corrige esa limitación de descubrimiento de la auditoría inicial. Su existencia no prueba instalación ni calidad real.

## Validación

Ejecutar en CI y, donde estén disponibles, localmente:

```bash
php artifacts/tests/test-content-studio.php
php artifacts/tests/test-content-studio-v2.php
php artifacts/tests/test-content-policy.php
scripts/integration.sh up
docker compose -f integration/compose.yml run --rm --no-deps cli wp eval-file /var/www/html/integration-tests/content-studio.php
# El workflow prepara WordPress, usuario sintético y Playwright antes del navegador.
CONTENT_STUDIO_BROWSER=1 npx playwright test tests/browser/content-studio.spec.js --workers=1 --reporter=line
scripts/integration.sh down
```

Localmente se extrajo PHP 8.4/GD/mbstring/tokenizer a una carpeta temporal para poder ejecutar contratos; no se instaló software en los servidores. CI conserva PHP 8.1 y el entorno WordPress/MariaDB del proyecto. Los resultados PHP locales y mocks no sustituyen integración ni VPS.

Pruebas de VivaBot: `node --test test/hero.test.js test/evo-content-policy.test.js`. El caso de motor real instala una `fetch` que falla si se alcanza y verifica cero llamadas con credenciales presentes; las pruebas de contrato también comprueban revisión humana.

### Evidencias CI y recorte real

- Core de aplicación `89abd45b001560ec04324d031bff68bb5598ae27`: [CI completo 43744444152](https://github.com/ernest196391/Casa-Viva/actions/runs/43744444152) en success: validate, integration, browser, content-studio y control-os-postgres. Release/staging también success.
- Content Studio: 14/14 pruebas de navegador, incluida la pantalla anterior y seis intentos facturables rechazados; integración WordPress/MariaDB confirmó los tres errores corregidos y ambas rutas de restauración conservando altas y bajas posteriores. Navegador general: 39 aprobadas, 13 omitidas por estar reservadas al job dedicado, sin fallos.
- VivaBot `d95b31801b58578da93a478ec33ee0f37a75a814`, [PR #42](https://github.com/ernest196391/vivabot/pull/42): [VivaBot verification 43744354065](https://github.com/ernest196391/vivabot/actions/runs/43744354065) y [Pedidos y vales 43744354087](https://github.com/ernest196391/vivabot/actions/runs/43744354087) success. Incluye npm run check y el proveedor espía de EVO-001.
- Recorte real: [smoke 43744911546](https://github.com/ernest196391/Casa-Viva/actions/runs/43744911546) success sobre `52fd70ee8ccd2379b5c20bd65b7f92278eba4a95`. [Artefacto antes/después/JSON 14174360264](https://github.com/ernest196391/Casa-Viva/actions/runs/43744911546/artifacts/14174360264), retención hasta 29-nov-2026. Resumen durable y hashes en `docs/evidence/EVO_001_RECORTE.json`.

Se superó la limitación inicial del bridge usando exclusivamente la conexión SSH existente de GitHub Actions a Hostinger y `wp eval-file -`. La primera tentativa con `/dev/stdin` falló antes de llamar al adaptador; se corrigió a la entrada estándar documentada por WP-CLI. No se instaló código en el plugin ni se modificaron servicios. El PHP de prueba se transmitió por stdin.

Se leyó la foto del producto 52, adjunto 6490 (no etiquetada como `_cvd_foto_real`; fuente actual de catálogo), y se llamó una vez a `CVD_Photo_AI::vps_recorte()`. Respuesta PNG 377×872, 167434 bytes, 33,24 s, `fiable=true`, motivos vacíos. Se descargaron el original y el PNG, se verificaron sus hashes y se borraron únicamente los dos archivos temporales de evidencia del servidor. No se actualizaron productos, galería, presupuestos, servicios ni el código desplegado. No hubo generación ni QA de pago en este recorrido.

**Revisión visual:** mantiene el carro y sus colores, pero quedan restos claros de fondo en la parte inferior y halo en bordes. No se aprueba como imagen lista para publicación. `fiable=true` de la máscara no certifica la calidad fotográfica; se mantiene revisión humana y cero publicación autónoma. Esta prueba acredita funcionamiento real del recorte, no calidad universal para todo el catálogo ni cuatro fotos profesionales.

El workflow queda limitado a esta rama y a cambios del propio workflow o script. Las actualizaciones de documentación no repiten el recorte. No debe activarse como tarea nocturna ni ampliarse a pago en EVO-001.

## Reversión

Antes de cualquier despliegue aprobado: registrar SHA de Core y VivaBot instalados, backup de plugin/servicio y configuración sin secretos; preservar uploads y base de datos. Desplegar solo SHA probado y aprobado mediante el proceso existente de manifest/checksum y rollback.

Si el candidato falla: detener ejecuciones de contenido; restaurar el paquete anterior del Core y el SHA anterior de VivaBot con su procedimiento existente, sin borrar uploads ni metadatos. Las correcciones no requieren migración SQL ni eliminación de datos. Advertencia concreta: volver al software anterior reabre sus rutas facturables y su política de publicación; mantenerlas deshabilitadas por configuración/operación hasta revisar el incidente. No usar el rollback para ejecutar generación de pago.

Para revertir solo una imagen: usar restauración del motor, que debe conservar fotos añadidas después. No recuperar un dump completo para deshacer una fotografía. Antes de restaurar una ficha completa, comprobar sus versiones existentes en `CVD_Catalogo_Auto`; son operaciones distintas.

## Siguientes pasos

1. Revisar el diff y aprobar explícitamente el despliegue de ambos PR/SHA; no auto-merge ni auto-deploy.
2. Tras aprobación: usar el proceso de release existente y verificar rutas, política 403, aprobación humana y restauración en un producto controlado.
3. Solo después abrir EVO-002: auditoría integral/puntuación/cola; las interfaces esperan los diseños Stitch. El recorte de objetos con huecos necesita revisión humana.
