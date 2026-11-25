# CV-AUTO-001 — Installation gates (not an installer)

Do not enable release_apply or restart services from this document.

## Read-only preflight on the actual VPS

- systemctl show cv-ops-bridge.service with Type, User, Group, MainPID, ExecMainStartTimestampMonotonic, InvocationID, ActiveState, Result, NoNewPrivileges, ProtectSystem, ProtectHome, CapabilityBoundingSet.
- Verify the actual timer or scheduling mechanism, unit identity and ownership. Do not print environment variables or credentials.
- Verify filesystem ownership, modes, symlinks and effective access to the signing key and transaction journal. A root candidate defeats ordinary Unix user separation; do not install under that architecture.
- Verify the candidate cannot write the observer, verifier, approval, journal, backups or unit configuration, and cannot read the signing key. Check effective sandbox capabilities and inherited file descriptors.
- A process identity or signed candidate self-report alone is insufficient. Independently measure a benign synthetic request and response bound to the exact loaded revision and invocation, with no commercial effects.
- For oneshot units use independently witnessed invocation completion and a functional receipt; do not rely on MainPID after exit.

## Installation eligibility

Require exact reviewed revision and all CI checks, independent approval, protected branch, separate least-privilege identities, authenticated observer transport with bounded execution, verified rollback and interrupted recovery tests in a disposable environment. Keep the existing bridge and release opt-in unchanged until these gates are satisfied.

## Grouped installation order (only after gates)

Inventory and backup current files, units and hashes; stage exact reviewed files outside production; verify ownership and separation; validate configuration without starting units; test in isolation; independently approve the exact artifact; switch only the fixed target under transaction lock; observe the exact new invocation; rollback on independently confirmed failure; verify the restored digest and functional health. Preserve journal on unknown outcomes. No broad VPS restart, customer messages, payments or new credentials in chat.

Current blocker: the observer emitter and verifier are protocol primitives, not a certified production observation source. Existing mailbox health returns only active status; it cannot establish the actual unit type or effective isolation.

## Verified 2026-11-25: do not activate current release path

Hostinger shows both cv-ops-bridge and cv-dev-worker as Type=simple, User=root, ProtectHome=no, ProtectSystem=no, NoNewPrivileges=yes. Repository bridge imports Contingency in-process; a root candidate could bypass HMAC and modify the journal/approval. The public release gate therefore refuses all remote releases until an actual separate execution boundary exists. The private transaction primitive is retained for disposable tests only. Do not use it as an alternate entrypoint in production.

Reuse E2/E3/E4 runbook for Dev Worker; do not change current service users wholesale. Dedicated bwrap-dev and loaded enforcing profile must be checked, not inferred from /usr/bin/bwrap. Existing verify-sandbox.py --dev-worker is the harness; run only on a disposable workspace under cvdev after explicit E2 authorization. E3 must validate effective capabilities, cgroup limits, canaries and absence of production secrets. Candidate execution for release requires a separate unprivileged process with a minimal typed interface; privileged existing bridge operations must stay on trusted fixed code. The observer key, journal and approval must be outside candidate access, including via inherited descriptors and IPC. This architecture is not yet implemented or certified. Installation is blocked; no production activation or restart is authorized by this document.

## Disposable E3 pilot (no existing service changes)

Use only the already installed dedicated bwrap-dev/profile, a reviewed exact
checkout, and a newly created run- directory. Run the existing verify-sandbox.py
as cvdev within a transient systemd unit, with MemoryMax=256M, TasksMax=32,
CPUQuota=50%, RuntimeMaxSec=120s and KillMode=control-group. Never run as root
inside the sandbox. The trusted harness must be readable by cvdev but not
writable by it. Verify the live cgroup from the host while the unit runs;
use assess_cgroup_limits or the fixed candidate limits checker. An exited
unit or missing measurements are not a pass. Record actual uid, AppArmor
label and capability evidence, and all negative checks. Remove only the
new disposable workspace and transient unit after completion. Do not
change cv-dev-worker or cv-ops-bridge, their flags, profiles or credentials.

This pilot tests E3 only. The current health_summary candidate is advisory;
do not use a receipt from this pilot to confirm the production bridge.
The full contingency module is still privileged and its release entry
must remain blocked.

## E3 synthetic evidence — 2026-11-25

Revision `f76fb687eef1413aefc1697438bb961eb3f85996`: Hostinger pilot exit 0, limits OK, 7.058 s, reported memory peak 764 KiB. Visible checks confirm network denial, all capability sets zero and NoNewPrivileges, and exact `cv-release-pilot-bootstrap//&cv-release-pilot-candidate (enforce)` label. Overall verdict implies all ten checks passed. Original profile hash OK; temporary profiles removed. The synthetic Python prefix self-stacks before fixed test logic, not before interpreter startup. This is **not** certification of arbitrary candidate execution, production release, or independent functional health. Public candidate execution and release application must remain closed. A production observer needs independent evidence of the exact loaded revision and functional path; process active/status or a signed self-report is insufficient. Do not copy or install the pilot policies as production rules.
