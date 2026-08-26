# Work Task Contract

Use this template before asking Work/Codex to implement a change.

## 1. TASK

One engineering task only. Give it a stable ID, e.g. `CV-MSG-001`.

## 2. WHY

Describe the observed user/business problem, not the proposed implementation.

## 3. CURRENT STATE

State what exists now and cite the canonical code/docs/PRs. Distinguish `IMPLEMENTED`, `PARTIAL`, `BROKEN`, and `UNKNOWN`.

## 4. TARGET BEHAVIOR

Describe the exact behavior after the change using observable inputs, states and outputs.

## 5. IN SCOPE

List the exact surfaces, components, endpoints or files that may change.

## 6. OUT OF SCOPE

List adjacent areas that must not change. A task is not permission to refactor unrelated code.

## 7. SOURCE OF TRUTH

Point to the minimum required repository documents. Always include the domain authority relevant to the task. Do not paste their full contents into the prompt.

## 8. IMPLEMENTATION CONSTRAINTS

Specify architecture, permissions, data ownership, privacy, compatibility, timeouts, idempotency, rollback or performance constraints that are non-negotiable.

## 9. UX CONTRACT

Define the flow, dominant action, loading/error/empty/success states, navigation entry/exit and mobile behavior. Do not use adjectives without measurable consequences.

## 10. UI CONTRACT

Name the component pattern, layout constraints, tap target sizes, breakpoints, safe areas, overflow rules and approved assets/tokens.

## 11. COPY CONTRACT

Provide exact approved strings. If copy is not approved, mark the missing strings as `COPY_REQUIRED`; do not let the coding agent invent product language silently.

## 12. ACCEPTANCE CRITERIA

Use binary checks. Example:

- `AC-01` A free-form NEXO question results in a server-side call to the canonical inference service.
- `AC-02` A regex-only/canned-response path cannot satisfy `AC-01`.
- `AC-03` A failed inference leaves the UI retryable and preserves the user input.

## 13. TEST MATRIX

List automated and manual scenarios, including failure modes. State which checks are unit/contract/integration/browser/production/manual.

## 14. DELIVERABLE

Require:

- one focused PR;
- changed files summary;
- tests run and tests not run;
- screenshots for visual work;
- rollback instructions;
- unresolved risks.

## 15. STOP CONDITION

The coding agent may report `IMPLEMENTED` or `CI_VERIFIED` when appropriate, but must not report `HUMAN_VALIDATED`, `CLOSED` or `PILOT_READY` without the required external evidence.

## Prompt hygiene

Prefer a short task contract linked to repository documentation over a long prompt that repeats the whole project. If a rule is reusable, update the relevant skill instead of adding more prose to the next prompt.
