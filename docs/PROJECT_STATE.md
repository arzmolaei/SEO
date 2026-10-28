# SEO OS — Durable Project State & Handoff

Last checkpoint: 2026-10-24

This file is the durable source of truth for continuing SEO OS across agent sessions. **Current Git heads + exact-head CI override stale chat, stale hashes and stale prose.** Never force-push over unexpected movement and never recreate a subsystem that is already verified.

## CURRENT STATE — 2026-10-28 (read this first; supersedes older lines below)

Import UI follow-up (2026-10-28): the existing WIP scaffold is now connected to
scoped/authorized CSV-or-paste upload, bounded preview, exact column mapping,
database-queued staging and commit, paginated invalid rows, and attributable
discard. Persian/English UI, home/overview/keyword CTAs and sidebar entry are
implemented. The original JSON staging endpoint remains supported. Source bytes
stay private and unchanged; derived previews tolerate malformed row encoding,
filesystem ancestors must be canonical, and internal exception/path text is not
sent in props. Database queue insertion and request markers share one transaction;
the reservation interval is at least 660 seconds for 600-second import jobs.
Caught staging errors are terminal and recover by discard/reupload; killed-worker
retries resume checkpoints.

The existing seven flow tests are unchanged in intent and GREEN, with additional
tenant/provenance/queue-failure/encoding/reservation regressions. Independent
focused review findings (exact-header trimming, invalid-byte JSON rendering and
discard error display) were corrected. Local full suite: 645 tests, 629 passed,
16 skipped, 4,822 assertions; Pint/PHPStan/frontend check/TypeScript/build pass.
No MariaDB/browser acceptance pass is claimed locally. The original CI
40643318600 failure cause could not be independently inspected because API
access remains denied; the owner-supplied expected RED description is recorded
as such, not confirmed from logs.

First unverified boundary: exact-head CI for PR #21 security head
`085442a5f863581de671d52640b4ae2a86130521` and the new stacked import head.
Merge #21 only with exact-head protection after all three workflows pass, then
verify main. Validate the import head before integration; preserve its existing
branch history (no force-push). Next implementation slice is browser lifecycle
acceptance: replace service-level seed with the real import UI, then extend
intelligence → Persian export download validation → backup → second-instance
restore → update → operational health. That browser slice is not implemented
by this checkpoint. No crawler work should begin yet.

Security review follow-up (2026-10-28): the bounded independent Update/Rollback
review reproduced P1 missing-pointer root fallback and P2 retained-slot payload
reuse. Corrections and RED/GREEN/process coverage are recorded in
`docs/release-readiness/update-recovery-rollback.md`: explicit root pointers,
fail-closed missing/corrupt/symlinked activation state, installed-version guard,
fresh private slots on every stage attempt, and exclusive upload verification.
Launcher contract is now `seo-launcher/2`; V1 hosts require a trusted manual
launcher upgrade, not an automatic V1/V2 package transition. Local backend/PHP
gates passed; connected MariaDB tests skipped locally. **New-head CI and merge
remain unverified** because GitHub API access is denied. The owner will verify
the pushed head and merge PR #21 with expected-head protection if all three
workflows pass. Then verify main and validate the stacked import implementation;
do not recreate its completed home/project work.

Session paused by the owner; work is pushed. Verify with Git/CI before trusting this text.

**Branches (none merged to `main` yet; `main` is still `43342c6`):**

1. `feature/update-connected-acceptance` — ready for review/PR into `main`. Head CI: `CI` + `Update Rollback MariaDB` green; new permanent `Lifecycle E2E` workflow green at `042d5b3` (run 40641665038) and running on the latest head. Contains (each with RED regression test):
    - browser install HTTP 500 before DB exists (rate limiter bound to DB cache) — fixed;
    - fresh runtime defaulted to en/LTR — default locale `fa`;
    - installer step 6 refresh of cron/worker evidence;
    - `mariadb` driver unsupported by update fingerprint + export/backup REPEATABLE READ — fixed;
    - slot launcher redeclare fatal after release switch — fixed;
    - pre-switch failure left permanent maintenance — owner cancel (`CancelPendingUpdate`, `POST /settings/update/cancel`);
    - target migration failure/interruption with no DDL/history residue now rolls back (design §28); residue still `recovery_required`;
    - queue polling took the exclusive lifecycle lock (random 503s) — shared lease;
    - Persian host-upload-limit message on the update page;
    - `tests/Acceptance/Lifecycle/` dependency-free Chrome driver + `.github/workflows/lifecycle-e2e.yml`;
    - PR #18 checkpoint commits cherry-picked; PR #18 is superseded by this branch.
2. `feature/workspace-entry-keyword-import` — stacked on branch 1. Design: `docs/superpowers/specs/2026-10-28-workspace-entry-keyword-import-design.md`.
    - DONE + tested: Persian workspace home at `/dashboard` (`HomeController`, `resources/js/pages/home.tsx`), form-based project creation.
    - Implemented follow-up: upload/mapping/queued processing/commit/discard controller and routes, Persian/English import page, project navigation/CTAs, privacy/isolation/failure checks. Local gates GREEN; exact-head CI and browser import acceptance remain unverified (see current follow-up above).
    - NEXT: verify both new heads and main integration, then replace lifecycle service seeding with the real import UI and extend browser acceptance through intelligence/export/backup/restore/update/health.

**Open gaps:** new-head CI/merge for the recorded Update/Rollback review fixes; release signing (integrity only, not claimed); chunked upload for packages over host limits; no `lang/fa` (framework/auth strings English); launcher 503 page is bare English text.

**Lessons for local verification:** `api.github.com` zipballs may be blocked — build a local dist cache via shallow `git fetch` + `git archive`; set `COMPOSER_ALLOW_SUPERUSER=1`; serve with `php -S … -t public` (no router script); package uploads need `upload_max_filesize`/`post_max_size` ≥ package size.

## Stable integrated baseline

- Repository: `arzmolaei/SEO`.
- Stable branch: `main`.
- Foundation + Keyword Core: integrated through PR #1.
- Keyword Intelligence: integrated through PR #2.
- Self-host Installer: integrated through PR #4.
- Persian Export: integrated through PR #5; merge commit `a6924d77b3a9b7f74b484f7e303c8168905c520c`.
- Backup + Restore: integrated through PR #7; merge commit `761aede4927dd21ba6384b4d714cc80f2292019e`.
- Independent post-merge `main` CI for Backup + Restore: run `39978951778`, success across all six permanent jobs.
- Update + Interrupted Recovery/Rollback: integrated through PR #8; merge commit `43342c667859d68a2bfe62b1fb2258aafedb6a5c` (merged 2026-10-24T07:29:14Z; final feature head `dd69e765ef4d5dddb977d3595423cded6fe8f592`).
- Independent post-merge `main` CI for Update/Rollback: `CI` run `40074320817` success across all six `ci.yml` jobs, plus the separate permanent workflow `Update Rollback MariaDB` run `40074320854` success.
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

## Update + Interrupted Recovery/Rollback

Integrated through PR #8 (merge commit above). All thirteen plan tasks have code on `main`, and exact-head PR CI plus independent post-merge `main` CI are green (see baseline above).

Design: `docs/superpowers/specs/2026-10-23-update-recovery-rollback-design.md`  
Written-spec review: `docs/superpowers/specs/2026-10-23-update-recovery-rollback-review.md`  
Plan: `docs/superpowers/plans/2026-10-23-update-recovery-rollback.md`  
Release evidence and **known gaps**: `docs/release-readiness/update-recovery-rollback.md`  
Operator notes: `docs/update-recovery-operations.md`  
Package tooling: `docs/update-package-build.md`

Verified boundary: stable dependency-free launcher and atomic active-release pointer; `seo-update/1` package contract; durable journal and persistent maintenance; shared recovery kernel reused from Backup/Restore; owner-only verify/preview/confirm/continue/recover routes; quiescence and queue drain before the safety snapshot; immutable private release slots with additive public assets; exact-history migration runner with verified reverse suffix; target health validation and finalization (only `product_version` changes); interrupted-update rollback and source-snapshot recovery; Persian-first owner-only UI; package build tooling and runtime release-slot smoke; connected MariaDB exact-suffix rollback and separate-process recovery acceptance.

**Do not recreate Update/Rollback Tasks 1–13 or the verified recovery services.** Reuse the lifecycle primitives.

Progress documents (intermediate hashes; current Git/CI outrank them): `docs/superpowers/progress/2026-10-23-update-bootstrap-recovery.md`, `2026-10-24-update-package-builder.md`, `2026-10-23-update-migration-runner.md`, `2026-10-23-update-queue-retention.md`, `2026-10-23-update-admission-drain.md`, `2026-10-24-update-target-finalization.md`, `2026-10-24-update-recovery-coordinator.md`, `2026-10-24-update-rollback-history-proof.md`.

### Open items on the Update/Rollback boundary (not yet verified)

These are recorded in `docs/release-readiness/update-recovery-rollback.md`; do not mark Update/Rollback "fully release-ready" until they are closed or explicitly accepted:

1. No connected-MariaDB acceptance of the full **successful** update path (success is proven at SQLite feature level and by the release-slot smoke).
2. No connected-MariaDB acceptance for bootstrap failure and post-migration health failure; no whole-update connected case for hard interruption between target migration and rollback or for drift -> `recovery_required`.
3. No recorded whole-branch review artifact for the final launcher/secret/migration-guess/maintenance-bypass/stale-snapshot review.
4. No publisher signing: SHA-256 proves integrity only (**NEEDS VERIFICATION before public/commercial automatic distribution**).
5. The Update Rollback MariaDB gate is a separate workflow (`update-rollback-mariadb.yml`), not a job in `ci.yml`; require it explicitly in any branch protection.

Housekeeping: PR #3 (`feature/foundation-keyword-core` -> `main`) is still open although Foundation + Keyword Core was integrated through PR #1; the owner should decide whether to close it.

## Next: Final end-to-end lifecycle acceptance

This is the next slice. Close the open Update/Rollback items above where they overlap, then complete **Final end-to-end lifecycle acceptance**:

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
