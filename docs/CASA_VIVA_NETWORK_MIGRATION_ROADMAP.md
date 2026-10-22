# Casa Viva Network — Migration Roadmap V1

## Estado
Paso 9 — COMPLETADO V1.

## Objetivo
Convertir la visión Network en una secuencia que preserve el producto actual y evite reconstrucciones especulativas.

## Regla
> Lanzar primero. Generalizar después. Extraer solo cuando exista un segundo caso real que lo justifique.

---

## Wave 0 — Finish current Casa Viva

Objetivo:
- completar 7C/7D;
- certificar E2E;
- corregir UX visible;
- validar operación real;
- preservar CI, release y rollback.

No añadir todavía marketplace universal ni agentes.

Exit criteria:
- flujo cliente→gestora→dependienta→mensajero→admin estable;
- producción observada;
- incidencias entendibles;
- regresiones controladas.

---

## Wave 1 — Prepare shared foundations

Solo después de lanzamiento estable.

Prioridades:
1. auditar identidad/roles múltiples;
2. introducir Organizations si existe primer merchant/team real;
3. formalizar Scheduling Core;
4. formalizar Geo abstraction;
5. diseñar offline sync contract;
6. CRM shared mínimo;
7. Goals Core.

Motivo: estas capacidades benefician varios actores y no requieren todavía superapp.

---

## Wave 2 — Courier professional layer

Construir sobre mensajería actual:
- agenda;
- reservas futuras;
- clientes propios;
- metas;
- rentabilidad;
- geo/offline;
- matching sugerido;
- retorno;
- reputación inicial.

Mantener Free útil.

Exit criteria:
- mensajero puede usar Casa Viva aunque no tenga pedido Casa Viva ese día;
- agenda y sync sobreviven mala conectividad;
- no se rompe estado canónico actual.

---

## Wave 3 — Gestora commercial OS

Extender lo ya fuerte:
- CRM común;
- tienda ampliada;
- catálogo multifuente cuando exista segundo merchant real;
- Studio;
- Scout manual/permitido;
- Sales Copilot A0-A2;
- logística recomendada.

Exit criteria:
- gestora gestiona clientes propios y múltiples fuentes sin perder atribución;
- creación de contenido reduce tiempo medible;
- comisión/payout continúan auditables.

---

## Wave 4 — Multi-business foundation

Primera verdadera expansión Network.

Añadir:
- Organizations/merchant onboarding;
- ofertas por merchant;
- disponibilidad/stock por fuente;
- políticas comerciales;
- red de gestoras;
- logística compartida;
- analytics básicos.

No crear aún Food + Services + Triciclub simultáneamente.

Seleccionar un segundo tipo de negocio real y aprender.

---

## Wave 5 — Customer Home layer

Con oferta suficiente:
- coste puesto en casa;
- favoritos/recurrencia;
- memoria del hogar;
- lista recurrente;
- presupuesto;
- despensa aproximada;
- cesta optimizada progresivamente.

Exit criteria:
- mejora medible en recompra, ahorro o tiempo;
- estimaciones son explicables y corregibles.

---

## Wave 6 — Services marketplace

Añadir Professionals:
- perfil;
- especialidad;
- agenda;
- disponibilidad;
- solicitud;
- cotización;
- service job;
- reputación;
- evidencia/cierre.

Reutilizar Identity, Scheduling, Geo, CRM, Reputation, Ledger, Notifications.

Este wave prueba si el núcleo realmente es reutilizable fuera de commerce/logistics.

---

## Wave 7 — Triciclub integration

Integrar como vertical, no fusionar UI obligatoriamente.

Reutilizar:
- CV ID;
- Geo;
- Scheduling;
- Reputation;
- Notifications;
- Ledger;
- Agent Platform.

Mantener Mobility domain especializado.

---

## Wave 8 — Food / recurring consumption

Con infraestructura multi-business ya probada:
- restaurantes;
- comida preparada;
- insumos;
- MiPyMEs;
- recurrencia;
- ventanas de preparación;
- delivery.

Aprovechar Home/Pantry.

---

## Wave 9 — Agent Platform progressive rollout

Orden recomendado:
1. A0 consultas;
2. A1 recomendaciones;
3. A2 borradores/planes;
4. A3 acciones confirmadas;
5. A4 reglas delegadas limitadas.

Empezar con dominios de bajo riesgo y alto valor:
- contenido;
- recordatorios;
- análisis;
- preparación de agenda.

Dejar dinero, cancelaciones y asignaciones irreversibles para etapas maduras.

---

## Wave 10 — Pro / Business monetization

Activar monetización solo cuando exista valor medible:
- Courier Pro;
- Sales Pro;
- Business OS;
- Professional Pro;
- Home Pro cuando haya recurrencia suficiente;
- Agent tiers después.

Aportes voluntarios pueden existir antes, pero no sostener el presupuesto base.

---

## Wave 11 — Prevente integration

Solo con:
- consentimiento explícito;
- frontera de datos;
- política de privacidad;
- separación de bienestar vs salud clínica;
- controles apropiados.

---

## Principios de migración

### Strangler pattern
Encapsular y reemplazar piezas gradualmente, no big bang.

### Adapter first
Aislar WooCommerce/WordPress detrás de servicios cuando aparezca necesidad real.

### Event compatibility
Conservar historial y mapear eventos viejos/nuevos.

### Data migration last responsible moment
No mover datos sin necesidad funcional.

### Feature flags
Usar para capacidades nuevas con riesgo.

### Shadow/read-only first
Para recomendadores/agentes, observar y comparar antes de permitir escritura.

### Rollback
Toda fase productiva mantiene rollback verificable.

---

## Gates obligatorios entre waves

Antes de avanzar:
- CI verde;
- E2E principal verde;
- migraciones reversibles o estrategia clara;
- métricas definidas;
- privacidad revisada;
- source of truth explícita;
- no duplicación de dominio;
- documentación CURRENT/NEXT/PREPARE/FUTURE actualizada.

## Conclusión
Casa Viva Network se construye como una secuencia de capacidades compartidas alrededor de un núcleo que ya funciona, no como una segunda plataforma paralela.