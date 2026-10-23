# Update rollback queue retention correction

Base: active `feature/update-recovery-rollback@eea234dcbed29cf263ac794aab061f487a8ae5a6`, PR #13. This implements confirmed normative review Ruling 6: queued, not-running work survives update success/recovery. It does not implement the still-pending reservation admission/drain correction or recovery coordinator.

`ApplySystemStateSnapshot::handle(..., preserveQueuedWork: true)` explicitly retains the exact live `jobs`, `job_batches` and `failed_jobs` rows at both volatile cleanup sites. Existing ordinary Restore callers omit the new argument and keep the established clearing behavior. Cache/session/password-reset cleanup remains unchanged. The safety bundle/catalog remain unchanged; queue data is not exported or imported from another installation.

Tests pin complete queue payloads, attempts, scheduling timestamps, batch metadata/options and failure evidence under both policies, alongside ordinary volatile cleanup. Correct RED: `31b3b75d`, CI `40026309005`, one missing-policy failure with 487 passing tests. Current-head GREEN and independent review remain required.

The future source-booted Update recovery coordinator MUST pass `preserveQueuedWork: true` only after exact source code/schema recovery and proven quiescence under the shared LifecycleMutex. This policy alone does not stop workers or prove concurrency safety. Full Update/Rollback is not integrated or release-ready.
