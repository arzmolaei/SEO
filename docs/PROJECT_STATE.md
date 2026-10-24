# SEO OS — Durable Project State & Handoff

Last checkpoint: 2026-10-23

This file is the durable source of truth for continuing SEO OS across agent sessions. **Current Git heads + exact-head CI override stale chat, stale hashes and stale prose.** Never force-push over unexpected movement and never recreate a subsystem that is already verified.

## Stable integrated baseline

- Repository: `arzmolaei/SEO`.
- Stable branch: `main`.
- Foundation + Keyword Core: integrated through PR #1.
- Keyword Intelligence: integrated through PR #2.
- Self-host Installer: integrated through PR #4.
- Persian Export: integrated through PR #5; merge commit `a6924d77b3a9b7f74b484f7e303c8168905c520c`.
- Backup + Restore: integrated through PR #7; merge commit `761aede4927dd21ba6384b4d714cc80f2292019e`.
- Independent post-merge `main` CI for Backup + Restore: run `39978951778`, success across all six permanent jobs.
- Runtime floor: PHP `>= 8.4.1`; permanent compatibility gates remain PHP 8.4 and 8.5.

Always fetch actual `main`, any active feature branch/PR and current CI before changing code. Recorded hashes are evidence, not permission to assume Git has not moved.

## Integrated product slices

### Foundation + Keyword Core

Integrated. Laravel 13 + Inertia/React/TypeScript, Persian-first RTL/LTR, auth/onboarding, Workspace/Membership/Project isolation, Persian keyword normalization preserving original source text, resumable import, canonical keyword/provenance/metric history, server-side Keyword Grid, Saved Views, transactional bulk changes/audit/safe Undo and allowlist-first runtime packaging.

Evidence: `docs/release-readiness/foundation-keyword-core.md`.

### Keyword Intelligence

Integrated. Deterministic intent history/classification, project-scoped Review Queue, reversible clusters, manual merge/split/move/lock, existing/planned Pages, mapping history/conflict review, explainable local priority and connected Grid/Intent/Clusters/Mapping workspace.

Evidence: `docs/release-readiness/keyword-intelligence.md`.

### Self-host Installer

Integrated. Browser-driven offline-capable install with DB-independent preflight, protected persistent APP_KEY, MySQL/MariaDB verification, resumable migrations, first owner/workspace, scheduler/queue evidence, lock-last finalization, authenticated handoff and permanent MariaDB 10.11 acceptance.

Evidence: `docs/release-readiness/self-host-installer.md`.

### Persian Export

Integrated through PR #5. Versioned `seo-plan/1` CSV with exact Persian/ZWNJ preservation, spreadsheet-injection defense, project-scoped intelligence state, bounded generation, repeatable-read MariaDB semantics, strict export authorization and permanent connected MariaDB acceptance.

Evidence: `docs/release-readiness/persian-export.md`.

### Backup + Restore

Integrated through PR #7.

Design: `docs/superpowers/specs/2026-10-23-backup-restore-design.md`  
Written-spec review: `docs/superpowers/specs/2026-10-23-backup-restore-review.md`  
Plan: `docs/superpowers/plans/2026-10-23-backup-restore.md`  
Release evidence: `docs/release-readiness/backup-restore.md`  
Operator notes: `docs/backup-restore-operations.md`

Verified boundary:

1. stable distributable `VERSION` and release-tree identity;
2. persistent system-owner identity and safe installation metadata upgrades;
3. shared `LifecycleMutex` and `MaintenanceWindow` primitives;
4. explicit 19-table durable catalog, schema fingerprint, canonical manifest and lossless row codec;
5. deterministic bounded `seo-backup/1` bundle with strict path, size, duplicate, checksum and trailing-data rejection;
6. owner-only full backup download with no-store/private temporary storage;
7. private restore upload, full pre-mutation verification, clean-target gate and non-secret durable restore journal;
8. destructive restore preserving destination DB credentials/local installation identity while restoring source APP_KEY only with durable state;
9. fail-closed `recovery_required` plus protected safety-snapshot recovery after destructive interruption;
10. Persian-first owner-only settings UI with exact digest confirmation and real server phases;
11. permanent connected MariaDB 10.11 round-trip/recovery acceptance and release-tree verification.

Verification evidence:

- final documentation-complete feature head: `4c7b8e313902f3524b738634208a105b37b291ce`;
- exact-head PR CI run `39978231749`: success across all six permanent jobs;
- PR #7 merged with expected-head protection into `main` as `761aede4927dd21ba6384b4d714cc80f2292019e`;
- independent post-merge `main` CI run `39978951778`: success;
- post-merge jobs green: `verify`, PHP 8.4, PHP 8.5, Self-host Installer MariaDB, Persian Export MariaDB and Backup/Restore MariaDB.

**Do not restart or recreate Backup/Restore Tasks 1–10.** Any future work must reuse these lifecycle primitives.

## Active lifecycle slice — Update + Interrupted Recovery/Rollback

This is the active subsystem; it is **not integrated on main**. Implementation is in PR #8 on `feature/update-recovery-rollback`; the older main checkpoint must not be interpreted as permission to recreate it.

The active branch already contains the approved Update/Rollback design, written review and 13-task plan. Tasks 1–6 exist, including the shared recovery kernel, package framing/verification, journal/maintenance, immutable staging and quiesced pre-update snapshot. Task 7 was completed through PR #11 with dependency-free bootstrap recovery and owner confirmation, merged into the active feature branch at `f699d8363de91a419bb6d5de7e508fc8c6f8bec1`. Final reviewed head `a90e1c08214fa1fcd2def6cd26513d91b6f6433c` passed all six permanent jobs in CI `40022118065`, including 479 backend tests on both PHP versions and the real recovery-auth routes.

Task 12 package tooling was integrated through PR #10 at `eea234dcbed29cf263ac794aab061f487a8ae5a6`. Combined Task 7 + package tooling head `a9237ea77c4674549ac77907b89de4a8771821db` passed all six gates in CI `40022550285` (486 backend tests, 3710 assertions), including actual no-dev source -> private slot -> source runtime acceptance. The builder streams file inputs and rejects build-host config/route/event caches.

Task 8 exact-history migration runner is reviewed in PR #12. Code head `0729ad4fd5898cf53a1e5e8ac13c0be84905b388` passed all six permanent jobs in CI `40026369422`, including actual MariaDB DDL and separate-process data-only interruption acceptance. It stores strict source/checkpoint DTOs, proves exact reverse prefixes, fingerprints table/view/trigger definitions and retains durable in-progress/completed evidence. Ambiguous unlogged data or DDL is never retried or guessed through. Final documentation head `dfda6800a37168aa14152b00eb91aafdec612c3b` passed all six gates in CI `40026705128`; PR #12 merged into the active branch as `56045cfcb81ec0e7d63f2a3a8a18817c83c7944a`.

The confirmed queue-retention correction was independently reviewed in PR #13; `17662123b31a5f74b35b77bd51b175c99218d448` passed all six jobs in CI `40026436495`. Its explicit `preserveQueuedWork: true` snapshot policy retains pending jobs/batches/failure evidence; existing ordinary Restore defaults remain unchanged. Future Update recovery MUST opt in after proving source schema and quiescence. Combined migration + retention head `b3db55c6708bca7128597ccb2b740b4d664812cb` passed all six jobs in CI `40027046367`; PR #13 merged at `b9999976f5273342ecf233318a966635bd70eb6e`.

The admission/drain correction was reviewed in PR #14. Head `3b2864baa93ec0df1af89e1b9d1369839361ca2a` passed all six permanent jobs in CI `40057637639`, including real paused HTTP/worker interleavings, stale-source rejection after a completed pointer switch, and eight connected MariaDB DDL/data-interruption/reservation cases. Shared request leases and committed queue reservations use the existing LifecycleMutex; both ownership modes revalidate the booted release and queue reservations reject ambient transactions. Default pointers now bind to the stable installation root when running in a slot. Final docs head `cfc61c0015a0e412bfeb0343f79a249dc65189c2` passed all six gates in CI `40057827460`; PR #14 merged as `1a016f93b6cb275cd3e95692f6d2a510ec7cdb52`.

Task 9 target health/finalization is independently reviewed in PR #15. Code head `efbd766c70a86ba285a755c86d5acfbc5de5c90e` passed all six gates in CI `40060127622`: 546 backend tests (4070 assertions), eight connected MariaDB lifecycle cases and real no-dev release-slot runtime smoke. Source runtime/crypto/legacy-key/installation identity and exact safety-bundle bytes are pinned before migration. Target continuation requires exact booted release and original lock ownership, publishes restartable migration checkpoints, validates local health, atomically updates only product_version and preserves retryable finalization on publication failures. Active/source releases, source assets and immediate safety evidence remain retained. Final documentation-head CI/integration remain required.

**Current continuation in PR #17:** recovery coordinator service code `36cde2b55f84d69b595a488d92aafbd329b31e48` passed all six permanent CI jobs in run `40062215857`, including 563 backend tests on PHP8.4/8.5. It includes the independently reviewed PR16 history-proof correction. Source/target recovery requests are separated, exact migration suffixes/checkpoints and source snapshot identity are enforced, environment bytes/legacy keys/pending queue work are preserved, and recoverable publication/staging failures retain resumable phases. Fresh review findings were reproduced before correction. The service is not yet integrated into the parent feature branch.

**Exact resume order:** finish **Task11 — Persian UI and owner-only continuation/recovery HTTP routes** in PR17, then **Task13 — full connected Update/Rollback gate**, with real separately booted runtime requests. Update/Rollback remains unintegrated on main. Do not recreate Tasks1–9, package tooling, or the now-verified recovery services. Main still points to the older integrated Backup/Restore checkpoint; active PR/Git evidence is authoritative.
Progress: `docs/superpowers/progress/2026-10-23-update-bootstrap-recovery.md`, `docs/superpowers/progress/2026-10-24-update-package-builder.md`, `docs/superpowers/progress/2026-10-23-update-migration-runner.md` and `docs/superpowers/progress/2026-10-23-update-queue-retention.md` and `docs/superpowers/progress/2026-10-23-update-admission-drain.md` and `docs/superpowers/progress/2026-10-24-update-target-finalization.md`. Current Git/CI outrank these intermediate hashes.

Required architecture direction:

- reuse `ProductVersion` for release compatibility and installed-version identity;
- reuse `LifecycleMutex` for Backup/Restore/Update serialization;
- require a successful pre-update backup/safety snapshot before destructive update work;
- define explicit durable update journal phases and fail-closed interruption semantics;
- preserve destination/local installation identity and environment secrets;
- perform version/schema compatibility checks before mutation;
- perform code/release switch, migrations and health validation as explicit phases;
- rollback to the pre-update release/data state when health validation or an interrupted destructive phase cannot safely complete;
- prove success and recovery on real MariaDB fixtures and runtime-only release trees;
- remain browser-driven/offline-capable on ordinary self-host/shared-host environments without requiring Composer, Node/npm, SSH or shell access at runtime.

Because Update/Rollback is a new subsystem, follow the mandatory sequence before product code: existing-lifecycle audit -> brainstorming/architecture -> approved design -> written-spec review -> implementation-plan review -> RED/GREEN task execution.

## After Update/Rollback

Complete **Final end-to-end lifecycle acceptance**:

clean runtime package -> browser install -> real project/import/intelligence workflow -> Persian Export -> Backup/Restore -> Update/Recovery/Rollback -> operational health.

Only after that first usable self-host lifecycle gate is trustworthy should the product advance into Site Intelligence/crawler, technical issues/history, content/tasks/internal linking, performance/opportunities/recommendations and broader operational release scope.

## Self-host/offline invariants

- Core runtime/install/backup/restore/update must not depend on Composer, Node/npm, SSH, shell access, foreign APIs or international internet on the production host.
- Vazirmatn may be bundled locally under its open license. Proprietary font binaries remain excluded from public Git/release artifacts.
- Preserve original keyword text, provenance/metric history and explicit human intent/cluster/mapping decisions.
- Every project resource query/mutation/export remains project-scoped and authorized.
- `.env`, credentials, private fonts and local fixtures stay out of Git/release artifacts.
- Backup bundles are highly sensitive because they contain durable instance data and APP_KEY.
- Destination DB credentials, APP_URL, mail/storage/external secrets and local installation identity are never overwritten by restore/update recovery.
- Any future durable-file mutator must acquire the shared `LifecycleMutex`.

## Mandatory continuation protocol

1. Read `AGENTS.md`, this file, `docs/HANDOFF.md`, `docs/DELIVERY_PLAN.md` and the active subsystem design/plan.
2. Fetch current Git heads, PR state and exact-head CI before writes.
3. Use RED -> observed correct RED -> minimal GREEN -> focused verification -> semantic commit/push.
4. Diagnose failures before changing production code or tests.
5. Keep permanent CI gates intact; temporary diagnostics must not survive in the product.
6. Never force-push or infer success from stale/cancelled/pending runs.
7. Update durable state at verified subsystem boundaries.
8. Continue autonomously through approved plans without routine confirmation.
9. A genuinely new architecture follows brainstorming -> design approval -> written spec review -> implementation plan review before product code.

## Definition of trustworthy progress

Code existence is not completion. Applicable frontend check/TypeScript/build, Pint, PHPStan/Larastan, backend tests, runtime dependency audits, project isolation, connected MariaDB acceptance and release/runtime smoke must be green on the current head. Subsystem integration additionally requires its merge commit on `main` to receive independent permanent CI success.

Task10 progress: `docs/superpowers/progress/2026-10-24-update-recovery-coordinator.md`.
