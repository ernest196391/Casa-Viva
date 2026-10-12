# CASA VIVA NETWORK — MASTER

## Autoridad

Este documento es la **referencia estratégica maestra** para la evolución futura de Casa Viva hacia Casa Viva Network.

Debe consultarse junto a `docs/CASA_VIVA_BLUEPRINT.md`, los modelos canónicos de pedidos/eventos y el checkpoint vigente.

No sustituye el estado real de `main` ni autoriza implementar automáticamente capacidades futuras.

Toda tarea nueva debe clasificar su impacto como:

- `CURRENT`: existe hoy en `main`.
- `NEXT`: fase concreta autorizada.
- `PREPARE`: decisiones estructurales que conviene dejar preparadas.
- `FUTURE`: visión todavía no autorizada para construir.

Regla central:

> **No romper el presente para construir el futuro, pero tampoco construir hoy algo que haga innecesariamente difícil el futuro.**

---

# 1. Visión

> **Casa Viva es la infraestructura que conecta las necesidades de una casa y de la vida cotidiana con las personas y negocios capaces de resolverlas.**

No es solamente tienda. El sistema puede abarcar progresivamente:

- comercio y abastecimiento;
- alimentación y delivery;
- mensajería;
- movilidad y carga mediante Triciclub;
- reparación y mantenimiento;
- profesionales;
- energía renovable;
- automatización y servicios del hogar;
- Prevente como vertical especializado de bienestar/prevención, con límites sanitarios propios.

---

# 2. Filosofía del jardín

> **No perseguimos las mariposas. Construimos el mejor jardín.**

La plataforma debe atraer y retener por valor real, no por dependencia artificial.

Los cinco actores principales son:

1. cliente;
2. gestora;
3. mensajero;
4. negocio;
5. profesional.

Cada uno aporta algo y Casa Viva hace que valga más:

- mensajero → vehículo/capacidad/tiempo;
- gestora → relaciones/confianza/venta;
- cliente → necesidad/capacidad de compra;
- negocio → productos/servicios/inventario;
- profesional → oficio/conocimiento/capacidad técnica.

> **Todo el mundo trae algo. Casa Viva ayuda a que valga más.**

---

# 3. Átomo del ecosistema

```text
ALGUIEN NECESITA ALGO
        ↓
ALGUIEN PUEDE PROPORCIONARLO
        ↓
ALGUIEN PUEDE DESCUBRIRLO / VENDERLO
        ↓
ALGUIEN PUEDE MOVERLO O EJECUTARLO
        ↓
CASA VIVA COORDINA
        ↓
TODOS RECIBEN VALOR
```

Ese algo puede ser producto, comida, mensajería, carrera, carga, reparación, instalación o servicio.

---

# 4. Promesas por actor

## Mensajero

> **Casa Viva convierte carreras en agenda, agenda en clientes y clientes en una operación.**

Objetivo: pasar de trabajo improvisado a agenda, clientes recurrentes, ingresos previsibles, segundo vehículo, equipo y empresa logística.

Capacidades objetivo: agenda, mapas offline-first, ubicación, seguimiento, CRM, metas, rentabilidad, matching, rutas compatibles, retorno, seguridad, mantenimiento, Courier Copilot, automatizaciones y flota.

## Gestora

> **Tus relaciones pueden convertirse en un negocio.**

Objetivo: empezar sin capital, operar una tienda multinegocio, conservar clientes, automatizar tareas, producir contenido, coordinar logística y evolucionar hacia marca/equipo/microempresa.

Capacidades: CV Store, catálogo universal, CRM, pipeline, links atribuibles, Studio, Scout, alertas, Sales Copilot, metas, predicción de recompra, logística y equipo.

## Cliente

> **Tu casa y tu vida cotidiana, resueltas desde un solo lugar.**

Objetivo: comprar, recordar menos, optimizar coste total, reponer productos, encontrar profesionales, resolver necesidades y construir memoria del hogar.

Capacidades: CV Home, historial, direcciones, recurrencias, despensa inteligente, coste puesto en casa, cesta optimizada, presupuestos y Home Agent.

## Negocio

> **Trae tu negocio. Casa Viva te ayuda a convertirlo en un sistema.**

Objetivo: reducir trabajo manual, vender más, gestionar catálogo/stock/clientes, acceder a gestoras/mensajeros, crear contenido y operar con analítica/automatización.

Capacidades: Business OS, catálogo, inventario, CRM, gestoras, mensajeros, contenido, branding, analítica, reposición, pagos y Business Agent.

## Profesional

> **Tú sabes hacer el trabajo. Casa Viva te ayuda a convertirlo en un negocio.**

Objetivo: trabajos → reputación → clientes → agenda → equipo → empresa.

Incluye plomeros, electricistas, técnicos, instaladores, energía renovable, paneles solares, refrigeración, reparación, limpieza, redes, cámaras y otros servicios del hogar.

---

# 5. Offline-first

Para operaciones críticas en Cuba:

> **Perder Internet no debe significar dejar de trabajar.**

Preparar arquitectura para:

- pedido activo local;
- agenda local;
- coordenadas persistidas;
- GPS sin Internet;
- mapas offline;
- cola de acciones pendientes;
- reintentos;
- última ubicación conocida;
- ahorro de datos;
- sincronización tolerante a fallos.

No acoplar el dominio a una sola app externa de mapas.

---

# 6. Monetización universal

La progresión oficial es:

## Nivel 1 — HAZLO
Herramientas esenciales gratuitas y funcionales.

## Nivel 2 — HAZLO MEJOR
Pro aporta inteligencia, recomendaciones, analítica y productividad.

## Nivel 3 — HAZLO CONMIGO
Un agente colabora con el usuario.

## Nivel 4 — HAZLO POR MÍ
Automatización supervisada, reglas, delegación y equipos.

> **Casa Viva monetiza capacidad, inteligencia y tiempo recuperado; no dependencia artificial.**

La reputación no se compra.

La aportación voluntaria puede existir, pero no compra pedidos, prioridad ni reputación y no penaliza a quien no aporta.

---

# 7. Agent Platform

No construir cinco chatbots separados.

Debe existir progresivamente un núcleo común de agentes con contexto, memoria, herramientas, permisos, objetivos, eventos y auditoría.

Experiencias:

- Courier Copilot;
- Sales Copilot;
- Home Agent;
- Business Agent;
- Professional Agent.

Regla:

> **La IA no es una pantalla. Debe aparecer donde ayuda a decidir o ejecutar.**

Automatización:

```text
MANUAL
→ SUGERIDA
→ CONFIRMADA
→ REGLA AUTOMÁTICA AUTORIZADA
```

---

# 8. Capability Map maestro

## CORE — construir una sola vez

- Identity
- Roles / Permissions
- Organizations / Teams
- Order Core
- Order State / Events
- Audit
- Ledger
- Automation Core
- Geo Core

## SHARED — reutilizar entre verticales

- CRM
- Scheduling
- Maps / Routing
- Logistics
- Reputation
- Verification
- Goals
- Notifications
- Messaging
- Content Studio
- Recommendation
- Benefits

## VERTICALES

### Commerce
Productos, stock, variantes, tienda, checkout.

### Food
Platos, ingredientes, preparación, disponibilidad, recurrencia.

### Services
Profesionales, cotización, agenda, trabajo, evidencia.

### Triciclub
Pasajeros, carga, vehículo, viajes, disponibilidad y asignación.

### Prevente
Bienestar/prevención con límites sanitarios y permisos específicos.

## EDGE / INTEGRATIONS

- WordPress
- WooCommerce
- WhatsApp
- proveedores de mapas
- redes sociales
- proveedores de pagos futuros

---

# 9. Capacidades que NO deben duplicarse

No crear motores independientes paralelos para:

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

Los verticales pueden especializar reglas, no duplicar el núcleo.

---

# 10. Pedido y transición

Durante la transición:

> **WooCommerce continúa siendo la fuente oficial del pedido comercial actual.**

No crear ahora un segundo motor contradictorio.

A futuro `Order` deberá admitir origen explícito (`source_type`, `source_id`) para Casa Viva, negocio, gestora, cliente, Food, Triciclub, Services e integraciones.

Esto es `PREPARE/FUTURE`, no autorización automática para cambiar el esquema actual.

---

# 11. Mapas y ubicación

El núcleo geo futuro debe soportar:

- cliente;
- negocio;
- mensajero;
- profesional;
- recogida;
- entrega;
- ruta;
- distancia;
- coste logístico;
- offline.

Cliente/gestora/negocio deben poder conocer el estado sin llamadas repetidas.

Nunca fingir tiempo real: mostrar posición reciente o última posición conocida.

La privacidad de ubicación debe depender de necesidad, permiso, rol y duración.

---

# 12. Cliente y coste total

Casa Viva debe optimizar el **coste puesto en casa**, no solo el precio del producto.

Considerar progresivamente:

```text
PRECIO
+ MENSAJERÍA
+ DISTANCIA
+ TIEMPO
+ DISPONIBILIDAD
+ REPUTACIÓN
```

La cesta multi-negocio y despensa inteligente son futuras, no MVP inmediato.

---

# 13. Datos como activo del participante

- mensajero → cartera + historial + reputación;
- gestora → clientes + tienda + ventas;
- cliente → memoria del hogar;
- negocio → clientes + operaciones + marca + datos;
- profesional → reputación + cartera + portafolio.

Cada interacción debería hacer más valioso el sistema para la persona al día siguiente.

---

# 14. Flywheel

```text
MÁS NEGOCIOS
     ↓
MÁS OFERTA
     ↓
MÁS GESTORAS
     ↓
MÁS DESCUBRIMIENTO
     ↓
MÁS CLIENTES
     ↓
MÁS PEDIDOS
     ↓
MÁS MENSAJEROS / PROFESIONALES
     ↓
MEJOR DISPONIBILIDAD
     ↓
MEJOR EXPERIENCIA
     ↓
MÁS CLIENTES
     ↓
MÁS NEGOCIOS
     ↺
```

---

# 15. Principios obligatorios

1. El jardín primero.
2. Primero valor, después monetización.
3. Independencia del participante.
4. Una fuente de verdad por concepto.
5. No duplicar motores.
6. Menos decisiones innecesarias.
7. Transparencia económica.
8. Offline-first para operaciones críticas.
9. IA contextual, no decorativa.
10. Automatización progresiva y autorizada.
11. Cada interacción construye un activo.
12. El éxito del participante beneficia al ecosistema.
13. La reputación se gana; no se compra.
14. La visión puede ser enorme; cada implementación debe ser pequeña y verificable.
15. Nunca reconstruir solo porque exista una visión futura mejor.

---

# 16. Qué NO autoriza este documento

No autoriza implementar inmediatamente:

- marketplace universal;
- wallet completa;
- agentes autónomos;
- Triciclub dentro del repo Casa Viva;
- Prevente;
- navegación propia completa;
- Business OS completo;
- scraping de redes;
- automatizaciones invasivas;
- créditos;
- seguros;
- recomendaciones clínicas;
- superapp monolítica.

---

# 17. Secuencia estratégica

1. Ecosystem Blueprint — **COMPLETADO V1**
2. Capability Map — **COMPLETADO V1**
3. Journey Maps
4. Monetization Matrix
5. Domain Architecture
6. Agent Architecture
7. Integration Architecture
8. Repository Gap Analysis
9. Migration Roadmap
10. GitHub Implementation Plan

---

# 18. Regla para otros chats y agentes

Antes de implementar una mejora estratégica:

1. leer este archivo;
2. leer `docs/CASA_VIVA_BLUEPRINT.md`;
3. leer el checkpoint actual;
4. inspeccionar `main`;
5. clasificar la tarea como CURRENT / NEXT / PREPARE / FUTURE;
6. identificar la capability correspondiente;
7. evitar duplicación;
8. respetar pedidos, eventos, permisos, auditoría y compatibilidad;
9. implementar solamente el incremento autorizado.

---

## Frase rectora

> **Casa Viva construye el jardín donde clientes, vendedores, mensajeros, profesionales y negocios encuentran las herramientas y relaciones necesarias para vivir mejor y crecer juntos.**
