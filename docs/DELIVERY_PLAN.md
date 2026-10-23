# SEO OS delivery gates

This is the durable delivery map, not a claim that the whole product is implemented. The active plan, current Git heads and exact-head CI are authoritative.

## Continue autonomously

Complete one bounded task with RED/GREEN, relevant verification, semantic commit/push and exact-head permanent CI. Then continue to the next approved task without routine confirmation. Update `PROJECT_STATE` and handoff at subsystem boundaries. Never weaken gates or recreate a completed subsystem because chat context is stale.

## Integrated delivery slices

- Foundation + Keyword Core — integrated through PR #1.
- Keyword Intelligence — integrated through PR #2.
- Self-host Installer — integrated through PR #4 with permanent MariaDB 10.11 acceptance and runtime-only release smoke.
- Persian Export — integrated through PR #5 at merge commit `a6924d77b3a9b7f74b484f7e303c8168905c520c`, with exact `seo-plan/1` contract and permanent MariaDB 10.11 acceptance.

Current stable `main` at this checkpoint is `fabf2bf02d18c4e14f4aa97b6ab819da8620b174`; permanent `main` CI `39913391652` is green.

## First usable self-host release gate — active lifecycle phase

### Backup + Restore — implementation complete, integration boundary active

Active branch / PR: `feature/backup-restore` / PR #7.

Tasks 1–10 product implementation and permanent connected acceptance are complete. Exact implementation evidence before final documentation:

- head `35ee01a108ef5a9df31fce829378756e850e8565`;
- CI `39976140240`, success;
- six permanent green jobs: `verify`, PHP 8.4, PHP 8.5, Self-host Installer MariaDB, Persian Export MariaDB, Backup/Restore MariaDB.

The slice includes:

- stable ProductVersion + release-tree identity;
- system-owner identity and owner-only lifecycle access;
- shared lifecycle mutex + maintenance window;
- deterministic `seo-backup/1` bundle with strict bounds/path/checksum verification;
- explicit durable DB/settings/file scope and schema fingerprint;
- private full backup download;
- clean-target restore verification before mutation;
- destination DB identity/credentials preserved while source APP_KEY restores durable encrypted state;
- protected safety snapshot and fail-closed `recovery_required` after destructive interruption;
- exact recovery to pre-restore clean bootstrap state;
- Persian-first settings UI with digest confirmation and real server phases;
- connected MariaDB 10.11 full round-trip + interruption/recovery acceptance;
- runtime release-tree verification containing VERSION, lifecycle code, routes/controllers and built UI.

The feature branch has reconciled current `main` through merge commit `0f857c313dfe5256764bbd55f33e562e9576db66` while retaining the reviewed feature design required by its plan.

Backup + Restore is integrated only after:

1. final docs/release-readiness/operator notes are committed;
2. whole-branch review has no open Critical/Important finding;
3. fresh exact-head PR CI is green across all six jobs;
4. PR #7 merges with expected-head protection;
5. independent post-merge `main` CI is green;
6. `PROJECT_STATE` records the exact merged state.

### Remaining lifecycle work before first usable self-host release

1. **Update + Interrupted Recovery/Rollback** — compatibility checks, mandatory pre-update backup, migrations/code transition, health validation, interrupted/failed update recovery and rollback on real fixtures. It must reuse ProductVersion, LifecycleMutex and Backup/Restore safety primitives.
2. **Final end-to-end lifecycle acceptance** — clean runtime package -> browser install -> real project/import/intelligence workflow -> Persian Export -> Backup/Restore -> Update/Recovery/Rollback -> operational health.

Already satisfied before those two slices:

- runtime-only package and supported host preflight;
- browser installation, owner creation, installer lock and authenticated entry;
- real MariaDB 10.11 installer acceptance;
- complete keyword/import/intelligence core;
- Persian project handoff export;
- versioned full-instance backup, clean restore and interruption recovery implementation with connected acceptance.

Do not collapse Update/Rollback and final lifecycle acceptance into one oversized task. Each gets an explicit design/plan where required, RED contract, connected acceptance and exact-head CI.

## Subsequent full-product scope

Only after the first usable self-host lifecycle gate is verified should the product advance through the remaining master-spec phases:

Site Intelligence/crawler -> technical issues/history -> content/tasks/internal linking -> performance/opportunities/recommendations -> final operational release acceptance.

External APIs, CMS providers and AI remain optional integrations. Plans, entitlements and SaaS commercialization remain later scope and are not prerequisites for the offline-capable self-host core.
