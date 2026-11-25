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
