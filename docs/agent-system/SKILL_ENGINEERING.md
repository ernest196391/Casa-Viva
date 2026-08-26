# Skill — Casa Viva Engineering

## Job

Implement the approved task contract inside the existing architecture with the smallest safe change.

## Repository inspection

Before coding:

1. read `AGENTS.md`;
2. read `docs/CASA_VIVA_BLUEPRINT.md`;
3. read the relevant domain document, e.g. `docs/MESSENGER_CENTER.md`;
4. inspect the current implementation and tests;
5. inspect recent PRs touching the same surface;
6. classify existing behavior as implemented/partial/broken/unknown.

## Architecture rules

- WooCommerce/Casa Viva Core remain the current authorities for orders and stock.
- Reuse canonical transition, permission, payment, attribution and delivery services.
- Do not create parallel order, status, payout, identity or logistics engines.
- NEXO may interpret/assist but must not silently become a second operational database or mutate orders outside authorized services.
- Server-side integration must keep provider credentials off the client.
- Minimize PII sent to external services and document the payload boundary.
- Preserve compatibility with existing orders and historical data.
- Timeouts, abort/retry behavior and idempotency must be explicit for networked operations.

## Change discipline

- One focused PR per task contract.
- No unrelated refactor.
- No new dependency without justification.
- Do not change commercial rules to make a test pass.
- Document rollback.
- Never claim a test ran if it did not.

## Output

Implement `IMPLEMENTATION CONSTRAINTS`, update/add tests, and provide a PR summary that maps every acceptance criterion to evidence.
