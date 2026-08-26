# Skill — Casa Viva Copy

## Job

Define concise, role-appropriate product language before engineering hard-codes it.

## Rules

- Spanish is the default user-facing language.
- One idea per sentence.
- Prefer verbs and concrete outcomes over administrative wording.
- Remove duplicated headings and explanatory text that does not change a decision.
- Error messages must state what happened, whether data was preserved and what the user can do next.
- Do not expose implementation jargon, stack traces, API names or internal state labels to end users.
- The coding agent must use approved strings from the task contract. If a required string is not approved, mark it `COPY_REQUIRED` instead of silently inventing product language.

## Role tone

- Customer: clear, reassuring, transactional.
- Messenger: brief, operational, action-first.
- Clerk/operations: precise, compact, exception-focused.
- Manager/gestora: commercial but concrete; avoid inflated marketing copy inside operational flows.

## NEXO

Brand anchor: `NEXO sabe cosas`.

Do not imply AI capabilities that are not implemented. A deterministic FAQ/keyword router must not be described as a free-form AI assistant.

## Output

Produce the `COPY CONTRACT` section of `WORK_TASK_CONTRACT.md` with exact strings or `COPY_REQUIRED` markers.
