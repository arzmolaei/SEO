# SEO OS delivery gates

This is a delivery map, not a claim that the whole product is implemented. The
active implementation plan/progress and current GitHub CI are authoritative.

## Continue autonomously

Complete one task with RED/GREEN, relevant verification, semantic commit/push and
final-head permanent CI. Then take the next task in the active plan without routine
confirmation. Update PROJECT_STATE and active progress at boundaries. Do not claim
indefinite background execution across a terminated session; the next session
resumes recorded Git/CI state instead of rebuilding completed work. Never weaken
gates or add implementation-mirroring tests.

## Verified delivery slices

- Foundation + Keyword Core: completed, reviewed and merged through PR #1.
- Keyword Intelligence Tasks 1–11: completed, reviewed and merged through PR #2.
- Keyword Intelligence is part of `main` at merge commit
  `95bb5a014834590c30ff2562615b050b71c997f0`.
- Self-host Installer Tasks 1–8: implementation, connected acceptance and
  whole-branch review completed on `feature/self-host-installer`; PR #4 is the
  single integration PR.
- Installer implementation evidence before final documentation:
  `75772ff85cc1eda7da8330af43dc7d89161c8954`, permanent CI `39859189369`,
  success across PHP 8.4/8.5, verify and MariaDB 10.11 connected acceptance.

The installer slice now covers clean browser bootstrap, persistent APP_KEY and
production `.env`, real MySQL/MariaDB verification, resumable migrations, exactly
one first owner/workspace, queue/scheduler activation evidence, final revalidation,
lock-last semantics, authenticated handoff and installer lockdown. The runtime-only
release package also boots `/install` over HTTP without Node/npm/Composer on the
host.

PR #4 still requires a fresh permanent CI run on the final documentation-complete
SHA. Do not treat an older successful run as the merge gate.

## First usable self-host release gate — active lifecycle phase

The Installer boundary is complete pending PR #4 final-head CI and merge. The
remaining lifecycle work, in order, is:

1. **Persian project export** — real project data, UTF-8/Persian correctness,
   bounded streaming behavior and strict access isolation.
2. **Backup + Restore** — database, uploaded files and durable settings with a
   versioned checksum manifest; restore into a clean installation and verify users,
   authorization and SEO data.
3. **Update + interrupted recovery/rollback** — compatibility checks, mandatory
   pre-update backup, migrations, health validation, failed/interrupted update
   recovery and rollback on real fixtures.
4. **End-to-end lifecycle acceptance** — clean runtime package through install,
   real project workflow, export, backup/restore, update/recovery and operational
   health.

Already satisfied within the Installer slice:

- clean runtime-only package and supported environment preflight;
- fresh browser installation, first owner, installer lock and authenticated entry;
- real MariaDB 10.11 connected installation acceptance;
- queue/scheduler evidence, shared-host commands and blocking-failure visibility;
- installer UI/actions are connected rather than dead placeholders.

Still required before the first usable self-host release can be called complete:

- Project -> import -> keyword/intent/cluster -> mapping -> priority workflow on a
  clean installed release as part of final lifecycle acceptance;
- Persian project export;
- versioned backup and clean restore;
- safe update plus interruption/failure recovery and rollback;
- final clean-package lifecycle acceptance across all of the above.

Do not collapse those risks into one oversized implementation task. Each bounded
sub-project gets its own design/plan where required, RED contract, verified
boundary, semantic commits and exact-head permanent CI.

## Subsequent full-product scope

Only after the first usable self-host lifecycle gate is verified should the product
advance through the master spec phases:

Site Intelligence/crawler -> technical issues/history -> content/tasks/internal
linking -> performance/opportunities/recommendations -> full operational release
acceptance.

External APIs, CMS providers and AI remain optional integrations. Plans,
entitlements and SaaS commercialization are later scope, not prerequisites for the
offline-capable self-host core.
