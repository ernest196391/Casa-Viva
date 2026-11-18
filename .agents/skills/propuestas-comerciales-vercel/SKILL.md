---
name: propuestas-comerciales-vercel
description: Crear, adaptar, verificar y publicar propuestas comerciales premium y configuradores de productos reales en Vercel para Casa Viva u otros comercios; fotos originales descargadas, precios verificables, packs, stock, cotizaciones, WhatsApp y pago fiscal sin cobro automático.
---

# Propuestas comerciales verificables → Vercel

## Cuándo usar

Cuando el usuario pide una oferta personalizada, landing, catálogo privado, propuesta tipo Hormozi, kit corporativo, comparador, configurador o micrositio comercial para un cliente y necesita compartir un enlace. **No** usar para cambiar el ecommerce canónico ni para crear pedidos directamente en WooCommerce.

## Contrato de entrada mínimo

Recoger de los chats, solicitudes de cliente y catálogo vigente, sin inventar: cliente/segmento, necesidad y beneficio, productos y SKU/ID, fotos originales, precio de venta actual y moneda, presentación/pack y unidad, stock confirmado con fecha y fuente, forma de cobro, destino de entrega, responsable y URL/destino de WhatsApp. Marcar los campos ausentes como `pending`, no simular confirmación. No exponer costes de proveedores, datos privados del cliente o credenciales.

**Fuente canónica:** WooCommerce para catálogo, imágenes publicadas, precios, pedidos y stock. CRM y proveedores son evidencia complementaria, no sustituyen a WooCommerce. Un precio importado de lista antigua no es precio confirmado hoy. Nunca utilizar una tasa de cambio ni comisión congelada en el HTML. Diferenciar estimación, reserva, pedido y pago.

## Secuencia obligatoria

1. **Auditar la solicitud:** releer audios y mensajes exactos, desambiguar números/contactos, confirmar las cantidades y presentaciones. Extraer hechos y pendientes con fuente/fecha; un audio transcrito puede contener errores.
2. **Verificar productos:** consultar cada ficha Woo y proveedor. Registrar precio público autorizado, unidad vs paquete, cantidad mínima, opciones/variantes, disponibilidad real y fecha. Separar productos confirmados de alternativas no confirmadas. Nunca ofrecer como seleccionable un artículo que el proveedor declaró agotado.
3. **Capturar fotografías auténticas:** obtener URL HTTPS del archivo original del producto exacto y verificar correspondencia con SKU y variante. Si es privada, obtener acceso/autorización legítima mediante herramienta conectada; **no** descargarla usando enlaces privados ni incluir tokens. Guardar una copia local en `public/assets/products/`, usando `scripts/prepare-assets.mjs` y el manifiesto. Evitar hotlinks, consultas CORS a tienda en runtime y fotografías de stock o IA si el usuario exige original. Si falta imagen, mostrar marcador discreto y enlace a ficha; no usar foto parecida. No alterar características físicas en retoque; opcionalmente optimizar compresión preservando el artículo.
4. **Definir la oferta:** un beneficio claro y creíble, selección concreta, precio visible, valor añadido real, objeciones, riesgo reducido (confirmación previa al cobro), condiciones, CTA única. Inspirarse en siete funciones de oferta (audiencia, problema, resultado, selección, valor, garantía/condiciones, acción) **sin imponer siete bloques ni mucho texto**. Helvetica o equivalente, beige/verde Casa Viva, sin precios falsos ni urgencia inventada.
5. **Configurar:** interfaz mobile-first, una columna en móvil estrecho, cards con foto, precio **por paquete**, selector en unidades reales, `packs=ceil(unidades/packSize)`, total en centavos enteros (no coma flotante), sin stock garantizado sin fuente. Al recalcular mostrar sobrantes si compra paquetes. Envío gratis solo cuando esté autorizado y económicamente evaluado; cuenta fiscal solo si la entidad receptora existe y el mecanismo es válido. CTA genera solicitud con detalle de productos, packs, total estimado y referencia; **no** afirma que pagó, no crea pedido ni reserva, no expone datos bancarios.
6. **Comprobar en local:** `node scripts/validate-offer.mjs oferta.json [directorio-publicado]`; ejecutar pruebas de cálculo, cantidades 0/1/10/100/101, vacíos, caracteres, sin imagen, artículos agotados, cambio de precio, enlaces, accesibilidad y tamaños 320/360/393 px. Revisión visual con captura móvil cuando haya navegador capaz de hacerlo. No declarar prueba visual realizada sin evidencia.
7. **Publicar solo con permiso explícito:** crear micrositio independiente en Vercel Hobby existente mediante archivos estáticos, sin tocar dominio principal ni producción de WooCommerce. Desplegar **los binarios de las imágenes junto al HTML**, no dependencias privadas de origen. Verificar despliegue `READY`, alias de producción, HTTP 200 en navegador público (si la herramienta lo permite), carga de imágenes, JS y botón WhatsApp. Si solo Vercel indica READY, comunicar que la prueba del navegador queda pendiente. Registrar deployment ID, URL, revisión, fecha, responsable y forma de rollback. Nunca registrar un dominio no autorizado, habilitar un plan de pago o desplegar secretos.
8. **Entregar:** URL real verificada, resumen de selección/precios y pendientes; enlace del sitio y manifiesto versionado para actualizar. No enviar a cliente ni gestora sin autorización explícita; la publicación y el envío son acciones distintas.

## Manifiesto y medios

Usar `references/oferta.example.json` como esquema ilustrativo. `scripts/prepare-assets.mjs oferta.json directorio` verifica HTTPS y host permitido (`casaviva.company` por defecto, otros dominios solo con parámetro explícito y evidencia de autorización); valida el formato binario JPEG/PNG/WebP, limita tamaño, desactiva reutilización de archivos de distinta procedencia y genera `assets-manifest.json` con hashes. Descarga solo archivos públicos y originales cuyo origen esté confirmado. No aceptará SVG ni recursos sin `imageUrl`. Rechaza enlaces con parámetros de autorización.

`validate-offer.mjs` falla si faltan datos o fotos requeridas, si hay precio no entero en centavos, packs inválidos, artículos no disponibles en la oferta o si faltan binarios locales; para ofertas solo borrador permitir `--draft` sin exigir fotos descargadas pero no publicar hasta cerrar pendientes. **Una URL externa de imagen nunca cuenta como activo final.**

## Ganchos y precauciones

- Catálogo y stock Woo tienen autoridad; no escribir ni duplicar pedidos en un servicio paralelo.
- No hardcodear precios, cambios, márgenes o formas de pago en el código base de la tienda; el manifiesto es un **snapshot fechado** de la cotización.
- Material para un cliente: datos mínimos, enlace noindex, caducidad/retirada acordada y sin PII en HTML, URL o eventos de analítica. `noindex` no equivale a enlace privado autenticado; protección real si se incluyen datos confidenciales.
- Implementación portable: scripts Node estándar, sin librerías pagadas. Al actualizar fotos/precios, volver a validar, desplegar versión y comprobar rollback.
- Dejar el micrositio independiente de WordPress, Vercel ligado a la cuenta autorizada, y no interferir en el resto de Casa Viva.
- Respeta `AGENTS.md`, `docs/CASA_VIVA_PLATFORM_MASTER_BLUEPRINT.md`, `docs/CASA_VIVA_PLATFORM_CHECKPOINT.md` y los límites de la tarea.

## Señal de aceptación

No se declara **lista para compartir** hasta tener fuente y fecha de cada precio, foto original del producto correcto físicamente dentro del despliegue, empaquetado coherente, flujo WhatsApp legible, condiciones de pago/entrega y URL comprobada. Si falla alguno, entregar **borrador** y los bloqueos exactos; nunca maquillar el estado.