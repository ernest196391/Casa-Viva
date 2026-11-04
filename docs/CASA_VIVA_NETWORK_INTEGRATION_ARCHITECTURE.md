# Casa Viva Network — Integration Architecture V1

## Estado
Paso 7 — COMPLETADO V1.

## Objetivo
Definir cómo Casa Viva se integra con plataformas externas sin convertirlas en el dominio del negocio ni crear dependencias irreversibles.

## Principio
> Integrar proveedores detrás de adaptadores. El dominio habla de ubicaciones, mensajes, pagos, contenido y pedidos; no de marcas concretas de proveedor.

---

## 1. Patrón general

Para cada integración:

`DOMAIN → APPLICATION PORT → ADAPTER → PROVIDER`

Debe existir:
- interfaz interna estable;
- adaptador por proveedor;
- timeouts;
- retries seguros;
- idempotencia cuando exista escritura;
- observabilidad;
- fallback/degradación;
- configuración por entorno.

---

## 2. WooCommerce / WordPress

### Estado
CURRENT.

### Autoridad actual
WooCommerce sigue siendo fuente oficial de:
- pedidos comerciales actuales;
- stock oficial actual.

WordPress sigue siendo la superficie de aplicación operativa actual.

### Evolución
Crear/fortalecer adaptadores y servicios en lugar de permitir que futuros verticales dependan directamente de detalles de WooCommerce.

No migrar por anticipación.

---

## 3. Mapas y navegación

### Dominio interno
- GeoPoint;
- Address;
- RouteRequest;
- RouteResult;
- OfflineRegion;
- LocationSample.

### Proveedores
Deben poder cambiar sin alterar Order/Logistics.

### Requisito Cuba
Offline-first:
- regiones descargables;
- GPS local;
- coordenadas de pickup/dropoff guardadas;
- ruta/último estado disponible con mala conexión;
- deep link a apps instaladas cuando convenga.

No depender de una única aplicación externa como única vía operativa.

---

## 4. Sincronización móvil offline

Patrón recomendado:

`LOCAL DB → OUTBOX → SYNC PUSH → SERVER APPLY → PULL/RECONCILE`

Cada mutación crítica sincronizable debe tener:
- ID estable;
- idempotency key;
- estado pending/synced/failed/conflict;
- versión o token equivalente;
- política de conflicto documentada.

### Work classes
- immediate online action;
- retryable background sync;
- scheduled maintenance;
- online-required transaction.

La UI móvil debe leer estado local para operaciones que deban sobrevivir desconexión.

---

## 5. WhatsApp

Casos:
- deep links;
- compartir contenido preparado;
- contacto cliente/gestora/negocio;
- Business Platform cuando exista caso autorizado y sostenible.

Reglas:
- no asumir acceso libre a grupos privados;
- no depender de scraping de WhatsApp;
- respetar opt-in y límites de mensajería;
- no almacenar más conversación de la necesaria;
- distinguir enlace manual de automatización API.

---

## 6. Redes y clasificados

Objetivo:
- preparar assets/copy;
- compartir hacia aplicaciones;
- publicar vía API cuando exista integración permitida.

No construir automatización crítica sobre scraping frágil o violación de términos.

`PublicationIntent` debe conservar:
- destino;
- contenido;
- estado;
- actor;
- método manual/API;
- resultado.

---

## 7. Pagos

El dominio interno no debe modelarse según un único proveedor.

Conceptos:
- PaymentIntent/Charge reference;
- method;
- currency;
- amount;
- status;
- external_reference;
- reconciliation.

Ledger sigue siendo la semántica económica interna.

Proveedores futuros pueden incluir distintos canales, pero su disponibilidad/legalidad debe verificarse antes de producción.

---

## 8. Notificaciones

Canales potenciales:
- in-app;
- push;
- WhatsApp autorizado;
- email;
- SMS donde sea viable.

Notification Core decide intención y preferencia; adapters entregan.

Debe existir fallback sin duplicar notificaciones indiscriminadamente.

---

## 9. Calendario y tareas

Scheduling Core conserva reservas del dominio.

Integraciones externas de calendario pueden sincronizar vistas/compromisos, pero no deben convertirse automáticamente en fuente de verdad de pedidos o asignaciones.

Scheduled Tasks del Agent Platform son otra capacidad distinta de Booking/Scheduling.

---

## 10. IA/model providers

Agent Platform debe abstraer:
- model invocation;
- tools;
- memory references;
- structured output;
- evaluation;
- fallback.

No guardar lógica económica crítica únicamente dentro de prompts.

Reglas, límites y autorizaciones deben residir en código/configuración determinista.

---

## 11. Prevente

Prevente debe integrarse mediante una frontera explícita de consentimiento y datos.

Separar:
- preferencias generales de bienestar;
- métricas personales;
- información clínica sensible;
- recomendaciones médicas.

Casa Viva general no debe heredar acceso sanitario por defecto.

---

## 12. Triciclub

Debe reutilizar mediante servicios/adapters:
- Identity;
- Geo;
- Scheduling;
- Reputation;
- Ledger;
- Notifications;
- Agent Platform.

Triciclub mantiene reglas de movilidad propias.

---

## 13. API externa futura

Una API pública/partner deberá construirse sobre Application Services, con:
- scopes;
- rate limits;
- idempotencia;
- webhooks/event delivery;
- audit logs;
- versionado;
- claves rotables;
- sandbox.

No exponer directamente estructuras internas de WordPress/WooCommerce como contrato permanente.

---

## 14. Integración segura por fases

1. Deep links/manual handoff.
2. Read-only API.
3. Write with explicit confirmation.
4. Scheduled/recurring write.
5. Rule-based automation.

Solo subir de nivel cuando exista observabilidad y rollback suficientes.

## 15. Regla final
> Si una integración externa falla, Casa Viva debe degradarse de forma entendible; no perder la fuente de verdad ni dejar al usuario sin saber qué ocurrió.