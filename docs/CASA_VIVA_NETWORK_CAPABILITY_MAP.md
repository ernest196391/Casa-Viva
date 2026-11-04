# CASA VIVA NETWORK — CAPABILITY MAP V1

## Estado
Paso 2 del diseño estratégico — **COMPLETADO V1**.

## Propósito
Separar claramente qué capacidades deben existir una sola vez, cuáles se reutilizan, cuáles son específicas de un vertical y cuáles son integraciones externas o futuras.

Clasificación:
- `CORE`: una sola capacidad compartida por todo Casa Viva Network.
- `SHARED`: servicio común reutilizado por varios verticales.
- `VERTICAL`: comportamiento especializado.
- `EDGE`: integración externa.
- `FUTURE`: prevista, no autorizada todavía.

---

# 1. Identity & Access — CORE

## CV Identity
- identidad única;
- autenticación;
- perfiles;
- roles múltiples;
- sesiones;
- dispositivos;
- preferencias;
- consentimiento.

Regla: `una persona != un solo rol para siempre`.

## Roles & Permissions
- cliente;
- gestora;
- mensajero;
- dependienta;
- administración;
- negocio;
- profesional;
- operador.

Debe soportar RBAC y evolucionar hacia permisos contextuales.

---

# 2. Organizations & Teams — CORE/SHARED

Representa:
- negocios;
- tiendas;
- restaurantes;
- empresas logísticas;
- equipos de gestoras;
- equipos profesionales;
- sucursales;
- almacenes;
- puntos de recogida.

Roles de equipo futuros: owner, admin, dispatcher, dependienta, gestora, mensajero, profesional.

---

# 3. Catalog & Offer

## Universal Catalog — CORE
Debe poder representar conceptualmente productos, alimentos, servicios, movilidad, paquetes y combos.

## Product Catalog — VERTICAL/COMMERCE
SKU, stock, variantes, atributos, precio, imágenes, descripción.

## Food Catalog — VERTICAL/FOOD
Platos, ingredientes, preparación, horario, disponibilidad, opciones.

## Service Catalog — VERTICAL/SERVICES
Oficio, especialidad, alcance, duración, materiales, disponibilidad, cotización.

## Mobility Offer — VERTICAL/TRICICLUB
Viaje, vehículo, capacidad, pasajeros, carga, zonas y disponibilidad.

## Catalog Syndication — SHARED
Una oferta puede aparecer en tienda del negocio, tienda de gestora, marketplace, campaña, recomendación o enlace directo.

---

# 4. Marketplace & Discovery

## Marketplace Core — CORE
Búsqueda, filtros, ranking, disponibilidad, proximidad, reputación, precio y contexto.

## Need Resolver — FUTURE
Entrada natural como `se rompió la tubería` y salida hacia categoría/proveedor/urgencia.

## Recommendation Engine — SHARED
Reutilizado por cliente, gestora, negocio, mensajero y profesional.

## Landed Cost Optimizer — SHARED
Optimiza producto + mensajería + distancia + tiempo + disponibilidad + reputación.

## Basket Optimizer — FUTURE
Agrupa compras entre negocios para minimizar coste total y fragmentación.

---

# 5. Orders & Events — CORE

## Order Core
Durante la transición WooCommerce continúa siendo fuente oficial para pedidos comerciales actuales.

Futuro: origen explícito, comprador, proveedor, atribución, logística, estados e historial.

## Order Source — PREPARE
Posibles orígenes: casa_viva, merchant, manager, customer, food, triciclub, services, integration.

## Order State Machine
Una semántica canónica por flujo. No crear estados paralelos por interfaz.

## Event History
Actor, fecha, before/after, contexto y evidencia.

## Incidents & Exceptions
Dimensión separada del estado normal.

---

# 6. Scheduling — CORE/SHARED

## Scheduling Core
Fecha/hora, ventanas, recurrencias, reservas, disponibilidad y conflictos.

Especializaciones:
- Courier Agenda;
- Professional Agenda;
- Business Scheduling;
- Customer Recurrence.

---

# 7. Maps, Geo & Routing

## Geo Core — CORE
Coordenadas, zonas, distancias, puntos, precisión y timestamps.

## Offline Maps — CORE PRIORITY
Descarga de regiones, GPS sin Internet, puntos guardados y degradación offline.

## Routing — SHARED
Origen, destino, ruta, ETA y paradas.

## Route Optimization — PRO/SHARED
Multiparada, desvíos, tiempos y capacidad.

## Live Location — SHARED
Servicio activo, última posición, frescura, privacidad y stop sharing.

## Backhaul Matching — FUTURE
Trabajos compatibles con rutas de retorno.

---

# 8. Logistics

## Logistics Core — CORE
Necesidad logística, recogida, entrega, asignación, custodia, evidencia, cobro asociado y cierre.

## Courier Availability — SHARED
Disponible, ocupado, pausa, offline.

## Assignment Engine — SHARED
Manual → recomendado → confirmado → automático autorizado.

## Delivery Proof — SHARED
PIN, foto, firma opcional, hora y actor.

## Fleet Management — BUSINESS/FUTURE
Vehículos, mensajeros, mantenimiento, rendimiento y dispatcher.

---

# 9. CRM & Relationships

## Relationship Core — CORE
Relaciones cliente↔gestora, cliente↔negocio, negocio↔mensajero, gestora↔negocio, cliente↔profesional y negocio↔profesional.

## CRM Core — SHARED
Contactos, historial, notas, intereses, estado, tareas, seguimientos y recurrencia.

## Sales Pipeline — VERTICAL
Nuevo → interesado → oferta → negociación → pedido → entregado → recompra.

## Household Memory — VERTICAL/CLIENT
Hogar, preferencias, productos habituales, profesionales, mantenimientos y presupuesto.

---

# 10. Reputation & Trust

## Reputation Core — CORE
Puntuaciones explicables, dimensiones, historial, contexto y evidencia.

## Verification — SHARED
Identidad, negocio, vehículo, profesional y certificaciones.

## Reviews — SHARED
Ligadas a intercambios reales cuando sea posible.

Regla: **la reputación no se compra**.

---

# 11. Ledger & Economy

## Ledger Core — CORE
Separar precio, cobro, payout, comisión, ingreso, gasto, aporte, suscripción, ajuste y devolución.

## Earnings / Expenses — SHARED
Mensajero, gestora, profesional y negocio.

## Commission Engine — CORE/SHARED
Reglas, atribución, excepciones, cierre y auditoría.

## Payouts — CORE/SHARED
Pendiente, aprobado, pagado y reconciliado.

## Voluntary Contributions — FUTURE
Nunca compran prioridad ni reputación.

## Subscription Billing — FUTURE
Free / Pro / Agent / Business.

---

# 12. Goals

## Goals Core — CORE
Diaria, semanal, mensual, anual y personalizada.

## Goal Progress — SHARED
Progreso, ritmo, desviación y forecast.

## Goal Recommendations — PRO/AGENT
Convierte una meta en acciones, oportunidades, prioridades y alertas.

---

# 13. Notifications & Communication

## Notification Core — CORE
In-app, push y futuras integraciones autorizadas.

## Preferences — CORE
Urgente, operativa, económica, oportunidad, meta, recomendación y comunidad.

## Messaging — SHARED
Ligada a pedido, servicio, cliente, negocio o profesional.

## Low-Bandwidth Mode — CORE
Payload mínimo, imágenes diferidas, caché, cola y reintento.

---

# 14. Content & Growth

## CV Studio — SHARED
Mejora de foto, fondo, recorte, branding, copy, descripciones, formatos sociales y video corto.

## Content Templates — SHARED
Por producto, servicio, plataforma y campaña.

## Publishing Integrations — EDGE
Solo según APIs/permisos de cada plataforma.

## CV Scout — PRO/SHARED
Analiza URL, imagen o texto; detecta precio, compara y guarda oportunidad.

No depender de scraping no autorizado.

---

# 15. Automation

## Automation Core — CORE

```text
TRIGGER
→ CONDITION
→ ACTION
→ PERMISSION
→ AUDIT
```

Niveles:
1. manual;
2. sugerida;
3. confirmada;
4. regla autorizada.

Scheduled tasks: recordatorios, seguimientos, contenido, alertas, reposición y agenda.

Toda automatización económica u operativa importante debe ser auditable.

---

# 16. Agent Platform — CORE/FUTURE

Componentes comunes:
- contexto;
- memoria;
- herramientas;
- permisos;
- objetivos;
- eventos;
- acciones;
- auditoría.

Especializaciones:
- Courier Copilot;
- Sales Copilot;
- Home Agent;
- Business Agent;
- Professional Agent.

---

# 17. Benefits — SHARED/FUTURE

Proveedor, beneficio, elegibilidad, vigencia y uso.

Casos: mantenimiento, repuestos, conectividad, alimentación, herramientas y formación.

---

# 18. Client / Home — VERTICAL

- CV Home;
- Pantry;
- Household Budget;
- recurrencia;
- mantenimiento;
- Prevente Integration futura.

Prevente debe separar bienestar general de salud clínica/regulada.

---

# 19. Business — VERTICAL

- Business OS;
- Inventory Intelligence;
- Merchant Analytics;
- Business Team;
- gestoras;
- mensajeros;
- CRM;
- contenido;
- reposición.

---

# 20. Professional Services — VERTICAL

- perfil profesional;
- especialidad;
- zona;
- portafolio;
- agenda;
- cotización;
- service job;
- evidencia;
- reputación;
- garantías futuras.

---

# 21. Triciclub — VERTICAL

Mobility Core: viaje, pasajeros, carga, vehículo, disponibilidad, asignación y recorrido.

Debe reutilizar Identity, Maps, Scheduling, Reputation, Ledger, Notifications y Agent Platform.

---

# 22. Integrations — EDGE

## CURRENT
- WooCommerce;
- WordPress.

## SHARED/FUTURE
- WhatsApp;
- proveedores de mapas;
- redes sociales;
- proveedores de pagos.

Separar siempre la semántica interna del proveedor externo.

---

# 23. Observability, Audit & Security — CORE

- audit log;
- telemetry;
- errores;
- latencia;
- sync/offline failures;
- privacidad;
- autenticación;
- autorización;
- secretos;
- rate limiting;
- abuso/fraude futuro.

---

# 24. Capas de plataforma

```text
EXPERIENCIAS
Cliente | Gestora | Mensajero | Negocio | Profesional

VERTICALES
Commerce | Food | Services | Triciclub | Prevente

SHARED
CRM | Maps | Scheduling | Logistics | Studio | Goals | Reputation | Notifications

CORE
Identity | Organizations | Orders | Events | Ledger | Permissions | Automation | Audit

EDGE
WooCommerce | WordPress | WhatsApp | Maps | Payments | Social
```

---

# 25. Matriz de reutilización

| Capacidad | Cliente | Gestora | Mensajero | Negocio | Profesional |
|---|---|---|---|---|---|
| Identity | ✓ | ✓ | ✓ | ✓ | ✓ |
| Orders | ✓ | ✓ | ✓ | ✓ | ✓ |
| CRM | limitado | ✓ | ✓ | ✓ | ✓ |
| Scheduling | ✓ | ✓ | ✓ | ✓ | ✓ |
| Maps | ✓ | ✓ | ✓ | ✓ | ✓ |
| Logistics | ✓ | ✓ | ✓ | ✓ | posible |
| Reputation | ✓ | ✓ | ✓ | ✓ | ✓ |
| Goals | ✓ | ✓ | ✓ | ✓ | ✓ |
| Notifications | ✓ | ✓ | ✓ | ✓ | ✓ |
| Ledger | ✓ | ✓ | ✓ | ✓ | ✓ |
| Studio | opcional | ✓ | limitado | ✓ | ✓ |
| Agent | Home | Sales | Courier | Business | Professional |
| Automation | ✓ | ✓ | ✓ | ✓ | ✓ |

---

# 26. Capacidades que NO deben duplicarse

Nunca crear un segundo motor independiente de:
- identidad;
- permisos;
- pedidos;
- eventos;
- reputación;
- ledger;
- notificaciones;
- geolocalización;
- automatización;
- auditoría.

---

# 27. Frontera CURRENT / PREPARE / FUTURE

## CURRENT
WooCommerce, WordPress, pedido canónico, gestoras, comisiones, mensajería, dependientas, administración, checkout, seguimiento y CI/despliegue.

## PREPARE
Roles múltiples, origen del pedido, geo desacoplado, eventos reutilizables, ledger limpio, notificaciones contextuales, scheduling común, CRM común y offline-awareness.

## FUTURE
Marketplace universal, agentes autónomos, cesta multi-negocio, flotas, Business OS completo, Prevente integrado, subscription billing y automatizaciones amplias.

---

# 28. Regla de implementación

Antes de crear una feature:
1. localizar su capability;
2. determinar si ya existe;
3. clasificar CURRENT / NEXT / PREPARE / FUTURE;
4. clasificar Core / Shared / Vertical / Edge;
5. evitar duplicación;
6. definir eventos;
7. definir permisos;
8. definir degradación offline si es crítica;
9. definir auditoría;
10. implementar solo el incremento autorizado.

---

## Resultado del Paso 2

Casa Viva ya dispone de un mapa para distinguir **qué construir una sola vez, qué reutilizar, qué pertenece a cada vertical y qué debe esperar**.

Siguiente: **Paso 3 — Journey Maps** para mensajero, gestora, cliente, negocio y profesional.
