# SEO OS — handoff to the next implementation agent

Checkpoint: 2026-10-22. Repository evidence and current CI always override stale
chat context. This handoff records the current verified implementation boundary;
it does not mean the full product is complete.

## Start here

Read `AGENTS.md`, `docs/PROJECT_STATE.md`, the release-readiness reports,
`docs/DELIVERY_PLAN.md`, the master product spec and the completed Keyword
Intelligence progress file. Fetch origin and inspect actual branch/main heads and
latest CI before changing code.

- Repository: `arzmolaei/SEO`.
- Foundation + Keyword Core: completed, reviewed and merged through PR #1.
- Keyword Intelligence Tasks **1–11 are implemented and post-review verified**.
- Post-review implementation boundary:
  `216a4d2448defdd2f481dcf0beb55dbeeb112f10`.
- Permanent implementation CI: `39828064083`, success on that exact head.
- Full backend suite there: **237 tests / 1317 assertions**.
- PHP 8.4/8.5 compatibility, frontend formatter/TypeScript/build, Pint, PHPStan,
  runtime npm/Composer audits, runtime-only install and release-tree smoke passed.
- PR #2 is the single Keyword Intelligence integration PR into `main`. Do not
  create a duplicate. A fresh permanent CI on the final documentation-complete PR
  head is the remaining integration gate before merge.

If this handoff is read from `main` after PR #2 integration, treat Keyword
Intelligence as integrated and start the next bounded release-gate work from the
updated `main`; do not resurrect Tasks 1–11.

## Completed Keyword Intelligence slice

### Intent and Review Queue

Intent assignments keep immutable attributable history. The deterministic Persian
rule engine is explainable and conservative around ambiguity. Review items are
project-scoped, stale-safe and idempotent; explicit human decisions outrank
automatic suggestions. The connected Review Queue handles intent, cluster and
mapping-conflict items with Persian-first RTL/keyboard behavior.

### Clustering and manual operations

Lexical clustering is bounded and deterministic, without AI/SERP dependency.
Locked/manual state survives recalculation. Merge/split/move/lock operations are
service-owned, transactional, project-authorized, audited and revision-safe for
Undo. The connected UI uses only those HTTP/application boundaries and requires
explicit primary replacement when a mutation would otherwise invalidate a cluster.

### Pages, mapping and conflict review

Existing/planned Page entities and stable planned→existing promotion are in place.
Cluster mapping retains history/revisions and rejects cross-project targets.
Conflict detection is deterministic and evidence-based: warnings never silently
change targets. Resolved evidence stays closed until material evidence changes;
stale acknowledgements fail.

### Priority and Ready

Priority uses only known local signals and exposes evidence/data completeness.
Missing metrics remain missing. Ready requires current review completion plus an
explicit target (including explicit Ignore). Material remaps, cluster mutations or
new/reopened required reviews invalidate stale Ready back to Planning.

### Connected acceptance and final review

The existing server-side Keyword Grid remains intact and is connected to Intent,
Clusters and Mapping entry points. `intelligence.project_id` is explicit rather
than inferred from URL structure. Manual cluster search/edit controls have explicit
busy/error/no-result states. Whole-branch review fixed the frontend
`mapping_conflict` contract/label mismatch and rechecked authorization,
idempotency, lock/manual override preservation, stale decisions, revision-safe
Undo and silent-remap risks. No open PR review threads remained at the post-review
boundary.

Detailed evidence: `docs/release-readiness/keyword-intelligence.md` and
`docs/superpowers/progress/2026-10-22-keyword-intelligence.md`.

## Critical invariants that carry forward

- Preserve original keyword text, provenance/metric history and explicit human
  intent/cluster/mapping decisions.
- Every project resource query/mutation remains project-scoped and authorized.
- Automation never recalculates through explicit locks/manual overrides.
- Mapping warnings are reviewable signals, never silent target mutations.
- Cluster/mapping/page changes retain revision/history semantics; old Undo must not
  erase later material decisions.
- `PromotePageTargets` uses Laravel event autodiscovery only; do not manually
  register it and duplicate execution.
- Ready is derived from current evidence, not a permanent badge.
- Core self-host workflows remain independent of international API/AI/SERP/CDN
  availability.
- Commercial font binaries, `.env`, secrets and local fixtures stay ignored.

## Exact next delivery phase after PR #2

Do **not** jump directly to crawler/SERP/AI. Follow `docs/DELIVERY_PLAN.md` and
write a bounded implementation plan for the first usable self-host release gate.
The required operational acceptance still includes:

1. clean runtime-only package and supported environment checks;
2. real fresh browser installation, first owner, installer lock and health;
3. normal Project -> import -> keyword intelligence workflow;
4. real Persian project export with access isolation;
5. backup of database/uploads/settings with versioned checksum manifest;
6. restore into a clean installation and verify auth/SEO data;
7. safe update from preceding release with backup/migrations/health;
8. interrupted/failed update recovery and rollback on real fixtures;
9. queue/scheduler failure visibility and self-host instructions;
10. no dead UI represented as delivered functionality.

Installer/health/release *foundations* already exist but do not satisfy these
acceptance gates. Inspect current implementation before choosing the first bounded
operational task; reuse foundations rather than rebuilding them.

Only after the operational release gate is deliberately implemented/verified move
to Site Intelligence/crawler -> technical issues/history -> content/tasks/internal
linking -> performance/opportunities/recommendations.

## Engineering protocol

Use RED → correct RED → minimal GREEN → refactor → meaningful focused verification
→ permanent final-head CI → semantic commit/push. Diagnose failures before changing
code. Never force-push, overwrite unexpected history, weaken gates, remove a valid
regression test or infer success from a cancelled/older run. Continue task-by-task
without routine user confirmation.

The actual runtime floor is **PHP ^8.4.1**. Older PHP 8.3 references are stale and
must not be advertised as verified compatibility.

If execution is externally interrupted, record the last verified SHA/CI and exact
first unfinished behavior in Git. Resume from repository evidence instead of
rebuilding completed work.