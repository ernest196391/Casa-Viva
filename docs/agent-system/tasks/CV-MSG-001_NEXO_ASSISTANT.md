# CV-MSG-001 — NEXO assistant must be a real, usable Messenger assistant

## 1. TASK

Replace the current Messenger “assistant-like” deterministic experience with a protected, real NEXO inference path and a mobile interaction that is ready for human validation.

Priority: `P0` capability truthfulness + `P1` Messenger UX.

## 2. WHY

Human production review found that the floating assistant opens but does not provide the promised free-form AI behavior and its current visual treatment is intrusive. The current client script can answer through deterministic keyword/regex routing, which does not satisfy the product promise `NEXO sabe cosas`.

## 3. CURRENT STATE

At task start, inspect current `main` and recent PRs #111–#113.

Known current implementation surfaces include:

- `wordpress/casa-viva-dropship-core/includes/class-cvd-contextual-assistant.php`
- `wordpress/casa-viva-dropship-core/assets/contextual-assistant.js`
- `wordpress/casa-viva-dropship-core/assets/contextual-assistant.css`
- Messenger assistant/route code referenced by `docs/MESSENGER_CENTER.md`

PR #111 improved voucher parse resilience. PRs #112–#113 fixed assistant initialization/render ordering. Those fixes must not be regressed.

## 4. TARGET BEHAVIOR

For an authenticated messenger:

`tap NEXO launcher -> open Messenger NEXO panel -> enter free-form question -> protected server-side request -> canonical NEXO inference service -> answer rendered -> user can ask again`

The assistant may use only authorized Messenger context. It must not mutate orders or operational state.

If NEXO is unavailable, the panel remains usable and explicitly reports an unavailable/retry state.

## 5. IN SCOPE

- Messenger NEXO launcher behavior.
- Messenger NEXO panel/sheet.
- Protected WordPress server-side endpoint/service needed to call canonical NEXO inference.
- Minimal authorized context assembly for Messenger questions.
- Failure/loading/retry behavior.
- Tests for inference vs deterministic fallback, privacy and mobile interaction.

## 6. OUT OF SCOPE

- Voucher intake form redesign.
- Route workflow redesign.
- Money/liquidations redesign.
- Profile/account redesign.
- Gestora UI redesign.
- Public customer/store assistant redesign except shared code required not to break it.
- New order/logistics state machine.
- Casa Viva Network.

## 7. SOURCE OF TRUTH

Read before implementation:

- `AGENTS.md`
- `docs/CASA_VIVA_BLUEPRINT.md`
- `docs/CASA_VIVA_CURRENT_STATE.md`
- `docs/MESSENGER_CENTER.md`
- `docs/agent-system/README.md`
- `docs/agent-system/SKILL_UX.md`
- `docs/agent-system/SKILL_UI.md`
- `docs/agent-system/SKILL_COPY.md`
- `docs/agent-system/SKILL_ENGINEERING.md`
- `docs/agent-system/SKILL_QA_RELEASE.md`

## 8. IMPLEMENTATION CONSTRAINTS

### NEXO-AI-001

A user-entered free-form question **must** result in a server-side request to the canonical NEXO inference service.

A response generated exclusively through local regex matching, keyword routing, canned responses or deterministic lookup tables **must not** satisfy this requirement.

### NEXO-AI-002

Provider/API credentials must remain server-side and must never be localized into browser JavaScript or committed to the repository.

### NEXO-AI-003

NEXO cannot directly mutate orders, delivery state, payment state, commission or payout.

### NEXO-AI-004

Context sent to inference must be the minimum necessary and limited to the authenticated user's authorized scope. Document the payload fields. Do not send unrelated PII.

### NEXO-AI-005

Network/inference operations require bounded timeout behavior. Failure must preserve the user's question and permit retry.

### NEXO-AI-006

Do not regress voucher analysis timeouts/background enrichment introduced in PR #111 or render/cache fixes introduced in #112–#113.

## 9. UX CONTRACT

Messenger has one global NEXO entry point. Remove/refrain from adding a second prominent `Asistente` CTA inside `Hoy` as part of this task only if that duplicate points to the same assistant and can be removed without touching unrelated layout logic.

Interaction states:

- `closed`
- `open_idle`
- `submitting`
- `answered`
- `recoverable_error`
- `service_unavailable`

During errors:

- keep the typed question;
- re-enable submit;
- never leave an indefinite spinner;
- never silently substitute a canned answer and present it as AI output.

## 10. UI CONTRACT

Use a mobile bottom-sheet pattern for Messenger NEXO rather than the current large floating modal when feasible within the existing component architecture.

Constraints:

- mobile baseline: 360 px;
- launcher tap target: >= 48 px;
- no Unicode robot emoji as final launcher identity;
- use an approved/canonical NEXO or assistant icon asset if available; if missing, report `ASSET_REQUIRED` rather than drawing a new brand mark;
- respect safe-area inset;
- minimum 12 px visual separation from WhatsApp;
- launcher/sheet must not cover bottom navigation or primary CTA;
- quick prompts, if retained, use compact chips rather than four large grid buttons;
- conversation area scrolls; composer remains reachable with mobile keyboard open;
- no horizontal overflow.

## 11. COPY CONTRACT

Exact Messenger copy:

- title: `NEXO`
- brand line: `NEXO sabe cosas`
- description: `Pregunta sobre tus entregas, cobros, rutas y vales.`
- input placeholder: `Pregúntale a NEXO…`
- submit: `Enviar`
- unavailable: `NEXO no está disponible ahora. Intenta de nuevo.`
- recoverable error: `No pude responder. Tu pregunta sigue aquí. Intenta de nuevo.`

Suggested quick prompts:

- `Qué me falta`
- `Cuánto cobro`
- `Próxima entrega`
- `Vuelto`

Do not use the Messenger panel copy `Casa Viva / ¿En qué te ayudo? / Mi pedido / Cómo pagar / Mensajería / Necesito ayuda`.

## 12. ACCEPTANCE CRITERIA

- `AC-01` Free-form input triggers a protected server-side call to canonical NEXO inference.
- `AC-02` Removing/disablement of the inference service causes explicit unavailable/error state; local keyword matching cannot make `AC-01` pass.
- `AC-03` No provider credential appears in client assets or repository content.
- `AC-04` Assistant cannot mutate order/delivery/payment/commission state.
- `AC-05` A timeout/network/5xx failure preserves input and restores a retryable UI.
- `AC-06` At 360×740, launcher and sheet do not overlap WhatsApp, bottom nav or primary controls.
- `AC-07` The production Messenger panel uses the approved copy above.
- `AC-08` No Unicode robot emoji is used as the final production launcher.
- `AC-09` Existing voucher-analysis resilience and assistant render-order regressions remain green.
- `AC-10` Human mobile validation is required before status can become `CLOSED`.

## 13. TEST MATRIX

Automated:

- real server-side inference request contract;
- inference service unavailable;
- timeout;
- 500/503;
- malformed provider response;
- repeated submit/double tap;
- authorization/role boundary;
- no secret in client assets;
- mobile browser 360×740;
- keyboard/composer accessibility;
- no horizontal overflow/collision;
- regressions for PRs #111–#113.

Manual production validation:

1. open Messenger on target phone;
2. open NEXO;
3. ask a free-form question not represented by a quick chip;
4. verify useful response;
5. test unavailable/retry behavior if safely simulatable;
6. confirm launcher/sheet positioning with WhatsApp and bottom nav;
7. approve/reject visual experience.

## 14. DELIVERABLE

One focused PR containing:

- implementation;
- exact endpoint/inference architecture summary;
- allowed context payload documentation;
- automated tests;
- 360 px screenshots;
- tests run / not run;
- rollback;
- unresolved risks.

## 15. STOP CONDITION

Work/Codex may report `IMPLEMENTED`, `CI_VERIFIED` and, after exact SHA deployment, `PRODUCTION_VERIFIED`.

It must stop at:

`PRODUCTION_VERIFIED — HUMAN VALIDATION PENDING`

until the user validates the real mobile experience. Only then may this task become `CLOSED`.
