# Casa Viva Agent Operating System

## Purpose

This directory turns informal product requests into small, verifiable engineering tasks without duplicating the functional source of truth already present in the repository.

It does **not** replace:

- `docs/CASA_VIVA_BLUEPRINT.md` for functional authority;
- `docs/CASA_VIVA_CURRENT_STATE.md` for current implementation status;
- domain documents such as `docs/MESSENGER_CENTER.md`;
- existing integration/release documentation.

It adds the missing operating layer for collaborating with coding agents such as Work/Codex.

## Core flow

`user intent -> product interpretation -> task contract -> implementation -> automated verification -> production evidence -> human validation -> closeout`

A green CI result is not equivalent to product approval.

## Skill map

Use only the skills required by the task:

- `SKILL_PRODUCT.md` — problem, actor, outcome, scope, priority and definition of done.
- `SKILL_UX.md` — flow, navigation, states, hierarchy and interaction rules.
- `SKILL_UI.md` — visual system, component constraints, responsive behavior and accessibility.
- `SKILL_COPY.md` — exact user-facing language and copy approval rules.
- `SKILL_ENGINEERING.md` — repository inspection, architecture, security and implementation constraints.
- `SKILL_QA_RELEASE.md` — test matrix, evidence, deployment and stop/go semantics.

## Required task contract

Every implementation request sent to a coding agent must be written using `WORK_TASK_CONTRACT.md`.

Do not send vague instructions such as:

- “make it premium”;
- “improve the UX”;
- “make the bot smarter”;
- “fix everything on this screen”.

Translate those requests into observable requirements, explicit scope and binary acceptance criteria first.

## Status vocabulary

Use these labels consistently:

- `DISCOVERED` — issue or opportunity observed.
- `SPECIFIED` — task contract completed.
- `IMPLEMENTED` — code exists.
- `CI_VERIFIED` — automated checks passed.
- `PRODUCTION_VERIFIED` — deployed behavior was checked in production.
- `HUMAN_VALIDATED` — intended user confirmed the experience.
- `CLOSED` — all required evidence exists.
- `PILOT_READY` — only after every blocking task in the selected pilot scope is `CLOSED`.

## Learning loop

Every material failure or false-positive closeout should be recorded in `LESSONS_LEDGER.md` using:

`symptom -> root cause -> why our previous instruction/test missed it -> correction -> reusable rule -> business value`

The reusable rule should update the relevant skill or contract so the same class of error is less likely to recur.

## Commercial reuse

`SERVICE_PLAYBOOK.md` explains how this operating system can later be packaged as a repeatable audit-and-delivery service for other businesses without exposing Casa Viva confidential data or internal commercial rules.
