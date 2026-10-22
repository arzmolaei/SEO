# SEO OS delivery gates

This is a delivery map, not a claim that the whole product is implemented.
The active implementation plan/progress and current GitHub CI are authoritative.

## Continue autonomously

Complete one task with RED/GREEN, relevant verification, semantic commit/push and
final-head permanent CI. Then take the next task in the active plan without routine
confirmation. Update PROJECT_STATE and active progress at boundaries. Do not claim
indefinite background execution across a terminated session; the next session
resumes recorded Git/CI state instead of rebuilding completed work. Never weaken
gates or add implementation-mirroring tests.

## Verified delivery slices

- Foundation + Keyword Core: completed, reviewed and merged through PR #1.
- Keyword Intelligence Tasks 1–11: implemented, reviewed and verified on branch
  `feature/keyword-intelligence`; PR #2 is the single integration PR.
- Post-review Keyword Intelligence boundary:
  `216a4d2448defdd2f481dcf0beb55dbeeb112f10`, CI `39828064083`, success,
  237 tests / 1317 assertions.

After PR #2 final-head CI and integration, do not reopen the intelligence plan.
Create the next bounded implementation plan from updated `main` for the operational
self-host release gate below.

## First usable self-host release gate — next delivery phase

Before calling any release ready for normal installation/use, verify all of:

- Clean runtime-only package; supported PHP/database/extensions and local assets.
- Fresh browser installation, first owner, installer lock and system health.
- Project -> import -> keyword/intent/cluster -> mapping -> priority workflow.
- Export real project data, including Persian encoding and access isolation.
- Backup database + uploaded files + settings with a versioned checksum manifest.
- Restore into a clean installation; verify users, authorization and SEO data.
- Update from the preceding release: compatibility, backup, migrations, health.
- Interrupted/failed update recovery and rollback exercised on real fixtures.
- Queue/scheduler failure visibility and self-host operational instructions.
- No dead UI actions represented as completed modules; unfinished modules are
  explicitly unavailable until delivered.

Installer/health/release *foundations* do not satisfy backup/restore/update gates.
Use bounded implementation plans for the remaining operational flows; inspect and
reuse existing installer/System Health/release packaging instead of rebuilding
them.

A sensible bounded order is:

1. fresh-install/first-owner/lock/System Health acceptance and missing installer
   behavior;
2. real project export with Persian encoding/access-isolation acceptance;
3. versioned backup manifest + backup production path;
4. clean restore + auth/SEO integrity verification;
5. safe update path with pre-update backup/migrations/health;
6. interruption/failure recovery and rollback fixtures;
7. queue/scheduler operational visibility and deploy/runbook acceptance;
8. end-to-end self-host release acceptance across a clean runtime package.

Each item should have its own RED contract and verified boundary. Do not collapse
all operational risk into one oversized implementation task.

## Subsequent full-product scope

Only after the first usable self-host release gate is verified should the product
advance through the master spec phases:

Site Intelligence/crawler -> technical issues/history -> content/tasks/internal
linking -> performance/opportunities/recommendations -> full operational release
acceptance.

External APIs, CMS providers and AI remain optional integrations. Plans,
entitlements and SaaS commercialization are later scope, not prerequisites for the
offline-capable self-host core.