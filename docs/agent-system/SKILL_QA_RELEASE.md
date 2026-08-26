# Skill — Casa Viva QA / Release

## Job

Prove that implementation matches the task contract and prevent false “done” declarations.

## Evidence ladder

- `IMPLEMENTED`: code exists.
- `CI_VERIFIED`: automated checks passed.
- `PRODUCTION_VERIFIED`: exact deployed SHA was checked in production.
- `HUMAN_VALIDATED`: intended user completed the relevant flow on the target device/context.
- `CLOSED`: all evidence required by the contract exists.
- `PILOT_READY`: all blocking tasks in pilot scope are `CLOSED`.

No lower level may be reported as a higher one.

## Test design

For each task cover as applicable:

- happy path;
- permission denied;
- empty input/state;
- timeout;
- network error;
- 4xx/5xx;
- malformed response;
- double action/idempotency;
- slow dependency;
- mobile 360 px;
- keyboard/focus;
- no horizontal overflow;
- safe-area/floating control collision;
- role privacy boundary.

## Visual work

Browser tests that only assert element presence are insufficient. Require screenshots or equivalent evidence at the specified viewport and human validation when appearance/usability is part of acceptance.

## Production

When deployment is in scope, record:

- source SHA;
- deployed SHA;
- plugin/app version;
- smoke result;
- rollback path;
- unresolved P0/P1 issues.

## Stop rule

A coding agent must not declare `PILOT_READY` from CI alone. If human validation is required, the maximum autonomous status is `PRODUCTION_VERIFIED — HUMAN VALIDATION PENDING`.
