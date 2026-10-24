# Pa'llá — app del mensajero (PWA) · Core 3.14.61 · rama `feat/palla`

**Estado (24-oct): construida y probada en vista previa. NO subida. Nace apagada (`cvd_palla` = `no`).**
Ernesto decide cuándo se sube, después de 3.14.59 y 3.14.60. La rama sale de `origin/main` = Core 3.14.58.1 (d083479).

## Hecho el 24-oct
- `includes/class-cvd-palla.php` — interruptor, token del enlace (30 días, época para anular), `/palla/` (página),
  `/palla/manifest.webmanifest`, `/palla/sw.js`, `/palla/sesion`, `GET /bot/palla/enlace?phone=`. Las rutas se leen de la
  dirección (`parse_request`), sin reglas de reescritura: no hace falta «guardar enlaces permanentes» al subir.
- `includes/class-cvd-palla-api.php` — `GET palla/hoy`, `POST palla/cojo`, `POST palla/carrera`, idempotencia por `cid`
  (últimos 50 en `_cvd_palla_cids`). Al leer no escribe nada (la mensajería de las ofertas sale de lo guardado).
- `class-cvd-bot-bridge.php` — `claim()` llama a `claim_by_messenger( $order, $messenger, 'whatsapp_group' )`; «La cojo» usa
  `'palla'` (misma clave de idempotencia: un «Yo» y un «La cojo» del mismo mensajero no se pisan). Solo cambian la nota
  («desde Pa'llá») y el origen anotado.
- `assets/palla/app.js|app.css|icon.svg|icon-192/512/maskable-512.png|fonts/Archivo-400/600/700/800.woff2`.
- Vista previa sin servidor con datos de ejemplo: `artifacts/palla-preview/index.html` (`?escena=1`, `?etapa=route`,
  `?offline=1`, `?cola=1`) y `marco.html` para capturas. Capturas en `ernesto-rondon-nexo/docs/nexo-business/apps/palla/capturas/`.
- Pruebas: `php -l` limpio (PHP 8.2 de Hostinger); `wp eval-file` EN SECO con las clases solo en memoria (token válido,
  tocado y caducado; ofertas reales; dinero de Daiquel = usuario 59; rutas); flujo completo en el navegador a 375×812
  (La cojo → Recogí → Entregado con CUP → cola sin conexión → reenvío al volver la señal con el mismo `cid`).

## Aviso del bot (24-oct, hecho)
- Core: «La cojo» guarda `_cvd_palla_claimed_at`; `GET /bot/dispatch/palla` da las cogidas en la app aún sin avisar (con el vale) y
  `POST /bot/dispatch/{id}/palla-announced` las marca. Si se suelta y la coge otro por el grupo, la oferta nueva es más reciente y ya no sale.
- VivaBot (rama `feat/palla-avisos`, worktree `vivabot-palla`): `processPalla()` en `src/dispatch.js` → «✅ #N es de X (la cogió en Pa'llá)» en el grupo si se
  había publicado, el vale por privado y la carrera queda esperando la hora (como tras un «Yo»). Con Core sin 3.14.61 no hace nada.
  Orden de subida: Core 3.14.61 primero, luego el bot.

## Para encenderla con Daiquel (modo prueba), después de subir 3.14.61
1. `wp option update cvd_palla_testers '[59]' --format=json` y `wp option update cvd_palla prueba`.
2. Su enlace: `wp eval 'echo CVD_Palla::link_for(59);'` → mandárselo por privado (no por el grupo).
3. Anular sus enlaces si pierde el teléfono: `wp eval 'CVD_Palla::revoke(59);'`. Apagar todo: `wp option update cvd_palla no`.

## Choques con las otras ramas (comprobado con `git merge-tree`, sin fusionar)
- `feat/carrera-palabras` (3.14.59) y `core/bot-recogida-a-mensajeria` (3.14.59): solo las 2 líneas de versión del archivo
  principal. `feat/ficha-contesta` (su punta dice 3.14.57): versión + las líneas `require`/`register` vecinas (quedarse con
  las dos). Ninguna toca `class-cvd-bot-bridge.php` ni los archivos nuevos de Pa'llá.

## Lo que se leyó el 23-oct
- Leídos: ficha `docs/productos/app-mensajeria-cuba.md`, diseño aprobado (5 pantallas del artifact HGgfiy4NwTDgPr1Dbg5XoU),
  `class-cvd-tarjeta-carrera.php` (3.14.54), `class-cvd-bot-bridge.php` (claim/release/delivered), `class-cvd-delivery.php`
  (ofertas, `messenger_confirms_pickup`, `/messenger/feed`), `class-cvd-pwa.php` (la PWA de Casa Viva, SW en `/casa-viva-sw.js`)
  y el reparto de VivaBot (`src/dispatch.js`).
- Comprobado: ni 3.14.59 ni 3.14.60 tocan `class-cvd-bot-bridge.php`; 3.14.59 añade a `CVD_Tarjeta_Carrera::submit()` un 5.º
  argumento opcional (`$via`), así que llamar a `submit()` con 4 argumentos vale con y sin 3.14.59.

## Plan del 23-oct (ya construido)
Archivos nuevos (no chocan con otras ramas):
- `includes/class-cvd-palla.php` — interruptor, entrada, página `/palla/`, manifest, service worker.
- `includes/class-cvd-palla-api.php` — la API (clase aparte para moverla a NEXO en la fase 2).
- `assets/palla/app.js`, `app.css`, `icon.svg` + PNG 192/512/maskable (letra **P** amarilla `#FFC21A` sobre tinta `#121212`, sin logo NEXO),
  `fonts/Archivo-400/600/700/800.woff2` (copiadas del manual NEXO, empaquetadas, nada de Google Fonts).
- Cambio mínimo en un archivo existente: `class-cvd-bot-bridge.php` — sacar el cuerpo de `claim()` a
  `public static function claim_by_messenger( WC_Order, WP_User, string $source )` y que `claim()` la llame. Así «La cojo» usa
  EXACTAMENTE lo mismo que el «Yo» del grupo (primero que llega; si no, `taken` → «Ya la cogió otro»).
- Registro en `casa-viva-dropship-core.php` (require + `register()`), versión **3.14.61**.

Interruptor: opción `cvd_palla` = `no` (por defecto) | `prueba` | `si`. En `prueba` solo entran los ids de `cvd_palla_testers`
(poner a Daiquel: buscar su user id por el teléfono 5358713773 con `CVD_Bot_Bridge::messenger_by_phone`). Con `no`, `/palla/` dice
«Todavía no está abierta» y la API responde 403. Siempre además: `CVD_Registration::is_approved_messenger()`.

Entrada sin contraseña:
- Enlace personal `https://casaviva.company/palla/#k=TOKEN` (la clave va tras `#`: no llega al servidor ni a los registros, y la
  vista previa de WhatsApp no la ve). TOKEN = HMAC con `wp_salt('auth').'|cvd-palla'` de `user_id|caducidad|época`, **30 días**;
  la época (`_cvd_palla_epoch` en el usuario) permite anular todos sus enlaces de golpe. La app lo guarda y lo manda en la cabecera
  `X-Palla-Token`.
- Con usuario de WordPress ya dentro: `GET /palla/sesion` (ruta PHP, no REST, exige cabecera `X-Palla: 1`) devuelve un token igual.
- Para dar el enlace: `CVD_Palla::link_for( $user_id )` (wp eval) y endpoint del bot `GET /bot/palla/enlace?phone=` (clave del bot;
  solo da el enlace, no envía nada). Sin contraseñas en ningún sitio.

API (`casa-viva/v1/palla/…`, `no-store`):
- `GET hoy` — todo en una llamada (mala conexión): yo, ofertas abiertas (las mismas que ve el grupo: `offered`, sin mensajero,
  `blocks_dispatch()` vacío, sin datos del cliente), mis carreras en curso con lo de la tarjeta (`stage()`, `money_plan()`,
  `customer_phone()`, mapa, `CVD_Order_Lines::texts()`), dinero del día (`bring_after()` por pedido entregado hoy y sin «Dinero
  recibido», mensajería ganada, vuelto incluido) e historial del día.
- `POST cojo {order, cid}` → `CVD_Bot_Bridge::claim_by_messenger`.
- `POST carrera {order, action: recogi|entregado|problema|suelto, usd, cup, eur, como, motivo, texto, cid}` →
  `CVD_Tarjeta_Carrera::submit()` (mismos códigos: picked, delivered, needs_amount, problem, released, too_late, test…; el `cid`
  va como `cvd_n` del «Problema»).
- Idempotencia de la cola: cada acción lleva `cid`; el servidor guarda los últimos 50 `cid` del usuario con su resultado y un
  reenvío devuelve lo mismo.

Sin conexión: service worker en `/palla/sw.js` (alcance `/palla/`, gana al de `/casa-viva-sw.js` en esa carpeta) guarda la app,
las fuentes y la última respuesta de `hoy`; las acciones hechas sin red van a una cola en IndexedDB y se mandan solas al volver
(evento `online` + al abrir), y el resultado de cada una se enseña («Entregado #N quedó» o «#N ya la cogió otro»). «La cojo» sin
red NO se pone en cola (otro puede cogerla antes): se enseña la pantalla 5 con las palabras de WhatsApp de 3.14.59.

Pantallas (del diseño): 1 Carreras disponibles con «La cojo» (un solo botón amarillo, el primero) · 2 Mi carrera (Recogí /
Entregado / Problema / No puedo, Llamar, Mapa, cobrar y traer con vuelto) · 3 Confirmar entregado (cómo pagó) · 4 Mi dinero de
hoy · 5 Sin conexión (las 4 palabras). Tokens NEXO, Archivo, 48 px táctil, español de Cuba, nada de «IA».

Pruebas previstas: `php -l`; `wp eval` en SECO en el servidor (sin cambiar pedidos; el de PRUEBA #5425 hace que `submit()`
devuelva `test` sin tocar nada); vista previa 375×812 en el navegador integrado; capturas en
`ernesto-rondon-nexo/docs/nexo-business/apps/palla/capturas/`.

Hueco del bot (23-oct): resuelto el 24-oct, ver «Aviso del bot» arriba.

## Fase 2 — varios comercios (NEXO)
- `business_id` en cada carrera y en el token del mensajero (hoy todo es Casa Viva implícito).
- La API pasa de Core (WordPress) a NEXO (Supabase/funciones); Core solo publica sus carreras y recibe los cambios.
- Dirección y horario de recogida por comercio (hoy fijos: Conill A esq. 45 #864, `cvd_tarjeta_recogida_horas`).
- Monedas, tasas y «traes a la tienda» por comercio; mensajeros que trabajan para varios comercios.
- Nombre y marca de Pa'llá fuera de Casa Viva (dominio propio), permiso de contacto al registrarse.
