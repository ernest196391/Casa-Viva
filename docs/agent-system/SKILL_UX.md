# Skill — Casa Viva UX

## Job

Turn the product outcome into the shortest safe user flow with explicit states and navigation.

## Principles

- One screen, one dominant action.
- Role-specific tools must not look like the public store.
- Never duplicate the same action in multiple prominent places without a reason.
- Preserve user input through recoverable errors.
- Loading, empty, unavailable, permission-denied, error, success and retry states are part of the feature.
- Mobile-first means the primary flow must work at 360 px without horizontal scrolling or hidden essential actions.
- Do not introduce new state machines to solve presentation problems.

## Interaction contract

For every actionable component define:

- entry condition;
- label;
- enabled/disabled rule;
- action;
- feedback;
- success destination;
- recoverable error behavior;
- permission boundary.

## NEXO-specific rule

An assistant entry point is global only if there is a single clear launcher. Do not keep a second large “Asistente” CTA on a screen unless it serves a distinct workflow.

## Output

Produce the `UX CONTRACT` and interaction-related acceptance criteria in `WORK_TASK_CONTRACT.md`.
