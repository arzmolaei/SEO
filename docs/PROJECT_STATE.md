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

The active branch already contains the approved Update/Rollback design, written review and 13-task plan. Tasks 1–6 exist, including the shared recovery kernel, package framing/verification, journal/maintenance, immutable staging and quiesced pre-update snapshot. Task 7's existing switch service is being completed through PR #11 with dependency-free bootstrap recovery and owner confirmation. Its intermediate head `662cac538c1e7d4470be6f9ee18453286163fcc7` passed all six permanent jobs in CI `40021758794` (478 backend tests on both PHP versions); the final real-route recovery-auth regression must also be green before integration.

Independent Task 12 package tooling is in PR #10. Head `8fd35c701c4f36e6690be4bb65568b67eee08e27` passed all six jobs in CI `40019999023` against a fixed baseline, including the actual no-dev runtime source -> private slot -> source smoke. Retargeted merged-tree CI against the active branch remains required. This tooling additionally rejects build-host cached config/route/event state and streams file inputs without holding the vendor tree open.

**Exact resume order:** verify/integrate PR #11 and PR #10 with current-head CI, then start **Task 8 — exact-history target migration runner**. Do not recreate Tasks 1–7 or package tooling from stale main prose. Update/Rollback still needs Tasks 8–11 and 13, final seven-job acceptance, reviewed integration and independent post-merge main CI. Final end-to-end lifecycle acceptance remains after that.

Progress: `docs/superpowers/progress/2026-10-23-update-bootstrap-recovery.md` and `docs/superpowers/progress/2026-10-24-update-package-builder.md`. Current Git/CI outrank these intermediate hashes.

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
