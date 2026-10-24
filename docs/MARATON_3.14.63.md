# Core 3.14.63 · Maratón de ideas (24-oct-2026)

Rama `feat/maraton-confianza`, encima de 3.14.62 (recogida → mensajería, #4223). Hecha como «3.14.62» y renumerada a
3.14.63 al subir porque ese número lo tomó la recogida. Subida el 24-oct con el SÍ de Ernesto («súbelo después de 3.14.61»). Pedido en el grupo General
(Dany, Katia, Oliuska, Nadielys, AA); decidido por Ernesto. Lista original:
`ernesto-rondon-nexo/docs/nexo-business/maraton-ideas-2026-10-24.md` («Para hacer»).

## Qué cambia

| # | Qué | Dónde |
|---|---|---|
| 1 | **Vale con menos datos.** Obligatorio solo el **teléfono** (también en la recogida) y, con mensajería, la **zona** (provincia, municipio, reparto). El **nombre**, la **calle** y **cómo paga** pasan a opcionales. Sin calle, el pedido guarda «Dirección por confirmar: llamar al cliente» (sale igual en el vale, la tarjeta del mensajero, la caja y el bot). Sin forma de pago, el vale dice «Cómo paga: por confirmar con el cliente». La entrada de vales por texto (NEXO) pide lo mismo. | `class-cvd-cuban-checkout.php`, `assets/checkout.js`, `class-cvd-whatsapp-receipt-template.php`, `class-cvd-voucher-intake.php` |
| 2 | **«📦 Bajo pedido · llega en 24 h»** (antes «24–48 h» o nada): recuadro en la ficha, texto de disponibilidad, tarjetas y filtro de la tienda, separador de «Todo», «Mi tienda» de la gestora, «Fotos para publicar» (antes decía «En existencia») y su texto copiable, y el carrito/pago («Disponibilidad: …»). | `class-cvd-datos-a-mano.php` (nuevo), `class-cvd-departments.php`, `class-cvd-gestora-app.php`, `class-cvd-gestora-fotos.php`, `class-cvd-market-bridge.php` |
| 3 | **«Quedan X» para todos** (antes solo gestoras; el cliente veía «Disponible / Últimas unidades») en la ficha y en las tarjetas. **«Agotado · vuelve el jueves 29 de octubre»** solo si hay fecha: WordPress → producto → Inventario → «Vuelve el día». Sin fecha no se inventa; pasada la fecha deja de salir. | `class-cvd-datos-a-mano.php`, `class-cvd-gestora-stock.php` |
| 4 | **Orden A–Z** en la tienda: fila «Orden: Por departamento · A–Z» debajo del filtro En tienda/Bajo pedido (`?orderby=title`; se conserva con ?ref=, el filtro y al paginar). | `class-cvd-departments.php`, `assets/departments.css` |
| 5 | **Tabla pública de mensajería** en `/tarifas-mensajeria/`, debajo del buscador que ya había: 17 municipios, 348 repartos (las tarifas oficiales activas de `cvd_shipping_rates`), con buscador. Enlace «Ver precios de mensajería por zona» junto al reparto en el vale. | `class-cvd-datos-a-mano.php`, `class-cvd-cuban-checkout.php` |

No cambia precios, existencias, tarifas, comisiones ni pedidos ya hechos.

## 6 · «Ve a la tienda y di el nombre de tu gestora» (la caja ya lo hace; no se tocó)

La caja NEXO tiene, al cobrar, el desplegable **«Gestora»** (`apps/business-pos/src/attribution.ts`). La venta queda a
nombre de esa gestora (comisión) y el bot le manda «Tu cliente compró en la tienda» (`startCajaGestoraLoop`, cada 2 min,
VivaBot; necesita `supabase/028_caja_ventas_gestora.sql` pegado e interruptor `avisos_caja_gestora` = si).

Para la dependienta, en 3 pasos:
1. **Vender:** añade los productos como siempre.
2. **«Gestora»:** el cliente dice el nombre de su gestora → elígela en la lista (si no la dice: «Venta directa»).
   Nombre y teléfono del cliente: opcionales («Cliente (opcional)»).
3. **Cobrar.** Listo: la venta y la comisión salen a nombre de la gestora, y a ella le llega el aviso por WhatsApp.

## Pruebas

- `php -l` (PHP de Hostinger) limpio en los 11 archivos PHP tocados.
- `wp eval-file` de solo lectura con la clase nueva y datos reales: batidora MK- → «📦 Bajo pedido · llega en 24 h»;
  carrito plegable → «Queda 1»; agotado con fecha simulada en memoria → «Agotado · vuelve el jueves 29 de octubre»;
  fecha pasada → nada; tabla: 17 municipios, 348 repartos. WooCommerce acepta `orderby=title` (A–Z).
- `artifacts/tests/test-maraton-3.14.63.mjs` (nuevo) y las pruebas de contrato existentes: pasan. Fallan igual que en
  `main` (no por este cambio): history-truncation-1a, gestora-messenger-dispatch, order-success-access-7a,
  store-pickup-5b, vale-fotos-tienda.

## Choques con otras ramas (md5 contra `origin/main`, 24-oct)

Solo `casa-viva-dropship-core.php` (las 2 líneas de versión, como siempre) con 3.14.59 (`core/bot-recogida-a-mensajeria`,
`feat/carrera-palabras`) y 3.14.61 (`feat/palla`). `class-cvd-gestora-app.php` también lo cambia `feat/ficha-contesta`
(3.14.57) en otras líneas (77 y 159; aquí 261 y 503): se fusiona solo. El resto de archivos, igual que en `main` en esas ramas.

## Al subirlo y después

- Subidos solo los archivos cambiados (copia de seguridad antes) y purgada la caché de LiteSpeed.
- Avisar en el General (con la guía paso a paso) y probar primero con Dany.
- **Decidido por Ernesto (24-oct): la calle sigue opcional** con mensajería (y la forma de pago también). Volver atrás es una
  línea (`billing_address_1` → `required = $is_delivery` y volver a ponerla en `$required` de `validate()`).
- El panel de la dueña (business-dashboard) saluda «Hola  .» cuando el pedido no tiene nombre (línea ~1813, repo NEXO):
  arreglo pequeño aparte. La caja ya pone «Cliente».
- «Vuelve el día» solo se puede poner hoy desde WordPress; en el panel de la dueña todavía no.
