# SEO OS — Durable Project State & Handoff

## CURRENT STATE — 2026-10-28: personal V1 slices (docs/DELIVERY_PLAN.md)

Supersedes the crawler-continuation section below. Scope is docs/OWNER_INTENT.md:
one owner, ordered slices 1–8; the archived master spec is never a source of scope.

- **Slice 1 — guided workflow + "what should I do next?": done.** PR #34 merged with
  expected head `ea46bc4` as `e5903dff27495cc831990004384cb35bf6af3519`. Exact-head PR
  checks (16) green; post-merge main green: CI `40713029777`, Update Rollback MariaDB
  `40713031140`, Lifecycle E2E `40713029415`. Overview steps are derived from real data;
  SEO plan downloads are recorded as `export.seo_plan` activity.
- **Slice 2 — pages workspace + on-page checklist: in review** on
  `feature/pages-workspace`, PR #35. Design note
  `docs/superpowers/specs/2026-10-28-pages-workspace-design.md`. Local evidence on
  PHP 8.4.16 + MariaDB 10.11: full suite 760 passed / 19 gated skips; Pint, PHPStan,
  npm check, tsc and vitest pass; every connected suite passes with `--fail-on-skipped`
  (installer 1, export 2, backup/restore 1, update migrations 9, snapshot isolation 1,
  crawl concurrency 3). Not done until exact-head CI on all three workflows is green,
  merged and post-merge main is green.
- Next after slice 2: slice 3 (own-site page check, reusing the crawler, bounded).

Local toolchain used in this session (the container is ephemeral; re-create it):
PHP 8.4 from the NixOS binary cache via `nix build --impure --expr` over
`channels.nixos.org/nixos-25.05/nixexprs.tar.xz` (`php84.withExtensions` + pdo_mysql,
intl, zip, bcmath, gd); Composer with `--prefer-source` because GitHub zipballs are
blocked (phpstan/phpstan needs a local `git archive` zip referenced from a scratch
lock copy that is never committed); MariaDB 10.11 from apt via `mysqld_safe`, users and
databases from the workflow env blocks. Run PHPStan with `--memory-limit=2G`.

## Earlier state — 2026-10-28: crawler continuation (superseded)

This checkpoint supersedes all older pause notes and CURRENT STATE sections below.
Live Git + exact-head CI remain authoritative. No force-push or stale branch deletion.

- PR #27 merged with expected-head protection (`511a1c0`) as
  `771d7cc3d007924479fcf0474ca18ca2e13f5364`. Independent main workflows passed:
  CI `40681906483`, Update Rollback MariaDB `40681906748`, Lifecycle E2E `40681906334`.
- PR #29 merged with expected-head protection (`c2973c3`) as
  `df7f87490c4c3de2e04e69b8fa255444ccd294e5`. Independent main workflows passed:
  CI `40682707635`, Update Rollback MariaDB `40682707688`, Lifecycle E2E `40682707657`.
  This is the verified main baseline. Persian framework/auth strings, offline-default
  password policy, upload-limit decision/warning and bilingual launcher pages are integrated.
- PR #28 remains draft/unmerged on `feature/site-crawler`. Its inherited failing
  `56d96ef` was reproduced and fixed at `314d0d1`; all exact-head PR workflows passed:
  CI `40682575360`, rollback `40682575358`, Lifecycle E2E `40682575345`.
- Current crawler implementation includes run serialization, checkpointed sitemap
  discovery, fail-closed forward-proxy handling, whole-chain network budgets
  (`d5c2c45`); bounded project-page linking without replacing human titles (`0e5ee07`);
  and authorized Persian-first crawl UI with polling/pagination/audit (`f5a14c4`).
  Current main was merged into the branch normally; no history was rewritten.
- Task 9 adds real MariaDB separate-process claim/kill/start concurrency tests to
  permanent CI. Local connected result: **3 passed / 27 assertions, zero skips**,
  invoked with `--fail-on-skipped`. Other local connected backup/migration/isolation
  suites: 11 passed / 206 assertions. Ordinary suite: 745 passed / 19 gated skips
  (5,221 assertions); Pint, PHPStan, TypeScript, npm check/build and frontend tests passed.
  These gated skips are not acceptance passes.
- **Not finished:** whole-branch focused review, browser crawl UI inspection, final
  exact-head three-workflow verification, PR #28 integration and post-merge main CI.
  Do not mark the crawler complete or advance to history/diff before that boundary.
- Next: finish those checks and fix real review findings with RED→GREEN. Keep the
  existing lifecycle acceptance and fail-closed guarantees intact. Then crawl
  history/diff (§17), issues (§18), architecture (§19), execution and performance
  per the master spec and delivery plan. No JS-rendering or issue-engine claim here.

Environment actually has PHP 8.4.24/Composer at `/workspace/.php-runtime/bin`;
activate that PATH. Existing MariaDB 10.11 containers `seo-connected-source` and
`seo-connected-destination` worked. Read workflow env blocks; never print secrets.
Local TCP tests and PHPStan need execution outside the socket-restricted sandbox.
Only the loopback-pinned `site.test` test host was excluded from proxy routing.
GitHub API works with normal configured proxy/credentials.

## Historical checkpoints (superseded where they conflict)

Last checkpoint: 2026-10-28

This file is the durable source of truth for continuing SEO OS across agent sessions. **Current Git heads + exact-head CI override stale chat, stale hashes and stale prose.** Never force-push over unexpected movement and never recreate a subsystem that is already verified.

## CURRENT STATE — 2026-10-28 (read this first; supersedes older lines below)

**Owner requested a clean pause and handoff. All implementation is committed and
pushed. Do not restart or re-audit completed slices. Fetch actual Git/CI first.**

### Integrated main: PR #21 is merged and verified

- Main merge commit: `f11f3bcb742d2a8cf105e75837aca08c55a006d7`.
- PR #21 final feature head: `9b60cf1bce86a36b21cbece0e97dd9cf51b23045`.
- Exact-head PR runs succeeded: CI `40653417461`, Update Rollback MariaDB
  `40653417463`, Lifecycle E2E `40653417465` (push runs also green).
- Merged through the GitHub API with the exact expected-head SHA parameter.
- Independent post-merge main runs all succeeded: CI `40654060956`,
  Update Rollback MariaDB `40654060905`, Lifecycle E2E `40654060872`.
- GitHub API access now works; the old 403 blocker is resolved.

Security work is integrated: missing/corrupt/symlinked activation state fails
closed, legitimate root activation/rollback writes an explicit pointer, and every
staging attempt extracts verified bytes into a fresh private slot. Launcher ABI is
`seo-launcher/2`; V1 installations require a trusted manual launcher upgrade.
Do not weaken the P1 guarantee or silently mix V1/V2 packages.

The connected failure at the older `085442a5` was reproduced on real MariaDB
10.11: the fixture created a retained target before source snapshot capture but
never activated root. Product activation was already correct. Commit `9b60cf1`
corrects that invalid fixture ordering and proves pointer existence/identity plus
fail-closed deletion after completed and rolled-back updates. All existing data,
schema, environment, identity and queue assertions remain.

### PR #22: import slice implemented; deliberately UNMERGED

- PR: https://github.com/arzmolaei/SEO/pull/22
- Branch: `feature/workspace-entry-keyword-import`, based on current main.
- Rebased implementation head: `988f5136b7a85086470c9aee63205922e2be7f6e`.
  Rebase push used an exact lease against `4a003de4d37e26595d6b868a0272899571d53244`.
  The rebased file tree was identical to the tested pre-rebase tree.
- Only four import/home commits remain above main; security/update commits are
  no longer duplicated in the PR diff.
- At the last API check on `988f513`: CI `40654733362` and rollback
  `40654733348` succeeded; Lifecycle E2E `40654733476` was still running.
- A documentation-only pause checkpoint is being added after that implementation
  head. **Verify CI on the actual final branch HEAD; do not substitute these
  older run results. Keep PR #22 unmerged unless the owner authorizes merging.**

Implemented and locally verified: Persian workspace home/project creation;
private CSV or pasted originals; bounded preview; exact-column mapping; queued
staging and commit; server-truth status; paginated invalid rows; attributable
discard; project-scoped binding/policies; Persian/English import page; home,
overview, keyword-page and sidebar entry points. Legacy JSON staging remains
compatible. Original bytes/whitespace remain separate from derived values;
preview rendering tolerates malformed row bytes; storage ancestors must be
canonical; raw paths/exception messages never enter page props.

Commit markers and the default database queue insert share a transaction.
Database reservations are at least 660 seconds for the existing 600-second jobs.
Caught staging errors are terminal with discard/reupload recovery; hard-killed
workers resume checkpoints. Focused review findings about header trimming,
malformed UTF-8 JSON and discard error rendering were corrected.

### Fresh local validation, including real connected tests

All requested connected suites ran against **real MariaDB 10.11** with
`--fail-on-skipped`, on both the update and import branches, with no skips:

- Rollback + fresh-runtime process recovery: 2 passed, 62 assertions.
- Backup/restore + update migrations + MariaDB-driver snapshot isolation:
  11 passed, 198 assertions.
- Self-host Installer: 1 passed, 125 assertions.
- Persian Export + large export: 2 passed, 110 assertions.

Normal update suite: 625 tests, 609 passed, 16 environment-gated skips,
4,633 assertions. Normal import suite: 645 tests, 629 passed, 16 gated skips,
4,826 assertions. Those ordinary-run skips are distinct from the connected
runs above; never present skipped connected tests as passed. Pint, PHPStan,
frontend check, TypeScript and production build passed on both branches.

The existing browser lifecycle driver also passed locally with Chrome + MariaDB
against a no-dev/classmap-authoritative packaged import runtime. **It still used
the old service seed; real-import UI end-to-end coverage is NOT implemented.**

### Exact first unfinished task: browser user-workflow acceptance

Continue from the import branch after checking its final exact-head CI. Keep #22
import-only/unmerged. Use a separate stacked feature branch for acceptance work.
A local `feature/lifecycle-user-workflow` name was briefly created at `988f513`,
but no browser code, design, or plan changes were written or pushed there.

Extend `tests/Acceptance/Lifecycle/run.mjs` and its existing driver/helpers:

1. Replace `harness.php seed` with browser project creation, CSV upload,
   mapping, real database-worker processing and commit.
2. Exercise intelligence through the UI (review/cluster/page mapping).
3. Download the real Persian CSV from the UI link and validate its content.
4. Download a sensitive backup, install a second clean instance through the
   browser, restore there, and verify data, source APP_KEY usability and retained
   destination database/installation identity.
5. Run the existing update/recovery scenarios on the restored instance and
   prove operational health after successful update, while retaining deliberate
   drift/fail-closed failure scenarios.
6. Review, run the real packaged Chrome/MariaDB acceptance, normal gates and
   exact-head CI; update these existing checkpoint files at the verified boundary.

Do not invent passing browser coverage, weaken assertions, merge temporary
branches, start crawler work, publish the environment, or redo integrated work.
Remaining broader release decisions (framework Persian strings, bilingual 503,
host upload limits/chunking, offline password checking and integrity vs signing)
remain as previously recorded; this session did not implement them.

### Runtime notes for a continuing agent

Use the existing isolated checkout, not a new worktree unless requested.
This cloud snapshot uses `export PATH=/workspace/.php-runtime/bin:$PATH`.
Real MariaDB containers were `seo-connected-source` (localhost:3306) and
`seo-connected-destination` (localhost:3307), image `mariadb:10.11`.
Users/databases and disposable credentials came from the workflow env blocks,
not production. Recreate them if processes/containers did not survive.
Local helper `/workspace/run-connected.py` runs groups rollback/backup/installer/
export and setup from the workflow values; it is outside Git and may not exist
in another task. The authoritative commands and env names are in `ci.yml` and
`update-rollback-mariadb.yml`. Never dump environment or credential values.
Local packaged tree `/workspace/lifecycle-runtime` and
`/workspace/lifecycle-baseline.log` are generated evidence, not Git sources.

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
