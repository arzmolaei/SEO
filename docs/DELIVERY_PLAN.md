# SEO OS delivery gates

This is a delivery map, not a claim that the whole product is implemented.
The active implementation plan/progress and current GitHub CI are authoritative.

## Continue autonomously

Complete one task with RED/GREEN, relevant verification, semantic commit/push
and final-head permanent CI. Then take the next task in the active plan without
routine confirmation. Update PROJECT_STATE and active progress at boundaries.
Do not claim indefinite background execution across a terminated session; the
next session resumes the recorded state and current Git/CI, without rebuilding
completed work. Do not weaken gates or add implementation-mirroring tests.

## Verified foundation

- Foundation + Keyword Core completed and merged.
- Keyword Intelligence Tasks 1–8 verified; Task 9 active.

## Current delivery: connected Keyword Intelligence

- Task 5: deterministic lexical proposals, safe application, uncertain review.
- Task 6: merge/split/move/lock with safe attributed history and undo.
- Tasks 7–10: existing/planned pages, mappings, conflicts and priority/lifecycle.
- Task 11: connected UI and end-to-end acceptance, whole-branch review.

## First usable self-host release gate

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

Installer/health/release _foundations_ do not satisfy backup/restore/update gates.
Use bounded implementation plans for the remaining operational flows after the
current intelligence plan; do not silently extend an unrelated task.

## Subsequent full-product scope

After keyword/page relationships are stable, execute the master spec phases:
Site Intelligence/crawler -> technical issues/history -> content/tasks/internal
linking -> performance/opportunities/recommendations -> full operational release
acceptance. External APIs, CMS providers and AI remain optional integrations.
Plans/entitlements/SaaS commercialization are later scope, not prerequisites for
the user's offline-capable self-host core.
