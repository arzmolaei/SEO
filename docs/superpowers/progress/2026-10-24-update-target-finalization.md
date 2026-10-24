# Task 9 — target health and finalization

Base: active Update/Rollback branch at 1a016f93b6cb275cd3e95692f6d2a510ec7cdb52. Task 8 exact-history migrations, Task 12 package/runtime tooling, queue retention, and HTTP/worker admission corrections are reviewed/integrated. PR #14 final docs head cfc61c0 passed all six gates in CI40057827460; the connected log ran eight MariaDB cases, not skipped placeholders.

## Execution refinements within the approved Task 9 plan

- Capture non-secret SHA-256 evidence of source runtime APP_KEY, complete stable environment file, effective default DB configuration and immutable installation metadata before maintenance/snapshot. Metadata identity excludes only product_version so finalization can be resumed after that field was atomically updated; owner/install/database identity and restore provenance stay pinned.
- Target health checks the real booted base path against exact active slot identity/version/digest, DB connectivity/schema/catalog, source identity digests, persistent/runtime key agreement and encryption round-trip, stable storage binding/writability and target manifest/assets at both slot and stable public locations. Health reports contain stable codes only, never paths/configuration/raw exceptions.
- Journal checkpoint writes retain the current migration phase and enforce the same phase plus existing safe-evidence rules. Before invoking migrations persist migration_started and the exact source DTO; after each completion persist the full restartable DTO. Task 8's unresolved attempt marker remains authoritative: unchanged DDL/history cannot prove data-only work did not commit.
- Continue only on a fresh target-booted request under exclusive lifecycle ownership. Proven health failures become rollback_required. Ambiguous migration/DDL/evidence failures retain recovery_required and artifacts; no broad rollback or source switch here.
- After healthy validation, atomically update only installed product_version, write a private completion/retention record, complete the journal, remove the transient upload, then clear maintenance. Keep active/previous releases, source assets and immediate safety snapshot/metadata; V1 does not guess through pruning. Interrupted finalization must be retryable.
- Tests first, observed RED, implementation, current-head six-job CI and independent review. Full connected Update/Rollback acceptance remains Task 13; the existing focused MariaDB cases do not satisfy it.

## Current boundary

Starting source-identity capture tests. Target health/coordinator/controller continuation and Tasks 10–11/13 remain incomplete. Local execution environment disconnected; repository connector + exact-head CI remain available, and no local success is inferred.

## Review corrections and downstream acceptance boundaries

- Source identity RED c3dce4f / CI40058257165: four correct missing-evidence/API failures, 509 passing tests. Health RED59a286 / CI40058502209: sixteen missing validator failures, 513 passed. Cipher/legacy-ring RED190666 / CI40058942233: three reproduced false-positive health checks, 530 passed. Fixed by hashing effective prior keys and independently cross-decrypting current/legacy probes with the configured cipher and complete resolved key ring.
- Continuation/checkpoint RED6dfac5 / CI40059321960: ten correct missing API failures, 533 passed. The first implementation passed both PHP backend suites; PHPStan identified a redundant nullable access after an earlier guard, corrected from its exact diagnostic.
- Independent review requires pinning the exact captured safety bundle SHA-256 and validating its installation/owner identity, rather than accepting any valid same-version/schema bundle. It also requires a receipt-write failure after accepted health/version update to retain retryable validating state instead of entering recovery_required. Regression tests were committed before these corrections.
- Before full Task10 acceptance, close the independently confirmed shorter-prefix gap in rollbackExactSuffix: without a completed-attempt marker, deleted DATA-ONLY migration history must not be inferred as a completed down merely because schema is unchanged. Exact caller-recorded remaining state is required unless durable completion evidence proves reconciliation.
- Task10 must consume the exact safety digest, opt into queue preservation, and prove source encryption compatibility including legacy keys. Full cross-install Backup/Restore legacy-key behavior also needs coverage in final lifecycle acceptance; a healthy self-round-trip of only the active APP_KEY is insufficient evidence for older ciphertext.


## Verified Task 9 code boundary

Independent review closed the reported Important findings. Code head `efbd766c70a86ba285a755c86d5acfbc5de5c90e`, CI `40060127622`, passed all six permanent gates: 546 backend tests (4070 assertions), PHP 8.4/8.5, frontend/TypeScript/build, Pint/PHPStan, runtime dependency audits, existing connected regressions, eight focused MariaDB lifecycle cases and actual no-dev root -> private slot -> root smoke. Review regression RED45ff07 / CI40059975338 reproduced three snapshot/publication failures (543 passed) before the fixes. Final documentation-head CI remains required before PR15 integration.

Continuation services/health are implemented; browser continuation/recovery routes are connected with Tasks10/11. Task10's shorter-prefix proof correction is independently underway in PR16 with SQLite and connected MariaDB regressions. Full update recovery/UI/seventh acceptance gate and main integration remain incomplete.
