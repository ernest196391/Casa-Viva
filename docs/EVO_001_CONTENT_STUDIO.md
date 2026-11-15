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
| `scripts/catalog/evo-001-recorte-smoke.py` | Prueba acotada del endpoint local de recorte, sin generación/QA API/publicación |

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

### Prueba controlada de recorte real — compuerta abierta

No ejecutada todavía: el entorno no dispone de sesión SSH autenticada al VPS y el bridge vivo (consulta #217, done/returncode 0) no expone recorte entre sus acciones fijas. No desplegar/modificar el bridge ni pedirle comandos arbitrarios para conseguirla. El PR conserva esta limitación visible; una llamada sin credenciales no acredita recorte.

Ejecutar el script preparado en una sesión autorizada del VPS con una fotografía real existente y `IMAGEN_PANEL_KEY` ya presente en el entorno. El script fija `http://127.0.0.1:5020/imagen/recorte`, rechaza redirects y no permite elegir `/panel`. Solo crea un PNG temporal y emite JSON con hashes, dimensiones, duración, `fiable` y motivos; nunca imprime clave ni imagen en base64. No modifica WordPress, catálogo, PM2/systemd o presupuestos.

Guardar la salida sin secretos, revisar visualmente el PNG frente a la fuente, y comprobar que los registros del proveedor no tienen generación/QA nueva. `fiable=true` no sustituye revisión de forma, color, etiquetas y piezas finas. Eliminar después únicamente el PNG temporal creado por esta prueba. Si falta servicio/modelo/clave, registrar el fallo y mantener bloqueo; no instalar ni reiniciar servicios sin aprobación.

## Reversión

Antes de cualquier despliegue aprobado: registrar SHA de Core y VivaBot instalados, backup de plugin/servicio y configuración sin secretos; preservar uploads y base de datos. Desplegar solo SHA probado y aprobado mediante el proceso existente de manifest/checksum y rollback.

Si el candidato falla: detener ejecuciones de contenido; restaurar el paquete anterior del Core y el SHA anterior de VivaBot con su procedimiento existente, sin borrar uploads ni metadatos. Las correcciones no requieren migración SQL ni eliminación de datos. Advertencia concreta: volver al software anterior reabre sus rutas facturables y su política de publicación; mantenerlas deshabilitadas por configuración/operación hasta revisar el incidente. No usar el rollback para ejecutar generación de pago.

Para revertir solo una imagen: usar restauración del motor, que debe conservar fotos añadidas después. No recuperar un dump completo para deshacer una fotografía. Antes de restaurar una ficha completa, comprobar sus versiones existentes en `CVD_Catalogo_Auto`; son operaciones distintas.

## Siguientes pasos

1. Obtener CI verificable del head combinado y del complemento VivaBot; registrar URLs y SHA.
2. Completar smoke autenticado y revisión visual del recorte real, sin cambios de producción.
3. Solicitar aprobación explícita de despliegue de ambos SHA; no auto-merge ni auto-deploy.
4. Solo después abrir EVO-002: auditoría integral/puntuación/cola; las interfaces esperan los diseños Stitch.
