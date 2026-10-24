# Task 8 — exact-history migration runner

Base: `feature/update-recovery-rollback@eea234dcbed29cf263ac794aab061f487a8ae5a6` after reviewed Task 7 and Task 12 integration. Combined head `a9237ea7`, CI `40022550285`, passed all six gates: 486 tests (3710 assertions), connected MariaDB regressions and actual no-dev source -> private slot -> source runtime acceptance. Do not reimplement those boundaries.

## Interface rulings

- `UpdateMigrationState` is a strict versioned non-secret DTO: ordered `ran` rows (`migration`, `batch`), their deterministic SHA-256, a full structural schema checkpoint and an `applied_suffix` of completed target migrations with post-completion schema checkpoints. `toArray/fromArray` preserve exact evidence across process interruption.
- `UpdateMigrationRunner::snapshot()` reads the exact ordered migration repository and structural schema state. `runPending(UpdateMigrationState $before, callable $afterEachCompleted): UpdateMigrationState` rechecks the checkpoint before each file, runs only pending files using Laravel Migrator and publishes a fresh complete DTO after each logged completion.
- `rollbackExactSuffix(UpdateMigrationState $before, array $expectedAppliedSuffix): void` requires the full repository to equal the pre-state plus the exact supplied suffix AND the schema to equal the last known completed checkpoint. It passes only the one proven migration file to each reverse Migrator step, checking repository/schema after every down. A latest batch is never used as proof.
- Intermediate schema checkpoints must include all tables, indexes and foreign keys: source/target catalog-bound compatibility hashes alone cannot prove a partly applied new table. The runner keeps this internal structural hash separate from the existing Backup/source/target compatibility fingerprint; that public fingerprint and Backup semantics are unchanged.
- Unlogged DDL/schema drift, extra/missing/reordered migration history, missing/unsafe definitions or a failed/non-reversible down yields `update_recovery_required`; never guess another down or switch source code early.
- Preparation stores the exact pre-update DTO as well as its existing history digest. This closes the existing gap where only a hash was recorded and later recovery could not reconstruct its source prefix.
- Application coordinators own `LifecycleMutex`; the runner never recursively reacquires it. Target application/vendor migrations remain trusted release code and must be reversible, avoid external connections/side effects and be covered by connected MariaDB acceptance. No sandboxing of arbitrary PHP is implied.

## Status

Implementation/review in PR #12. Correct observed REDs: initial missing API CI `40023385360`; trigger/reverse completion regressions CI `40024824838` (2 failed, 491 passed); missing durable-attempt API CI `40025238250`; unpublished forward completion CI `40025795507` (1 failed, 495 passed). Final current-head CI remains required.

Review corrections implemented:

- SQLite fingerprints canonical `sqlite_master` definitions; MySQL/MariaDB hashes full table/view definitions and stable trigger metadata. CHECK constraints, engine/collation, view security/check options and quoted defaults are structural evidence. A quote-aware scanner removes only the mutable table-level AUTO_INCREMENT counter. Routines, events and non-table/view objects are unsupported and fail closed rather than being omitted.
- A private durable `migration-attempt.json` is atomically written before invoking any up/down. An unresolved attempt blocks retries even when history/schema look unchanged: data-only work may have committed. A thrown migration therefore conservatively requires recovery unless a fully completed checkpoint was written; unchanged DDL alone is not sufficient proof for automatic rollback. Task 9/10 must preserve this boundary, not silently retry or clear this marker.
- Completed markers contain exact before/after DTOs and direction. If publication was interrupted, forward continuation republishes the completed checkpoint before clearing it. Reverse continuation matches only a recorded remaining prefix, publishes each verified down and resumes without executing completed downs again.
- Permanent connected tests run after existing Backup/Restore acceptance in the same MariaDB job with `--fail-on-skipped`: reversible FK-ordered DDL, mutable-counter stability, unlogged trigger/CHECK/engine drift, quoted-default/view-check drift and separate PHP process exits during committed data-only up/down. This is focused Task 8 acceptance, not the still-required full Task 13 Update/Rollback gate. Tasks 9–11 and 13 remain incomplete; no full-product or lifecycle-release claim.


## Mandatory lifecycle acceptance corrections discovered during review

Before declaring Update/Rollback complete, fix these confirmed Important gaps in the existing lifecycle slice:

1. Ordinary HTTP requests already admitted before maintenance can still mutate after the safety snapshot. Add coordinated read/admission leases using the same lifecycle lock and prove drain with a paused real mutation process.
2. Laravel Worker reserves/runs before testing its restart timestamp; empty reserved-at polling plus queue restart has a race. Coordinate database queue reservation with the same lock/maintenance admission and prove an idle worker cannot reserve after quiescence while the update owns the lock.
3. `ApplySystemStateSnapshot` clears `jobs`/`job_batches`, while the Backup catalog excludes them. Update rollback needs an explicit queue-preserving policy; ordinary Restore behavior stays unchanged. Pin queued payloads and batch metadata across rollback (normative review Ruling 6).

These are targeted Task 6/10 acceptance corrections, not permission to redesign completed subsystems. The independent review used the exact Laravel v13.38.0 pinned in composer.lock; method signatures were cross-checked against official Laravel source.


## Verified code boundary

Independent review closed the reported Important findings. Code head `0729ad4fd5898cf53a1e5e8ac13c0be84905b388` passed all six permanent gates in CI `40026369422`: PHP 8.4/8.5 suites, frontend/TypeScript/build, Pint/PHPStan, dependency audits, connected Installer/Persian Export/Backup Restore, seven connected migration/DDL/process-exit cases and actual no-dev release-slot boot smoke. Final docs-head CI remains required before PR #12 integration into the active feature branch. Main is unchanged and full Update/Rollback remains incomplete.
