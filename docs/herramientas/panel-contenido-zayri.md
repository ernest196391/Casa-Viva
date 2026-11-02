# Mi catálogo y fotos — piloto de Zayri

## Alcance autorizado (2026-11-02)

Panel móvil conectado al WooCommerce de `https://casaviva.company`. Zayri puede editar títulos y descripciones, subir una foto principal conservando la anterior, guardar mercancía nueva y publicar productos completos. El contenido humano se conserva al sincronizar BizneCubano. No cambia la autoridad de precios, stock, SKU, variantes ni pedidos.

Código base revisado: `038050a12575e9039d9a766c25036d7fec937ada` (plugin 3.14.111). Los estados antiguos del checkpoint no prueban la versión de producción actual.

## Acceso y activación

- Página: `/?cvd_contenido=1`, accesible también desde Mi cuenta → Mi catálogo y fotos.
- Autorización: sesión WordPress y capability `cvd_edit_catalog_content`, o `manage_woocommerce`. Nunca pública.
- Tras desplegar y verificar, administrador → WooCommerce → Acceso al catálogo → crear `zayri`, nombre Zayri. La contraseña temporal se genera en servidor y se muestra una sola vez al administrador; no se guarda en Git ni logs ni se envía a un correo inventado.
- Zayri puede cambiarla desde el panel usando la contraseña actual y una nueva de al menos 12 caracteres. El cambio cierra su sesión.
- No se crea automáticamente ninguna cuenta al instalar o activar el código. No se reutilizan ni elevan cuentas existentes por coincidencia de nombre.
- Revocar el permiso: `wp user remove-cap zayri cvd_edit_catalog_content`.

## Flujo

1. Entrar, buscar un producto o pulsar Nueva mercancía.
2. Editar título, descripción y resumen. El resumen de productos MK conserva el aviso comercial canónico.
3. Guardar. Los productos existentes publicados actualizan su contenido; los nuevos nacen en borrador, sin inventar precio ni stock.
4. Elegir JPG/PNG/WebP (hasta 8 MB), subir como principal. La foto anterior queda en galería. En un producto publicado la subida actualiza la foto pública.
5. Publicar cuando haya foto, descripción y un precio configurado por el sistema comercial existente. Este panel no introduce ese precio.
6. Añadir datos confirmados, situación de compra, objeción y evidencia. Se guardan con la ficha, aparte del texto público.
7. Preparar una composición 9:16 o 1:1; en características señaladas, tocar los detalles de la imagen para colocar los números. Descargar JPG y textos.

Hay control de versión, exclusión mutua por producto, rechazo de ediciones antiguas y hasta diez instantáneas previas del contenido. Reintentar la creación con el mismo token devuelve el mismo borrador. Las instantáneas se conservan para recuperación administrativa; este piloto no tiene todavía un botón de restauración.

## Relación con el video y la skill

Fuente aportada por Ernesto: https://higgsfield.ai/@adilinthewildtempo/blogs/high-performing-ads-6-formats-for-e-commerce-and-services-2

Skill original `create-ads-with-agents` 2.1.2 descargada e instalada en Codex, no incluida ni ejecutada dentro de WordPress. SHA-256 del paquete original: `5f5a849986b52c9d4649865bdd06074f0f5550e910b7d623084a407e692ebfc0`. El archivo contiene instrucciones, referencias y un registro local de trabajos; no contiene credenciales de producción.

Se analizó también `meta-ads-client-brief.pdf`: negocio, producto, público/alternativas, evidencia, oferta, destino, resultados, presupuesto y fotos. El panel recoge un subconjunto práctico por producto; no afirma que haya investigado compradores ni anuncios.

PROOF = Promise, Receipts, Offer, Objection, First step. Se adapta como guía de mensaje y control de afirmaciones.

Disponible en el piloto:
- composición de foto real con características numeradas;
- ficha técnica textual con datos confirmados;
- tarjeta de producto y precio actual;
- presentación de uso cotidiano basada en el texto aportado;
- sugerencia de título y descripción con el servicio de texto existente, su clave del servidor, límites y guardas; si no responde, texto a partir de los datos aportados;
- prompts adaptados a Casa Viva para continuar la generación externa.

Limitaciones explícitas:
- Las plantillas gratuitas no generan escenas, fondos o interiores nuevos con IA ni eliminan el fondo original. «Uso cotidiano» usa la foto y el texto suministrados.
- No hay llamadas a Higgsfield ni consumo de créditos de imágenes desde este panel. La conexión y los costos de generación nueva deben configurarse en una unidad posterior. Instalar una skill no crea esa conexión.
- No se fabrican reseñas ni estrellas. La Review Card permanece pendiente de una reseña auténtica con fuente y permiso.
- No se ejecuta investigación de Meta Ad Library, ni se lanzan campañas, ni se prometen resultados publicitarios.
- El servicio de texto es opcional; el límite de aplicación no garantiza que el proveedor mantenga su plan gratuito.

## Sincronización y datos

`_cvd_content_owned` protege la imagen contra BizneCubano, el aplicador masivo de fotos ampliadas y el reemplazo de imágenes del puente de mercado; rechaza reescrituras/reversiones del generador automático de fichas. `_cvd_texto_fijo` conserva nombre y descripción en el puente MK existente. Los precios y existencias siguen sus procesos actuales.

`_cvd_content_local=1` distingue mercancía creada por el equipo: el importador no la oculta por no existir en BizneCubano. Los productos BC retirados conservan la regla de ocultación comercial anterior; proteger el contenido no los mantiene a la venta.

## Verificación y entrega

Pruebas PHP ejecutables en `artifacts/tests/test-content-studio.php`: acceso, integridad comercial, limpieza de HTML, edición antigua, duplicación por reintento, borradores incompletos, historial y foto protegida. Se conservan las pruebas del Taller. Se incorporan al CI existente.

QA local: interfaz PHP real renderizada con funciones WordPress de prueba y API local simulada; foto real de la biblioteca Casa Viva, exportación JPG 1080×1920 y ancho de pantalla 390 px sin desbordamiento horizontal. Esto no certifica el recorrido con WordPress ni la subida a producción.

La revisión #167 incorpora también integración con WordPress/MariaDB reales (`integration/tests/content-studio.php`) y un recorrido Playwright dedicado (`tests/browser/content-studio.spec.js`) para login con cuenta limitada, guardado, subida multipart, descarga JPG y cambio de contraseña. La integración real de la revisión `f73428a` pasó en Actions `41522895994`; el recorrido de navegador está en comprobación en la candidata posterior.

Actualización: Actions `41524288477`, candidata `815bb0e`, pasó el recorrido completo del panel, la validación de código y el navegador general. Release y staging también verdes. Integración general pendiente de repetir tras adaptar la fixture de vales a su aviso de dirección reutilizada (confirmación explícita de datos sintéticos). No hay cambios en esa regla de producción.

Se repararon requisitos de CI que fallaban antes del panel: nombre antiguo de categoría, versión fijada a 3.14.71, entorno Ubuntu no soportado por Playwright, un helper del Taller confundido con un React Hook y fecha de entrega pasada en una fixture. No cambian reglas comerciales. Candidata: 3.14.112.

Verificación final del código: revisión `913928fa1290c3658a551b8d68a987834a6ecfeb`, Actions `41525690191`: validate (incluye lint, TypeScript y build), integration, browser y content-studio en verde. Contratos de release `41525690210` y staging `41525690145` verdes. El registro posterior de estos resultados solo modifica documentación.

Pendiente antes de entregar acceso real: revisión del cambio, despliegue con aprobación, crear cuenta y verificar login/subida/edición/publicación con un producto controlado en producción. No declarar lista para usar hasta esa verificación.

Costo nuevo requerido: 0 para este piloto de composiciones. Usa hosting y almacenamiento actuales, sujetos a su capacidad. Próximo escalado: generación de escenas por proveedor, control de consumo por tienda, almacenamiento y procesamiento asíncrono; sin iniciar Foundation multiempresa.
