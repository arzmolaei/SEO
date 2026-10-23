# SEO OS delivery gates

This is the durable delivery map, not a claim that the whole product is implemented. The active plan, current Git heads and exact-head CI are authoritative.

## Continue autonomously

Complete one bounded task with RED/GREEN, relevant verification, semantic commit/push and exact-head permanent CI. Then continue to the next approved task without routine confirmation. Update `PROJECT_STATE` and handoff at subsystem boundaries. Never weaken gates or recreate a completed subsystem because chat context is stale.

## Integrated delivery slices

- Foundation + Keyword Core — integrated through PR #1.
- Keyword Intelligence — integrated through PR #2.
- Self-host Installer — integrated through PR #4 with permanent MariaDB 10.11 acceptance and runtime-only release smoke.
- Persian Export — integrated through PR #5 at merge commit `a6924d77b3a9b7f74b484f7e303c8168905c520c`, with exact `seo-plan/1` contract and permanent MariaDB 10.11 acceptance.
- Backup + Restore — integrated through PR #7 at merge commit `761aede4927dd21ba6384b4d714cc80f2292019e`, with permanent connected MariaDB 10.11 round-trip/recovery acceptance and runtime release-tree verification.

Backup + Restore post-merge `main` CI run `39978951778` is green across all six permanent jobs: `verify`, PHP 8.4, PHP 8.5, Self-host Installer MariaDB, Persian Export MariaDB and Backup/Restore MariaDB.

## First usable self-host release gate — active lifecycle phase

### Completed: Backup + Restore

Integrated capabilities include:

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

Do not rebuild this slice. Reuse its lifecycle primitives for Update/Rollback.

### Active next slice: Update + Interrupted Recovery/Rollback

This is the next separate subsystem and must not be collapsed with final end-to-end acceptance.

Required delivery boundary:

1. explicit ProductVersion/source-target compatibility rules;
2. mandatory verified pre-update backup/safety state before destructive work;
3. shared `LifecycleMutex` serialization with Backup/Restore;
4. browser-driven/offline-capable release staging and switch suitable for packaged/shared-host runtime;
5. migration execution with explicit compatibility and ordering semantics;
6. durable update journal with interruption-safe phases;
7. post-switch application/database health validation;
8. safe rollback/recovery behavior for pre-switch, migration, post-switch and health-check failures;
9. preservation of destination-local secrets and installation identity;
10. Persian-first operator status/recovery UI without fake progress or secret leakage;
11. permanent connected MariaDB acceptance covering successful update plus interruption/failure recovery/rollback;
12. runtime-only release-tree acceptance with no production-host Composer/Node/npm/SSH/shell dependency;
13. exact-head PR CI, expected-head merge and independent post-merge `main` CI.

Because this is a new architecture-bearing subsystem, follow the required flow: existing-system audit -> brainstorming/architecture -> approved design -> written-spec review -> implementation-plan review -> RED/GREEN execution.

### Remaining lifecycle work after Update/Rollback

**Final end-to-end lifecycle acceptance** — clean runtime package -> browser install -> real project/import/intelligence workflow -> Persian Export -> Backup/Restore -> Update/Recovery/Rollback -> operational health.

Already satisfied before that final slice:

- runtime-only package and supported host preflight;
- browser installation, owner creation, installer lock and authenticated entry;
- real MariaDB 10.11 installer acceptance;
- complete keyword/import/intelligence core;
- Persian project handoff export;
- versioned full-instance backup, clean restore and interruption recovery with connected acceptance.

## Subsequent full-product scope

Only after the first usable self-host lifecycle gate is verified should the product advance through the remaining master-spec phases:

Site Intelligence/crawler -> technical issues/history -> content/tasks/internal linking -> performance/opportunities/recommendations -> final operational release acceptance.

External APIs, CMS providers and AI remain optional integrations. Plans, entitlements and SaaS commercialization remain later scope and are not prerequisites for the offline-capable self-host core.
