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
- Update + Interrupted Recovery/Rollback — integrated through PR #8 at merge commit `43342c667859d68a2bfe62b1fb2258aafedb6a5c` (2026-10-24), with permanent connected MariaDB rollback/recovery acceptance (separate `Update Rollback MariaDB` workflow) and runtime release-slot smoke. Known gaps: see `docs/release-readiness/update-recovery-rollback.md`.

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

### Completed: Update + Interrupted Recovery/Rollback

Integrated capabilities: ProductVersion/source-target compatibility rules, mandatory verified pre-update safety snapshot, shared `LifecycleMutex` serialization, browser-driven offline-capable release staging and atomic switch, exact-history migration execution, durable update journal, post-switch health validation, deterministic rollback/recovery with fail-closed `recovery_required`, preservation of destination-local secrets and installation identity, Persian-first operator UI, runtime release-slot smoke and connected MariaDB rollback/recovery acceptance.

Not yet verified (tracked, not hidden): connected-MariaDB successful-update acceptance, connected bootstrap/health-failure and whole-update interruption/drift cases, a recorded whole-branch review, and publisher signing (out of V1). Do not rebuild this slice; close these items deliberately.

### Remaining lifecycle work

**Final end-to-end lifecycle acceptance** — clean runtime package -> browser install -> real project/import/intelligence workflow -> Persian Export -> Backup/Restore -> Update/Recovery/Rollback -> operational health.

Already satisfied before that final slice:

- runtime-only package and supported host preflight;
- browser installation, owner creation, installer lock and authenticated entry;
- real MariaDB 10.11 installer acceptance;
- complete keyword/import/intelligence core;
- Persian project handoff export;
- versioned full-instance backup, clean restore and interruption recovery with connected acceptance;
- browser-driven update with exact-history migrations, rollback and interruption recovery (gaps tracked above).

## Subsequent full-product scope

Only after the first usable self-host lifecycle gate is verified should the product advance through the remaining master-spec phases:

Site Intelligence/crawler -> technical issues/history -> content/tasks/internal linking -> performance/opportunities/recommendations -> final operational release acceptance.

External APIs, CMS providers and AI remain optional integrations. Plans, entitlements and SaaS commercialization remain later scope and are not prerequisites for the offline-capable self-host core.
