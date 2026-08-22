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
