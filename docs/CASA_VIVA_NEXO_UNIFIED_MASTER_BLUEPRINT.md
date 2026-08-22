# CASA VIVA + NEXO — UNIFIED MASTER BLUEPRINT

**Estado:** Documento maestro de coordinación entre chats/agentes  
**Fecha:** 22 agosto 2026  
**Repositorio rector:** `ernest196391/Casa-Viva`  
**Branch estratégico actual:** `docs/network-ecosystem-blueprint-v1` (PR #48)  
**Regla:** este documento coordina; no sustituye `main`, `docs/CASA_VIVA_CURRENT_STATE.md`, contratos canónicos ni permisos existentes.

---

## 1. Decisión maestra

La arquitectura queda dividida en tres niveles complementarios:

1. **Casa Viva Core** — fuente operativa de verdad para comercio y operación actual.
2. **Casa Viva Network** — arquitectura futura común del ecosistema.
3. **NEXO** — capa compartida de inteligencia, servicios API y asistente transversal.

NEXO **no** se convierte en una segunda Casa Viva, ni en una segunda app de mensajería, ni crea una base paralela de pedidos/estados/cobros. Casa Viva conserva pedidos, roles, estados, asignaciones, inventario, cobros, tarifas y auditoría. NEXO interpreta, consulta, propone y orquesta servicios compartidos.

---

## 2. Visión del producto final

Casa Viva Network es la infraestructura que conecta necesidades cotidianas con personas, negocios y profesionales capaces de resolverlas.

Verticales previstos:

- Casa Viva Commerce;
- Gestoras / CV Store;
- Mensajería / Courier;
- Triciclub;
- Mandados / delivery;
- Servicios y profesionales;
- Prevente;
- Energía / instalaciones;
- futuras verticales de negocio.

NEXO es el copiloto común del ecosistema: un cerebro compartido que entiende el contexto de cada aplicación, conoce solo la información autorizada y usa herramientas/API para responder o proponer acciones.

---

## 3. Experiencia NEXO: “Pregunta a NEXO — NEXO sabe cosas”

Patrón UX común para las aplicaciones:

- botón flotante persistente en esquina inferior derecha;
- esfera/robot abstracto con identidad NEXO;
- tooltip: **“Pregunta a NEXO”**;
- subtítulo: **“NEXO sabe cosas”**;
- apertura como panel conversacional compacto en móvil y escritorio;
- contexto automático de la pantalla actual;
- acciones sugeridas según rol y permisos;
- botón permanente para hablar con una persona cuando corresponda.

La identidad visual de NEXO debe ser reconocible en todo el ecosistema, pero adaptarse al branding anfitrión. En Casa Viva usa tokens de Casa Viva; en Triciclub usa el sistema visual de Triciclub. El icono NEXO permanece reconocible como capa transversal.

Regla central: **la IA no es una pantalla aparte; aparece donde ayuda a decidir o ejecutar.**

---

## 4. Fronteras de responsabilidad

### Casa Viva Core — dueño del dominio operativo

Casa Viva mantiene como fuente canónica:

- usuarios, roles y permisos actuales;
- WooCommerce/productos/stock durante la transición;
- pedido comercial y pedido operativo;
- estados y eventos canónicos;
- asignación de gestora/dependienta/mensajero;
- preparación, custodia, ruta y entrega;
- cobros, comisiones, payouts y conciliación;
- tarifas oficiales de mensajería;
- incidencias;
- auditoría.

### NEXO — servicios compartidos en Render

NEXO presta servicios **stateless o de conocimiento auxiliar**:

- parsing de vales;
- normalización estructurada;
- detección de campos faltantes/inconsistencias;
- geocodificación;
- sugerencia de rutas;
- búsqueda/consulta de conocimiento autorizado;
- asistente conversacional;
- router Gemini/OpenAI;
- medición de uso/coste IA;
- herramientas futuras de clasificación, resumen y recomendación.

NEXO devuelve **borradores, candidatos o sugerencias**. Casa Viva valida y persiste.

### Casa Viva Network — núcleo futuro común

No duplicar entre verticales:

- Identity;
- Roles/Permissions;
- Organizations/Teams;
- Order Core;
- Order State/Events;
- Audit;
- Ledger;
- Automation Core;
- Geo Core;
- Notifications;
- Reputation.

Los verticales especializan reglas; no crean motores paralelos.

---

## 5. Repositorios y estado actual

### `ernest196391/Casa-Viva`

**Rol:** core operativo y fuente de verdad actual.  
**Branch producción:** `main`.  
**Estado:** Bloques 1A–6B cerrados; 7A–7C integrados; 7D certificación E2E en curso.  
**Regla:** completar baseline estable pre-Network antes de implementar capacidades FUTURE del Network.

### PR #48 — Casa Viva Network

**Branch:** `docs/network-ecosystem-blueprint-v1`.  
**Rol:** arquitectura estratégica futura 10/10.  
**Índice:** `docs/CASA_VIVA_NETWORK_INDEX.md`.  
Incluye Blueprint, Capability Map, Journey Maps, Monetization, Domain, Agent, Integration, Gap Analysis, Migration Roadmap y GitHub Plan.

### `ernest196391/ernesto-rondon-nexo`

**Rol:** web pública NEXO + servicios compartidos IA/API.  
**Runtime:** Next.js/Node en Render.  
**Estado:** producción funcional; Gemini 3.5 Flash Lite primario; OpenAI GPT-5.6 Luna fallback; telemetría de tokens activa; auto-deploy desde `main`.

Prioridad API compartida:

- `POST /api/messaging/parse-voucher`
- `POST /api/messaging/geocode`
- `POST /api/messaging/route-suggest`
- `POST /api/assistant/query`

### `ernest196391/Triciclub`

**Rol:** vertical de movilidad/logística existente.  
**Estado observado:** app independiente con PHP, API, agenda, métricas y assets.  
**Regla:** no moverla dentro de Casa Viva ni reconstruirla. Integrarla progresivamente mediante contratos compartidos.

### Prevente / Mandados / Remesas / Concesionario / web personal

Aún deben tratarse como verticales/proyectos separados hasta que exista un repositorio o contrato concreto. Deben consumir servicios compartidos cuando aporte valor, sin forzar una superapp monolítica.

---

## 6. Contratos API inmediatos

### 6.1 `POST /api/messaging/parse-voucher`

Entrada mínima:

```json
{
  "text": "vale original",
  "source": "whatsapp|manual|image|pdf|url",
  "locale": "es-CU"
}
```

Salida:

```json
{
  "normalized": {
    "external_id": null,
    "provider": null,
    "manager": null,
    "customer": {"name": null, "phones": [], "address": null, "zone": null, "reference": null},
    "items": [],
    "delivery": {"quoted_amount": null, "currency": null},
    "payments": [],
    "change_required": [],
    "notes": [],
    "time_window": null
  },
  "missing": [],
  "warnings": [],
  "confidence": 0.0,
  "provider": "gemini|openai"
}
```

No crea pedido. Casa Viva muestra “He entendido esto”, confirma/corrige y crea el pedido canónico.

### 6.2 `POST /api/messaging/geocode`

Recibe dirección/zona/referencia y devuelve candidatos con coordenadas y confianza. No modifica el pedido.

### 6.3 `POST /api/messaging/route-suggest`

Casa Viva envía paradas ya autorizadas con IDs, coordenadas, ventanas y prioridades. NEXO devuelve un orden sugerido y razones. El mensajero mantiene la última palabra.

### 6.4 `POST /api/assistant/query`

Entrada: pregunta, rol, contexto mínimo autorizado y herramientas permitidas.  
Salida: respuesta + fuentes/herramientas usadas + acciones sugeridas.  
Nunca acceso SQL libre. Acciones sensibles requieren confirmación y pasan por APIs canónicas de Casa Viva.

---

## 7. NEXO Gateway y routing de modelos

Política inicial:

- Gemini 3.5 Flash Lite: consultas generales, parsing de bajo riesgo y volumen/freemium cuando los datos lo permitan;
- OpenAI GPT-5.6 Luna: fallback y tareas que requieren mayor robustez;
- modelos superiores: únicamente tareas premium/alta complejidad donde el valor lo justifique.

El modelo se selecciona por coste, calidad, disponibilidad, sensibilidad de datos y tipo de tarea.

Registrar por petición:

- proveedor/modelo;
- tokens entrada/salida;
- latencia;
- éxito/fallo;
- fallback;
- coste estimado;
- herramienta utilizada.

---

## 8. MVP de mensajería que puede salir ya

No construir una aplicación paralela. Reutilizar lo ya existente en Casa Viva.

Flujo mínimo:

```text
VALE REAL
  ↓
NEXO parse-voucher
  ↓
“HE ENTENDIDO ESTO”
  ↓ confirmar/corregir
CASA VIVA crea/actualiza pedido canónico
  ↓
Centro del Mensajero existente
  ↓
WhatsApp / llamada / ubicación
  ↓
Preparado / asignado / recogido / en ruta
  ↓
Entregado / incidencia
  ↓
Cobro / conciliación / auditoría canónica
```

Primer piloto real: usar vales reales de Casa Viva de una jornada y medir tiempo ahorrado, errores, llamadas, entregas, incidencias y coste IA.

---

## 9. Integración del asistente NEXO por rol

### Gestora

- “¿Dónde está mi pedido?”
- “¿Cuánto cuesta la mensajería a esta zona?”
- “Interpreta este vale.”
- “¿Qué me falta para confirmar?”
- “Prepara mensaje para el cliente.”

### Mensajero

- “¿Cuál es mi siguiente parada?”
- “¿Qué debo cobrar?”
- “¿Quién necesita vuelto?”
- “Muéstrame los clientes no confirmados.”
- “Propón ruta, respetando horarios.”

### Dependienta

- “¿Qué tengo que preparar?”
- “Agrupa por proveedor.”
- “¿Qué pedido cambió después de cargado?”

### Administrador

- “¿Qué está bloqueado hoy?”
- “¿Qué incidencias requieren acción?”
- “¿Cuánto costó la IA esta semana?”

### Cliente

- “¿Dónde está mi pedido?”
- “¿Cuándo llega?”
- “Necesito ayuda.”

Cada respuesta se restringe por rol y contexto.

---

## 10. Arquitectura unificada

```text
                 ┌──────────────────────────┐
                 │      CASA VIVA NETWORK   │
                 │ estrategia/core futuro  │
                 └────────────┬─────────────┘
                              │
     ┌────────────────────────┼────────────────────────┐
     │                        │                        │
┌────▼─────┐            ┌─────▼─────┐           ┌─────▼─────┐
│Casa Viva │            │ Triciclub │           │ Verticales │
│Commerce  │            │ Mobility  │           │ futuros    │
└────┬─────┘            └─────┬─────┘           └─────┬─────┘
     │                        │                        │
     └────────────────────────┼────────────────────────┘
                              │ contratos API
                    ┌─────────▼─────────┐
                    │   NEXO GATEWAY    │
                    │ IA + shared APIs  │
                    │ Gemini/OpenAI     │
                    │ Geo/Route/Assist  │
                    └───────────────────┘
```

NEXO no posee el pedido. NEXO conoce cosas a través de herramientas autorizadas.

---

## 11. Plan de trabajo coordinado desde hoy

### Track A — Casa Viva Core

Responsable: chat/agente de Casa Viva.  
Objetivo: terminar 7D y emitir `CASA VIVA CORE — BASELINE ESTABLE PRE-NETWORK` con SHA exacto.

No empezar Network antes de ese checkpoint salvo trabajos `PREPARE` que no muten el core.

### Track B — NEXO Shared Services

Responsable: chat/agente NEXO.  
Objetivo inmediato:

1. `parse-voucher`;
2. `geocode`;
3. `route-suggest`;
4. `assistant/query`;
5. documentación OpenAPI/contratos;
6. auth service-to-service;
7. observabilidad/coste.

### Track C — Mensajería piloto

Responsable: chat/app mensajero sobre Casa Viva.  
Objetivo: consumir NEXO, no duplicarlo. Usar Centro del Mensajero y estados canónicos ya existentes.

### Track D — Casa Viva Network

Responsable: chat estratégico Network.  
Objetivo: mantener arquitectura, migración y secuencia. No implementar FUTURE prematuramente.

### Track E — Triciclub

Responsable: chat Triciclub.  
Objetivo: mantener vertical independiente y preparar integración con NEXO Gateway/Geo/Identity cuando exista contrato estable.

---

## 12. Reglas para todos los chats/agentes

Antes de tocar código:

1. identificar repositorio y branch;
2. leer documento maestro correspondiente;
3. leer estado/checkpoint actual;
4. inspeccionar HEAD real;
5. clasificar `CURRENT / NEXT / PREPARE / FUTURE`;
6. identificar capability propietaria;
7. comprobar si ya existe una implementación canónica;
8. no duplicar base de datos, estados, identidad, ledger, auditoría, geo ni notificaciones;
9. crear rama/PR pequeño;
10. exigir CI verde;
11. mergear solo cuando corresponda;
12. deployment reproducible y reversible;
13. actualizar documentación/checkpoint.

---

## 13. Fuente única de coordinación

Punto maestro de lectura para chats/agentes:

1. `docs/CASA_VIVA_NEXO_UNIFIED_MASTER_BLUEPRINT.md` — coordinación ecosistema;
2. `docs/CASA_VIVA_CURRENT_STATE.md` — verdad del lanzamiento actual;
3. `docs/CASA_VIVA_BLUEPRINT.md` — core Casa Viva;
4. `docs/CASA_VIVA_NETWORK_INDEX.md` — estrategia Network;
5. repo NEXO — contratos/API compartida;
6. repo Triciclub — vertical movilidad actual.

Si dos documentos parecen contradecirse, prevalece el estado real de `main` y el contrato canónico vigente para operaciones presentes. La visión Network orienta el futuro, pero no invalida el presente.

---

## 14. Resultado que buscamos

Una familia de aplicaciones especializadas, no una superapp monolítica:

- cada vertical conserva una experiencia simple;
- comparten capacidades que conviene construir una vez;
- NEXO aparece como copiloto común;
- Casa Viva Network coordina el ecosistema;
- los datos permanecen en su dominio canónico;
- la IA consulta mediante herramientas y permisos;
- el sistema funciona con conectividad limitada;
- monetización progresiva: Hazlo → Hazlo Mejor → Hazlo Conmigo → Hazlo Por Mí.

**Frase operativa:**

> **Casa Viva opera. Casa Viva Network conecta. NEXO entiende, consulta y ayuda a actuar.**
