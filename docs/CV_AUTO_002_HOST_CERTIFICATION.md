# CV-AUTO-002 — reversible host certification procedure

**Do not apply SQL to production or create the certification marker.** The timer and unit are candidates, not installed by this procedure. Existing AppArmor profiles are reused, never replaced.

## Offline preparation
From a reviewed exact commit, run the existing stage-test-only-candidate.py into a new private directory under /tmp. Verify every manifest SHA256 against the reviewed commit independently. Run the staged github_test_only_host_preflight.py as a read-only audit. Require the existing E3 profiles in enforce, local Git objects, the dedicated bwrap, and unit verification. A passing audit is not runtime certification.

## Host pilot (separate authorization boundary)
Use a disposable systemd transient unit with the same bounded parent capabilities and NNP policy as the proposed unit, a fresh tmpfs workspace and no production credentials. Use the CV-AUTO-001 synthetic fixture and existing host observer; never run the production worker or claim from production DB. Confirm host PID identity, AppArmor stacked enforcement, UID, all five zero capability sets, NNP, cgroup and isolation. Verify cleanup and unchanged existing services. A test-only run must not be called a health attestation. If any prerequisite fails, stop and remove only the pilot resources.

## Future installation gate
Only after independent host certification, approved database migration, and the dependent PR #298: install the manifest-verified root-owned files with private permissions, then verify the exact service/timer definitions. No auto-start or enable. Keep the certification marker absent until explicit release approval. Do not change existing AppArmor profiles, Git receiver or legacy worker.

## Rollback
Never remove or overwrite an existing live unit or profile. For a future installation, stop only the new timer/service, remove the new certification marker and verify no new unit process remains. Restore any files from a verified backup if modified; remove only artifacts created by this installation. No database rollback is implied by removing files. Until installation occurs, rollback is simply removing the private staging directory.

## Hermes
Hermes must use the existing authorized owner mailbox and fixed test-only envelope, not a second command transport. Do not give Hermes a service-role key, shell endpoint or credentials for production. The terminal result is correlated by comment id and SHA; absence of a result is unknown, not success. Hermes integration remains unverified until its existing runtime and identity can be observed on the host.
