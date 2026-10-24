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
