# Casa Viva — repository agent rules

These instructions apply to the whole repository.

## 1. Read the source of truth before changing behavior

For functional work involving orders, messaging, gestoras, commissions, payments, customers or operations, read:

1. `docs/CASA_VIVA_BLUEPRINT.md` — permanent functional authority.
2. `docs/CASA_VIVA_CURRENT_STATE.md` — current implementation status.
3. The relevant domain document, e.g. `docs/MESSENGER_CENTER.md` for Messenger.
4. Existing code/tests and recent PRs that touched the same surface.

Do not duplicate a capability that already exists.

## 2. Translate informal requests before coding

For non-trivial product work, use the operating system in `docs/agent-system/README.md`.

Every implementation task must be expressed with `docs/agent-system/WORK_TASK_CONTRACT.md` and only the skills required for that task:

- Product: `docs/agent-system/SKILL_PRODUCT.md`
- UX: `docs/agent-system/SKILL_UX.md`
- UI: `docs/agent-system/SKILL_UI.md`
- Copy: `docs/agent-system/SKILL_COPY.md`
- Engineering: `docs/agent-system/SKILL_ENGINEERING.md`
- QA/Release: `docs/agent-system/SKILL_QA_RELEASE.md`

Do not solve vague requests such as “make it premium” directly. Convert them into observable behavior and binary acceptance criteria first.

## 3. Work in small verified changes

- One dominant functional outcome per PR.
- Inspect before editing.
- Reuse canonical services and permissions.
- No unrelated refactors.
- Do not install dependencies without justification.
- Do not change commercial rules to make tests pass.
- Do not claim tests that were not executed.
- Document changed files, tests, risks and rollback.

## 4. Architecture boundaries

- WooCommerce/Casa Viva Core remain the current authorities for orders and stock.
- Do not create parallel order, status, delivery, payout, identity or commission engines.
- Keep secrets, tokens and provider credentials out of client code and the repository.
- Minimize PII sent to external services.
- Casa Viva Network remains FUTURE unless a task explicitly changes that boundary.

## 5. Stack is surface-specific

Do not assume one stack applies to the entire repository.

- The canonical production operations/Messenger surface is WordPress/WooCommerce through `casa-viva-dropship-core`.
- Existing Next.js/App Router/TypeScript/Tailwind surfaces must keep their current stack when a task targets them.
- Do not rebuild a canonical WordPress capability in Next.js merely because a sandbox/visual prototype exists.

## 6. Experience rules

- Spanish is the default user-facing language.
- Mobile-first, responsive, accessible and usable on slow connections/devices.
- One screen, one dominant action.
- Role-specific operational tools must not look like the public store.
- Approved Casa Viva brand assets must be reused; do not redraw logos inside feature work.
- Copy is part of the contract. Do not silently invent product language when exact copy is required.

## 7. Definition of done

Use the evidence ladder from `docs/agent-system/SKILL_QA_RELEASE.md`:

`IMPLEMENTED -> CI_VERIFIED -> PRODUCTION_VERIFIED -> HUMAN_VALIDATED -> CLOSED`

Do not report `PILOT_READY` from CI alone.

## 8. Learn from failures

When a material defect, false-positive closeout or repeated prompting problem is discovered, record it in `docs/agent-system/LESSONS_LEDGER.md` and update the relevant reusable rule instead of making the next prompt longer.
