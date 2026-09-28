# Casa Viva ↔ NEXO Business Integration Contract v0

**Status:** DESIGN FREEZE for parallel development
**Date:** 2026-09-28
**Goal:** Casa Viva and NEXO Business can evolve independently now and converge later without rebuilding either system.

## Authority during transition
Until an explicit migration phase is approved:
- Casa Viva/WooCommerce remains authoritative for existing web orders, web product publication and current web stock.
- NEXO Business is authoritative only for its local offline POS transactions once the pilot begins.
- Neither system writes directly into the other's internal tables.
- Integration happens through versioned contracts/adapters and stable external references.

This prevents the current Casa Viva Work from being blocked by the new application.

## Shared identity rule
NEXO Business owns stable UUID identities for future canonical business entities. Existing Woo IDs are preserved as external references, never discarded.

Mapping:
- business UUID ↔ Casa Viva tenant/site
- product UUID ↔ Woo product/variation ID + SKU/barcode
- order UUID ↔ Woo order ID/order number
- customer UUID ↔ Woo/customer/contact identity where safely resolvable
- inventory operation UUID ↔ Casa Viva movement UUID
- device UUID ↔ Android/Windows installation

No code may assume a Woo integer ID is the universal NEXO Business ID.

## Contract envelopes
Every cross-system mutation/event must eventually carry:
- contract_version
- event_id UUID
- business_id UUID
- source_system
- source_entity_id
- occurred_at ISO-8601
- idempotency_key
- payload

## Product contract v0
Required portable fields:
product_id(UUID when mapped), external_refs, sku, barcodes[], name, description, category, unit, active, prices[], images[], updated_at/version.

Casa Viva may continue storing this in Woo. NEXO Business stores a local projection for offline use.

## Inventory contract v0
Inventory is exchanged as movements/events, not blind stock overwrites:
movement_id, product identity, location, delta OR physical_count, reason/type, source, source_reference, actor, occurred_at.

During transition Woo remains official web stock. NEXO Business must not push local movements into Woo until reconciliation rules are tested. Initial integration is import/read + reconciliation report.

## Order contract v0
Portable order projection:
order identity/external refs, channel, customer, lines with stable product refs, totals/currency, fulfillment type, address/location as permitted, canonical stage, payment/cash dimension, delivery dimension, incident dimension, attribution refs, timestamps.

Casa Viva canonical state semantics are not renamed by NEXO Business. NEXO Business maps them.

## Event contract v0
Casa Viva already has immutable order events. NEXO Business must ingest/map those events rather than infer history from labels. New cross-system events must be immutable and idempotent.

## Phase bridge
### Bridge 0 — now
Documentation/identity compatibility only. No production data writes between systems.

### Bridge 1 — read-only
NEXO Business imports Casa Viva catalog/products/barcodes/prices and web orders. It can compare local vs Woo stock but does not mutate Woo.

### Bridge 2 — controlled reconciliation
Approved POS inventory/sales movements synchronize to cloud and a connector reconciles Woo stock using idempotency keys and audit records.

### Bridge 3 — shared operational truth
After E2E certification, choose canonical ownership per entity explicitly. Never switch authority implicitly.

## Non-negotiable compatibility tests
- Same product is not duplicated because one side uses UUID and the other Woo ID.
- Replaying a movement/event creates no duplicate.
- Web order imports once.
- Offline POS sale cannot silently overwrite newer web stock.
- Cancellation/refund/restock does not double restore stock.
- Casa Viva order stage maps without losing delivery/payment/incident dimensions.
- Existing Casa Viva historical orders remain readable.
- Either integration can be disabled without stopping Casa Viva web sales or NEXO Business offline POS.
