# Casa Viva Network — Domain Architecture V1

## Estado
Paso 5 — COMPLETADO V1.

## Objetivo
Definir fronteras de dominio antes de endpoints, tablas o microservicios. La meta es evitar cinco productos con cinco versiones incompatibles de identidad, pedidos, dinero, reputación o geografía.

## Principio
> Un concepto de negocio importante debe tener una sola semántica canónica, aunque tenga múltiples interfaces.

---

## 1. Bounded Contexts propuestos

### Identity & Access
Entidades/conceptos:
- User;
- Profile;
- Role;
- Capability;
- Membership;
- Device;
- Consent.

Responsabilidad: quién es el actor y qué puede hacer.

### Organizations
- Organization;
- Branch;
- Team;
- Membership;
- BusinessProfile.

Responsabilidad: representar negocios y equipos sin convertirlos en usuarios especiales.

### Catalog
- Offer;
- Product;
- ServiceOffering;
- FoodOffering;
- MobilityOffering;
- Variant;
- Price;
- Availability;
- InventoryReference.

Responsabilidad: qué se ofrece. No posee el pedido.

### Marketplace
- Listing;
- SearchDocument;
- Recommendation;
- Match;
- RankingContext.

Responsabilidad: descubrimiento. No debe ser fuente oficial de stock o dinero.

### Order
- Order;
- OrderLine;
- OrderSource;
- Attribution;
- FulfillmentRequirement;
- OrderEvent.

Responsabilidad: acuerdo transaccional y ciclo de vida del pedido.

Durante transición WooCommerce conserva autoridad para pedidos comerciales actuales.

### Scheduling
- Booking;
- TimeWindow;
- AvailabilitySlot;
- RecurrenceRule;
- ScheduleConflict.

Responsabilidad: compromisos futuros de tiempo.

### Logistics
- Delivery;
- Pickup;
- Dropoff;
- CourierAssignment;
- Custody;
- RoutePlan;
- ProofOfDelivery;
- LogisticsIncident.

Responsabilidad: mover bienes/personas y cerrar evidencia logística.

### Geo
- GeoPoint;
- Address;
- ServiceArea;
- RouteReference;
- LocationSample;
- OfflineRegion.

Responsabilidad: semántica geográfica independiente del proveedor de mapas.

### Relationship / CRM
- Contact;
- Relationship;
- Lead;
- Opportunity;
- FollowUp;
- CustomerPreference;
- HouseholdProfile.

Responsabilidad: memoria relacional. No debe modificar pedidos históricos.

### Reputation & Trust
- ReputationProfile;
- Rating;
- Review;
- Verification;
- Certification;
- TrustSignal.

Responsabilidad: confianza explicable basada en hechos/eventos.

### Ledger & Money
- LedgerEntry;
- Charge;
- Earning;
- Commission;
- Payout;
- Refund;
- Adjustment;
- Contribution;
- SubscriptionCharge.

Responsabilidad: movimientos económicos. Separar precio, pago, comisión, payout y contabilidad.

### Goals
- Goal;
- GoalPeriod;
- ProgressSnapshot;
- Forecast.

Responsabilidad: objetivos y progreso; no posee órdenes ni ledger.

### Notifications
- Notification;
- Preference;
- DeliveryChannel;
- NotificationAttempt.

Responsabilidad: comunicar eventos; nunca convertirse en fuente de verdad del evento.

### Content
- Asset;
- BrandKit;
- ContentDraft;
- Template;
- PublicationIntent.

Responsabilidad: creación/reutilización de contenido.

### Automation
- AutomationRule;
- Trigger;
- Condition;
- Action;
- ApprovalRequirement;
- Execution;
- AuditRecord.

Responsabilidad: automatizar acciones explícitamente permitidas.

### Agent
- AgentProfile;
- MemoryReference;
- ToolPermission;
- AgentTask;
- Recommendation;
- ProposedAction.

Responsabilidad: razonamiento y coordinación; no saltarse servicios de dominio ni permisos.

### Benefits
- Benefit;
- Provider;
- Eligibility;
- Redemption.

### Incident & Support
- Incident;
- Dispute;
- SupportCase;
- Evidence;
- Resolution.

---

## 2. Aggregate roots sugeridos

Inicialmente considerar como agregados independientes:
- User;
- Organization;
- Offer;
- Order;
- Booking;
- Delivery;
- Relationship;
- ReputationProfile;
- LedgerAccount/ledger stream;
- Goal;
- AutomationRule;
- Incident.

No convertir `Order` en un objeto gigante dueño de toda la plataforma.

---

## 3. Eventos canónicos

Ejemplos futuros:

### Order
- OrderCreated
- OrderConfirmed
- OrderCancelled
- OrderCompleted

### Scheduling
- BookingCreated
- BookingRescheduled
- BookingCancelled

### Logistics
- CourierAssigned
- CourierAccepted
- PickupStarted
- CustodyTransferred
- DeliveryStarted
- DeliveryCompleted
- DeliveryFailed

### Money
- ChargeRecorded
- CommissionAccrued
- PayoutApproved
- PayoutPaid
- RefundRecorded

### CRM
- LeadCreated
- FollowUpScheduled
- CustomerReactivated

### Reputation
- RatingSubmitted
- VerificationGranted
- ReputationRecalculated

### Automation
- AutomationProposed
- AutomationApproved
- AutomationExecuted
- AutomationFailed

Eventos actuales existentes deben mapearse antes de introducir nombres nuevos.

---

## 4. Reglas de dependencia

Permitido:
- Marketplace consulta Catalog/Geo/Reputation.
- Order referencia Offer snapshots y Attribution.
- Logistics referencia Order y Geo.
- Ledger escucha eventos de Order/Logistics/Commission.
- Notifications escucha eventos de dominio.
- Agents llaman servicios de aplicación autorizados.

Evitar:
- UI escribiendo directamente ledger;
- Agent modificando base de datos sin servicio de dominio;
- Catalog cerrando pedidos;
- Notification definiendo estado real;
- Reputation dependiendo de plan pagado;
- mapas externos almacenando semántica de negocio.

---

## 5. Fuente de verdad por concepto

| Concepto | Fuente canónica objetivo |
|---|---|
| Identidad | Identity |
| Organización | Organizations |
| Oferta | Catalog |
| Pedido | Order (WooCommerce durante transición comercial actual) |
| Reserva | Scheduling |
| Entrega/custodia | Logistics |
| Ubicación | Geo + proveedor como integración |
| Relación/lead | CRM |
| Reputación | Reputation |
| Movimiento económico | Ledger |
| Meta | Goals |
| Notificación | Notification log, derivada de evento |
| Automatización | Automation |

---

## 6. Monolito modular primero

No se prescribe microservicios.

Recomendación:
1. conservar el sistema actual;
2. reforzar fronteras lógicas;
3. encapsular servicios;
4. emitir eventos;
5. extraer servicios solo cuando exista necesidad operativa demostrada.

Casa Viva puede escalar mucho tiempo como monolito modular bien organizado. Distribuir prematuramente aumentaría complejidad, especialmente con baja conectividad y un equipo pequeño.

---

## 7. Offline domain contract

Las acciones críticas móviles deben clasificarse:

### Offline-safe
Puede ejecutarse localmente y sincronizarse:
- notas;
- marcadores de llegada;
- evidencia capturada;
- ciertos cambios operativos idempotentes.

### Online-preferred
Puede prepararse offline pero requiere confirmación del servidor:
- aceptar una nueva carrera competitiva;
- reservar un recurso compartido.

### Online-required
Acciones donde una decisión desactualizada causaría daño económico o doble asignación, salvo diseño específico de reserva.

Cada entidad sincronizable debe definir:
- id estable;
- versión;
- updated_at;
- pending_sync;
- idempotency key;
- política de conflicto.

---

## 8. Arquitectura de transición

### CURRENT
WooCommerce/WordPress y contratos ya validados.

### PREPARE
- adapters;
- services;
- eventos;
- interfaces claras;
- IDs estables;
- separación dinero/logística/pedido;
- source metadata cuando sea seguro.

### FUTURE
Generalizar a múltiples verticales solo cuando un segundo caso real lo requiera.

## 9. Regla final
> Generalizar por evidencia, no por imaginación; pero mantener fronteras que hagan posible generalizar sin reescribir el núcleo.