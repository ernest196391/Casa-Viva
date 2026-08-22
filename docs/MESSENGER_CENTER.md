# Centro Operativo del Mensajero

## Objetivo

La experiencia del mensajero reutiliza el portal existente y lo organiza alrededor de una entrega activa, sin crear otra máquina de estados ni otro origen de verdad.

## Principios

- Una entrega activa ocupa la prioridad visual.
- Una sola acción logística principal domina la tarjeta.
- WhatsApp, llamada y navegación aparecen como herramientas rápidas cuando el pedido ya está aceptado y los datos del cliente corresponden al mensajero asignado.
- Las ofertas permanecen separadas de la entrega activa.
- Estados, transiciones, incidencias, QR, ubicación en vivo, ganancias y contabilidad siguen usando los servicios existentes.
- El polling existente continúa refrescando ofertas y cambios de estado.

## Fase 2D

La primera iteración añade una capa visual mobile-first al portal de mensajeros ya existente:

1. prioriza `Mi entrega` como `Entrega activa`;
2. destaca la acción logística principal;
3. agrupa accesos de WhatsApp, llamada y navegación;
4. conserva incidencias como acción secundaria;
5. mantiene ofertas, ganancias, disponibilidad, QR y ubicación en vivo intactos;
6. valida el flujo con Playwright sobre WordPress/WooCommerce reales.

## Alcance posterior

Después del piloto se podrá refinar el diseño, incorporar finalizados del día y mejorar resúmenes contables sin cambiar la autoridad de estados.

## P0.2 · Inicio/Hoy y entrega activa

La superficie operativa canónica es `/area-mensajeros/`. La ruta Next.js `/mensajero` permanece como sandbox visual Stitch y no comparte autenticación, estado ni pedidos.

P0.2 traslada a WordPress los tokens y patrones visuales aprobados y renderiza, con datos del pedido existente:

- resumen del trabajo activo y ofertas disponibles;
- productos y cantidades;
- cliente, teléfono, dirección, referencia y mapa cuando existen;
- total WooCommerce y mensajería CUP como conceptos separados;
- notas operativas;
- WhatsApp, llamada, navegación, QR, ubicación e incidencias existentes;
- acciones que conservan las URLs con nonce de `CVD_Delivery`.

### Degradaciones explícitas

- “Hoy” representa la carga activa actual, no una agenda persistida: todavía no existe un campo canónico de jornada o secuencia diaria.
- El vuelto no tiene un campo estructurado en el pedido. Solo se muestra dentro de la nota operativa cuando fue registrado allí; la interfaz no intenta inferirlo.
- Una dirección, referencia, teléfono o mapa ausentes se muestran como faltantes o se omite la acción correspondiente.
- No se calculan ETA ni orden óptimo de ruta.

## P0.3 · Contactos y preparación

`/area-mensajeros/` reutiliza los pedidos asignados y el ownership existente:

- Contactos lista clientes reales. Solo revela teléfono y habilita WhatsApp/Llamar después de aceptar la entrega. Muestra el teléfono de facturación y el de envío cuando WooCommerce los expone y son distintos; no inventa alternativos.
- Preparar agrupa por el único punto de recogida configurado de Casa Viva, consolida productos/cantidades sin mezclar variantes y muestra notas reales. Una incidencia aditiva conserva el pedido en el manifiesto mediante la etapa logística preservada y se presenta como alerta separada.
- `operation=ready` se presenta como preparado por tienda. `delivery=picked_up` junto a `_cvd_handed_over_by` se presenta como carga verificada por tienda. El mensajero no escribe ninguno de esos datos.
- El mensajero conserva únicamente `accepted → to_store` y, después de la transferencia física, `picked_up → handed_over` mediante las acciones canónicas existentes.
- El resumen para tienda es texto compartible por WhatsApp y no crea un manifiesto persistente.
- `CVD_Web_Push::send_delivery_update()` ya avisa al mensajero de cambios canónicos ajenos. P0.3 amplía mínimamente el feed protegido del mensajero con `operationStatus` y `operationUpdatedAt` de sus pedidos asignados para refrescar la vista cuando tienda marca `ready`; no añade datos del cliente ni capacidades de escritura.

### Gap de resultados de contacto

El dominio actual no contiene eventos para `Confirmó`, `No responde`, `Reprogramar` ni `Ubicación recibida`. P0.3 los presenta deshabilitados y no guarda metadatos libres ni crea una máquina de estados paralela.

El cambio mínimo propuesto para una iteración posterior es un evento inmutable de dominio `contact`, separado de los estados del pedido, con:

- `event_type`: `contact.confirmed`, `contact.no_answer`, `contact.reschedule_requested` o `contact.location_received`;
- pedido, actor/rol, timestamp, canal y nota opcional acotada;
- idempotencia propia y permisos del mensajero asignado o personal operativo;
- escritura mediante un servicio canónico y lectura desde el timeline, sin modificar `operation` ni `delivery` automáticamente.

El contrato ya está implementado mediante eventos inmutables del dominio `contact`. El mensajero asignado puede registrar los cuatro resultados después de aceptar el pedido. Cada escritura exige nonce REST e idempotencia, conserva actor/hora y no modifica estados `operation` ni `delivery`.

## P0.4 · Mi ruta y cierre de entrega

`Mi ruta` muestra únicamente pedidos asignados al mensajero autenticado que ya fueron aceptados y siguen en una etapa operativa (`accepted`, `to_store`, `picked_up` o `handed_over`). Direcciones, zonas, referencias, productos, totales, mensajería, notas, teléfonos y enlaces de mapa provienen del pedido canónico. Los datos de contacto no se muestran antes de la aceptación.

El mensajero puede mover cada parada con `Subir` y `Bajar`. Ese orden vive solo en `sessionStorage`, por usuario y sesión del navegador; no se escribe en WooCommerce, no modifica estados y no representa una ruta canónica. La interfaz lo identifica expresamente como orden manual previo a NEXO. No se añaden geocodificación, ETA ni `route-suggest`.

`Entrega activa` conserva una acción principal según la transición permitida por `CVD_Delivery`, con incidencia como acción secundaria. Producto y mensajería continúan separados y la transición `handed_over → delivered` usa la URL canónica con nonce.

Después de `delivered`, el pedido deja de competir con las entregas activas y aparece en `Cierre de entrega`. La vista lee `_cvd_cash_status`, timestamps de entrega/retorno/verificación y, cuando existen, `_cvd_collection_method`, `_cvd_collection_amount_usd` y `_cvd_collection_amount_cup`. No permite al mensajero declarar el arqueo ni cambiar la conciliación.

### Datos operativos estructurados

- Core conserva teléfono alternativo, fecha solicitada, ventana mañana/tarde y vuelto por importe/moneda como datos del mismo pedido WooCommerce. El mensajero los lee en Contactos, Preparación, Ruta y Entrega; no constituyen estados.
- Pedidos históricos sin esos metadatos mantienen una degradación explícita y no se reinterpretan desde notas libres.
- La fecha/ventana no constituye ETA y nunca se calcula una hora predictiva.
- La acción canónica `handed_over → delivered` exige al mensajero confirmar medio e importes realmente recibidos en USD/CUP. La misma transacción guarda actor/hora y emite el evento de entrega; no infiere cantidades del total. El retorno y la verificación del dinero continúan perteneciendo a Casa Viva mediante `cash_returned → closed`.
- Un pedido sin teléfono o `_cvd_map_url` conserva la parada, muestra el faltante y omite la acción correspondiente; no se geocodifica la dirección.
