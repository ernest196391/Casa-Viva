# Casa Viva Lessons Ledger

Use this as a reusable record of failures, corrections and commercial learning. Do not include secrets or customer PII.

## L-001 — “AI assistant” implemented as deterministic keyword routing

**Symptom**

The interface presented an assistant experience, but free-form questions were answered through local regex/keyword branches rather than a real inference request.

**Root cause**

The instruction described the desired experience (“assistant”, “NEXO sabe cosas”) without a binary technical requirement defining what qualifies as AI-backed behavior.

**Why previous verification missed it**

Tests verified that the launcher opened and returned contextual answers, not that a server-side inference call occurred.

**Correction**

Future NEXO tasks must include an acceptance criterion requiring a protected server-side call to the canonical inference service for free-form questions; canned/regex-only responses cannot satisfy it.

**Reusable rule**

Never use a product adjective/capability label as acceptance criteria. Define observable architecture and behavior.

**Business value**

Prevents delivering demos that look complete but do not provide the promised capability.

---

## L-002 — Green CI was treated as “pilot ready”

**Symptom**

A release was reported as ready while the real mobile interface still contained visual, navigation and usability defects.

**Root cause**

The status vocabulary did not distinguish implementation, automated verification, production verification and human validation.

**Why previous verification missed it**

Browser tests validated technical presence/overflow but not the intended human experience.

**Correction**

Use the evidence ladder defined in `SKILL_QA_RELEASE.md`. `PILOT_READY` requires closure of all blocking tasks including human validation where UX is part of scope.

**Reusable rule**

CI is evidence, not product approval.

**Business value**

Reduces false launches and costly rework after stakeholders inspect the real product.

---

## L-003 — Voucher analysis appeared frozen although NEXO had already answered

**Symptom**

The mobile UI remained in “Analizando…” and appeared frozen.

**Root cause**

Secondary catalogue and tariff enrichment were awaited before releasing the primary parse flow; those requests lacked independent bounded failure behavior.

**Why previous verification missed it**

The core parse path was tested without sufficiently slow downstream enrichment.

**Correction**

Show the parsed draft immediately, perform enrichment in background, bound each network dependency with independent timeouts and guarantee UI cleanup in all failure paths.

**Reusable rule**

Do not make a critical user-visible result wait for optional enrichment.

**Business value**

Improves perceived speed, resilience and usability on slow mobile networks.

---

## L-004 — Vague design instructions produced inconsistent screens

**Symptom**

Screens were individually “improved” but retained duplicate headings, inconsistent spacing, oversized cards, conflicting floating actions and mixed public-store/role navigation.

**Root cause**

Prompts used subjective instructions such as “premium”, “clean” or “simplify” without component and layout contracts.

**Correction**

Use `SKILL_UX.md` and `SKILL_UI.md` to define hierarchy, component patterns, tap targets, safe areas, breakpoints, approved copy and screenshots before implementation.

**Reusable rule**

Translate visual adjectives into measurable component constraints.

**Business value**

Makes design quality reproducible across agents and projects instead of depending on taste in each prompt.

## Template for new lesson

### L-XXX — Title

**Symptom**

**Root cause**

**Why previous verification missed it**

**Correction**

**Reusable rule**

**Business value**
