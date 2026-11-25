# CRM recovery gate — Casa Viva

Evidence 2026-11-25: local 98,229 messages, 7,063 after 2026-11-19 22:43:06 UTC; remote 91,166 observed. Count agreement is not proof of identical IDs. No messages or IDs in GitHub.

1. Identify local and remote Casa Viva tenant separately, require exactly one slug match on each; compare identities and schema before mapping. Do not assume UUIDs agree.
2. Read-only reconciliation within authorized infrastructure: key is (business_id, wa_id). Verify nonempty IDs, duplicate counts, missing IDs in both directions and conflicting payloads, using stable bounded snapshot and counts only in reports. Confirm remote unique constraint, RLS and writer role. Preserve original sent_at and direction. Do not export message data to GitHub or logs.
3. Dry run: report eligible missing rows, conflicts and excluded rows, per batch; block on ambiguity, missing identity, unknown columns, or conflicts. No overwrite or deletion. Require independent review and explicit approval before writes.
4. Backfill after approval: use existing least-privilege writer, bounded batches, explicit column allowlist, INSERT ON CONFLICT (business_id, wa_id) DO NOTHING; no UPDATE, no payment or dispatch side effects. Verify actual trigger behavior first; never replay WhatsApp events or send customer messages. Use transaction per batch, durable checkpoint only after verified commit. Re-read on uncertain response before retry. Stop on quota/auth/schema errors; no busy retries or paid upgrades.
5. Verify exact key coverage in snapshot, compare conflict counts, confirm subsequent new messages synchronize. Repeat same batch must insert zero. Preserve local source; do not delete history. Only aggregated evidence in master register.

Caja: unauthenticated Render/Core probes both HTTP 401 on remote request 7171537302, confirming reachability and authentication challenge, not authenticated order availability. Existing device identity and read-only Core route remain mandatory. No test token issuance or secret transfer; use approved device for actual read, report only status and count.
