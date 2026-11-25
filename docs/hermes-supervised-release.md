# Hermes supervised releases

## Existing gate, not a second updater

Reuse scripts/ops/github_release_gate.py. It verifies protected current main, exact successful push workflows, fixed artifact, separate read-only credential, and content-addressed backup. The deployed bridge runs as root; changing its Python module is privileged code deployment, not a diagnostic. Do not admit release_apply into health_only or enable CV_GITHUB_RELEASE_ENABLED during this catalog installation.

Before remote releases can be enabled, CV-03 must verify actual VPS gate hash, credential scope without exposing it, actual required-review/ruleset settings (including bypass), exact workflows covering all catalog tests, and documented recovery from a failed import or lost heartbeat. A successful write is not a successful rollout. Require independent approval of the exact revision and an independently controlled rollback mechanism; do not let the updated module authorize its own replacement or alter the gate. Keep action opt-ins independent. No arbitrary command, branch, path, credential, or production deployment payload.

## PR294 certification

Stage the exact reviewed commit into a disposable checkout. Run hermes-offline.test.py, hermes-probe.test.py, github-contingency.test.py, hermes-catalog.test.py. Run the two pinned offline suites under the existing restricted systemd executor, no API calls. Verify static finance diagnostic returns only hashes and boolean markers, never raw content. Missing source or Git object blocks activation, not an invitation to read alternate files. Static markers are not evidence of Core/Caja connectivity, currency, payment, or settlement.

Install only github_contingency.py from the certified checkout, after checking the existing deployed SHA256. Preserve the previous version and existing systemd drop-ins. Enable only CV_GITHUB_HERMES_DIALOG_ENABLED and CV_GITHUB_HERMES_FIN_CONTRACT_ENABLED in a separate drop-in. Restart only cv-ops-bridge and restore module/drop-in on failed service check. Then certify remote health, old finance suite, dialogue suite and static finance diagnostic by correlated mailbox receipts. No real WhatsApp messages or payment mutations.

## Recovery priorities

P0: authenticated read-only Core/Caja contract and identity/currency reconciliation using existing connectors and minimal sanitized evidence; no inferred USD or payout. P1: conversation regression and CRM mirror diagnosis; no AI spend or customer-facing changes without separate authorization. A remote release mechanism remains blocked until its independent security checks pass.
