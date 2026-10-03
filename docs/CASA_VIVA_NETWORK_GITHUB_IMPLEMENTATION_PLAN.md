# Casa Viva Network — GitHub Implementation Plan V1

## Estado
Paso 10 — COMPLETADO V1.

## Propósito
Definir cómo convertir la visión estratégica en cambios de GitHub sin interferir con el Bloque 07 actual ni crear una reescritura paralela.

## Regla cero
> Este documento NO autoriza iniciar Network mientras el bloque de lanzamiento actual no esté certificado. Cada fase futura requiere auditoría del `main` real y autorización concreta.

---

## 1. Modelo de trabajo GitHub

Para cada incremento:

1. leer `AGENTS.md`;
2. leer `docs/CASA_VIVA_CURRENT_STATE.md`;
3. leer `docs/CASA_VIVA_NETWORK_MASTER.md`;
4. leer el capability/domain doc relevante;
5. verificar HEAD real de `main`;
6. verificar CI previo;
7. clasificar el trabajo CURRENT / NEXT / PREPARE / FUTURE;
8. crear rama pequeña;
9. implementar una sola frontera coherente;
10. añadir pruebas;
11. actualizar documentación;
12. PR revisable;
13. CI verde;
14. merge solo bajo política/autorización vigente;
15. validar post-merge.

---

## 2. Convención para futuras ramas

Después del Bloque 07, preferir nombres descriptivos, por ejemplo:

- `network/scheduling-foundation`
- `network/geo-contract`
- `network/offline-sync-contract`
- `network/crm-foundation`
- `network/goals-foundation`

No asignar todavía numeración 8A/8B/etc. hasta cerrar 7D y auditar el nuevo `main`.

---

## 3. Primer backlog PREPARE candidato

### N0 — Network Guardrails Audit
Solo documentación/auditoría.

Objetivo:
- inspeccionar hardcodes y acoplamientos relevantes;
- confirmar multi-role actual;
- inventariar geo, CRM, scheduling y money concepts;
- producir matriz KEEP / WRAP / EXTEND / DEFER.

No refactor funcional.

### N1 — Scheduling Foundation
Solo si el producto ya necesita reservas futuras.

Entregables:
- modelo Booking/TimeWindow;
- recurrencia mínima;
- conflictos;
- permisos;
- eventos;
- tests.

No implementar optimización IA todavía.

### N2 — Geo Contract
Entregables:
- GeoPoint/Address/service area contract;
- adapter de proveedor actual;
- timestamps/precision;
- deep-link compatible;
- tests.

No construir navegación completa.

### N3 — Offline Sync Contract
Entregables:
- clasificación offline-safe/online-required;
- idempotency keys;
- outbox protocol;
- conflict semantics;
- sync observability;
- pruebas de reintento.

Primero contrato/test; después app móvil.

### N4 — CRM Foundation
Entregables:
- Contact/Relationship/FollowUp;
- ownership/attribution;
- privacidad;
- eventos;
- migración mínima si aplica.

### N5 — Goals Foundation
Entregables:
- Goal;
- período;
- progreso;
- forecast básico;
- actor/rol;
- notificaciones no invasivas.

---

## 4. Courier roadmap técnico posterior

Después de N1/N2/N3/N4/N5:

- agenda del mensajero;
- clientes propios;
- trabajos recurrentes;
- mapa/offline;
- metas;
- earnings/gastos;
- recomendaciones de tarifa;
- matching read-only;
- matching sugerido;
- Courier Copilot A0-A2;
- reglas A3/A4 solo después de observabilidad.

Cada elemento en PR separado o grupo pequeño cohesivo.

---

## 5. Gestora roadmap técnico posterior

Reutilizando CRM/Catalog/Content:

- tienda multifuente cuando exista segundo merchant;
- Studio básico;
- importación manual/Share-to-Casa-Viva;
- Scout manual;
- pipeline;
- recommendations read-only;
- Sales Copilot A0-A2;
- logística recomendada;
- automatización posterior.

No usar scraping no autorizado como dependencia del MVP.

---

## 6. Multi-business roadmap

Solo tras validar el segundo merchant real:

1. Organizations;
2. MerchantProfile;
3. Offer source;
4. catalog adapter;
5. merchant policies;
6. inventory authority;
7. attribution;
8. logistics;
9. analytics.

Usar WooCommerce adapter para presente; no reemplazar stock actual por un catálogo abstracto sin caso real.

---

## 7. Services roadmap

Cuando el núcleo compartido sea estable:

- ProfessionalProfile;
- ServiceOffering;
- Booking;
- Quote;
- ServiceJob;
- evidence;
- reputation;
- ledger hooks;
- recurrent service.

Debe ser la prueba de que Scheduling/CRM/Reputation/Geo son realmente compartibles.

---

## 8. Agent rollout en GitHub

Orden estricto:

### Agent-0 — Tool contracts
Solo lectura.

### Agent-1 — Recommendations
Sin escritura.

### Agent-2 — Drafts
Genera borradores/planes.

### Agent-3 — Confirmed actions
Una herramienta de escritura por vez, con autorización.

### Agent-4 — Delegated rules
Trigger/condition/action/audit/kill-switch.

Cada nivel requiere evaluación antes del siguiente.

---

## 9. Pruebas obligatorias

### Unit
- reglas de dominio;
- dinero;
- permisos;
- conflictos;
- ranking determinista donde aplique.

### Integration
- WooCommerce adapters;
- events;
- ledger;
- notifications;
- sync.

### Contract
- APIs;
- provider adapters;
- agent tools.

### Offline
- pérdida de red;
- reintento;
- duplicados;
- conflicto;
- cola persistente.

### E2E
Mantener el átomo:

`NEGOCIO → GESTORA → CLIENTE → MENSAJERO → CIERRE`

Y añadir journeys verticales de forma incremental.

---

## 10. Definition of Done Network

Una fase no termina hasta que:
- funcionalidad implementada;
- permisos validados;
- source of truth documentada;
- eventos auditables;
- pruebas verdes;
- degradación/error definidos;
- privacidad revisada;
- rollback/migración evaluados;
- CURRENT_STATE actualizado;
- capability map actualizado si cambió arquitectura.

---

## 11. Archivos maestros de consulta

Orden recomendado:

1. `docs/CASA_VIVA_CURRENT_STATE.md` — verdad operativa actual.
2. `docs/CASA_VIVA_BLUEPRINT.md` — contratos funcionales actuales.
3. `docs/CASA_VIVA_NETWORK_MASTER.md` — visión estratégica.
4. `docs/CASA_VIVA_NETWORK_CAPABILITY_MAP.md` — mapa de capacidades.
5. `docs/CASA_VIVA_NETWORK_JOURNEY_MAPS.md`.
6. `docs/CASA_VIVA_NETWORK_MONETIZATION_MATRIX.md`.
7. `docs/CASA_VIVA_NETWORK_DOMAIN_ARCHITECTURE.md`.
8. `docs/CASA_VIVA_NETWORK_AGENT_ARCHITECTURE.md`.
9. `docs/CASA_VIVA_NETWORK_INTEGRATION_ARCHITECTURE.md`.
10. `docs/CASA_VIVA_NETWORK_REPOSITORY_GAP_ANALYSIS.md`.
11. `docs/CASA_VIVA_NETWORK_MIGRATION_ROADMAP.md`.
12. este documento.

La verdad operativa actual siempre gana frente a una suposición estratégica no implementada.

---

## 12. Estado de los 10 pasos

1. Ecosystem Blueprint — ✅
2. Capability Map — ✅
3. Journey Maps — ✅
4. Monetization Matrix — ✅
5. Domain Architecture — ✅
6. Agent Architecture — ✅
7. Integration Architecture — ✅
8. Repository Gap Analysis — ✅
9. Migration Roadmap — ✅
10. GitHub Implementation Plan — ✅

## Conclusión
La fase de **diseño estratégico inicial de Casa Viva Network queda cerrada**. El siguiente trabajo no es seguir inventando alcance: es terminar el lanzamiento actual, observar operación real y convertir únicamente la siguiente necesidad validada en un incremento pequeño de GitHub.