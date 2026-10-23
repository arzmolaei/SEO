# SEO OS — Durable Project State & Handoff

Last checkpoint: 2026-10-23

This file is the durable source of truth for continuing SEO OS across agent sessions. **Current Git heads + exact-head CI override stale chat, stale hashes and stale prose.** Never force-push over unexpected movement and never recreate a subsystem that is already verified.

## Stable baseline

- Repository: `arzmolaei/SEO`.
- Stable branch: `main`.
- Current stable `main` at this checkpoint: `fabf2bf02d18c4e14f4aa97b6ab819da8620b174`.
- Current `main` CI: run `39913391652`, success.
- Foundation + Keyword Core: integrated through PR #1.
- Keyword Intelligence: integrated through PR #2.
- Self-host Installer: integrated through PR #4.
- Persian Export: integrated through PR #5; merge commit `a6924d77b3a9b7f74b484f7e303c8168905c520c`.
- Active integration branch: `feature/backup-restore`, PR #7.
- Backup/Restore implementation head before final documentation: `35ee01a108ef5a9df31fce829378756e850e8565`.
- Permanent implementation CI on that exact head: run `39976140240`, success across all six jobs, including the dedicated `backup-restore-mariadb` gate.
- `main` reconciliation commit on the feature branch: `0f857c313dfe5256764bbd55f33e562e9576db66`; it makes `main` an ancestor while deliberately retaining the reviewed Backup/Restore design required by the implementation plan.
- Runtime floor: PHP `>= 8.4.1`; permanent compatibility gates remain PHP 8.4 and 8.5.

Always fetch the actual `main`, active branch head, PR status and latest CI before changing code. Recorded hashes are evidence, not permission to assume Git has not moved.

## Completed integrated product slices

### Foundation + Keyword Core

Integrated. Includes Laravel 13 + Inertia/React/TypeScript, Persian-first RTL/LTR, auth/onboarding, Workspace/Membership/Project isolation, Persian keyword normalization preserving original source text, resumable import, canonical keyword/provenance/metric history, server-side Keyword Grid, Saved Views, transactional bulk changes/audit/safe Undo and allowlist-first runtime packaging.

Evidence: `docs/release-readiness/foundation-keyword-core.md`.

### Keyword Intelligence

Integrated. Includes deterministic intent history/classification, project-scoped Review Queue, relational/reversible clusters, manual merge/split/move/lock, existing/planned Pages, mapping history/conflict review, explainable local priority and connected Grid/Intent/Clusters/Mapping workspace.

Evidence: `docs/release-readiness/keyword-intelligence.md`.

### Self-host Installer

Integrated. Provides browser-driven offline-capable installation with DB-independent preflight, protected persistent APP_KEY, MySQL/MariaDB verification, resumable migrations, first owner/workspace, scheduler/queue evidence, lock-last finalization, authenticated handoff and permanent MariaDB 10.11 acceptance.

Evidence: `docs/release-readiness/self-host-installer.md`.

### Persian Export

Integrated through PR #5. Provides the versioned `seo-plan/1` CSV contract with exact Persian/ZWNJ preservation, spreadsheet injection defense, project-scoped intelligence state, bounded generation, repeatable-read MariaDB snapshot semantics, strict export authorization and permanent connected MariaDB acceptance.

Evidence: `docs/release-readiness/persian-export.md`.

## Active slice — Backup + Restore

Design: `docs/superpowers/specs/2026-10-23-backup-restore-design.md`  
Written-spec review: `docs/superpowers/specs/2026-10-23-backup-restore-review.md`  
Plan: `docs/superpowers/plans/2026-10-23-backup-restore.md`  
Release evidence: `docs/release-readiness/backup-restore.md`  
Operator notes: `docs/backup-restore-operations.md`

Tasks 1–10 implementation is complete. The slice is at the final documentation / exact-head CI / merge / post-merge verification boundary.

Implemented boundary:

1. stable distributable `VERSION` and release-tree identity;
2. persistent system-owner identity and safe installation metadata upgrades;
3. shared lifecycle mutex and maintenance window primitives for Backup/Restore and future Update/Rollback;
4. explicit 19-table durable catalog, schema fingerprint, canonical manifest and lossless row codec;
5. deterministic bounded `seo-backup/1` binary bundle reader/writer with strict path, size, duplicate, checksum and trailing-data rejection;
6. owner-only full backup download with no-store headers and private temporary storage;
7. private restore upload, full pre-mutation verification, clean-target gate and non-secret durable restore journal;
8. destructive restore with destination DB identity preserved, source APP_KEY applied only with restored durable state, volatile state cleared and protected safety-snapshot recovery after post-commit interruption;
9. Persian-first owner-only settings UI with verified digest confirmation, real server phases and explicit recovery-required handling;
10. permanent connected MariaDB 10.11 backup/restore/recovery acceptance plus release-tree verification.

Exact implementation evidence:

- feature implementation head: `35ee01a108ef5a9df31fce829378756e850e8565`;
- permanent CI run `39976140240`: success;
- jobs green: `verify`, PHP 8.4, PHP 8.5, self-host Installer MariaDB, Persian Export MariaDB and Backup/Restore MariaDB;
- `verify` includes frontend check, TypeScript, production build, Pint, PHPStan/Larastan, backend tests, dependency audits, runtime-only Composer install and production release-tree smoke;
- connected Backup/Restore gate proves separate source/destination MariaDB databases, Persian + ZWNJ domain data, owner/password/passkey/membership state, upload bytes, destination DB credential/installation identity preservation, source APP_KEY usability, volatile-state exclusion, second-restore rejection, injected post-DB-commit failure, `recovery_required`, exact clean-state recovery and lifecycle artifact cleanup.

The branch was one commit behind `main` because `main` intentionally removed an early design draft. Commit `0f857c313dfe5256764bbd55f33e562e9576db66` reconciles `main` into the feature branch while retaining the reviewed final design required by the feature plan. Any newer documentation-complete head must receive fresh permanent PR CI before merge.

## Merge boundary for PR #7

Backup + Restore is not integrated merely because implementation CI is green. Before calling it integrated:

1. final documentation/release evidence is committed;
2. whole-branch review has no open Critical/Important finding;
3. exact current PR head CI is green across all permanent jobs;
4. PR #7 is merged with expected-head protection;
5. the resulting `main` merge commit receives independent permanent CI success;
6. this state file is updated on `main` with the merged SHA and post-merge CI evidence.

## Exact next lifecycle work

After PR #7 is merged and post-merge `main` CI is green, start **Update + Interrupted Recovery/Rollback** as the next separate subsystem. Reuse `ProductVersion`, `LifecycleMutex`, backup capture/bundle, safety snapshot and recovery primitives; do not build a parallel lifecycle stack.

Then complete **Final end-to-end lifecycle acceptance**:

clean runtime package -> browser install -> real project/import/intelligence workflow -> Persian Export -> Backup/Restore -> Update/Recovery/Rollback -> operational health.

Only after that first usable self-host lifecycle gate is trustworthy should the product advance into Site Intelligence/crawler, technical issues/history, content/tasks/internal linking, performance/opportunities/recommendations and broader operational release scope.

## Self-host/offline invariants

- Core runtime/install/backup/restore/update must not depend on Composer, Node/npm, SSH, shell access, foreign APIs or international internet on the production host.
- Vazirmatn may be bundled locally under its open license. Proprietary font binaries remain excluded from public Git/release artifacts.
- Preserve original keyword text, provenance/metric history and explicit human intent/cluster/mapping decisions.
- Every project resource query/mutation/export remains project-scoped and authorized.
- `.env`, credentials, private fonts and local fixtures stay out of Git/release artifacts.
- Backup bundles are highly sensitive because they contain durable instance data and APP_KEY; they must never be uploaded to public storage, email or support tickets.
- Destination DB credentials, APP_URL, mail/storage/external secrets and local installation identity are never overwritten by restore.
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
9. A genuinely new architecture still follows brainstorming -> design approval -> written spec review -> implementation plan review.

## Definition of trustworthy progress

Code existence is not completion. Applicable frontend check/TypeScript/build, Pint, PHPStan/Larastan, backend tests, runtime dependency audits, project isolation, connected MariaDB acceptance and release/runtime smoke must be green on the current head. Subsystem integration additionally requires the merge commit on `main` to receive its own permanent CI success.
