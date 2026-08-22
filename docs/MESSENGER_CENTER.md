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

Ese contrato debe aprobarse y probarse en Core antes de habilitar los cuatro controles.
