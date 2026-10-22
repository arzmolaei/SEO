# Keyword Intelligence — Increment Readiness

Date: 2026-10-22

## Scope and status

The complete bounded Keyword Intelligence slice is implemented and verified:

`Canonical Keyword -> Intent/Review -> Cluster -> Manual Cluster Edits -> Existing/Planned Page -> Mapping -> Conflict Review -> Priority -> Ready`

Tasks 1–11 in `docs/superpowers/plans/2026-10-22-keyword-intelligence.md`
are complete. Foundation + Keyword Core was already merged through PR #1; this
slice is carried by PR #2. This report is readiness evidence for the Keyword
Intelligence increment only, **not** a claim that the whole SEO OS product or the
first usable self-host release gate is complete.

Post-review implementation boundary: `216a4d2448defdd2f481dcf0beb55dbeeb112f10`.
Permanent CI: `39828064083` — success on that exact implementation head.
A documentation-only final-head CI is still required before PR #2 integration.

## Delivered workflow

### Intent and Review Queue

Canonical project keywords receive deterministic, explainable local intent
suggestions. Ambiguous or lower-confidence results remain reviewable instead of
being silently forced. Human assignments are authoritative, review fingerprints
are stale-safe, and resolved items reopen only when relevant evidence changes.

The project Review Queue supports intent, cluster and mapping-conflict items. It is
Persian-first/RTL-safe, keyboard-friendly and exposes evidence instead of hiding
automatic decisions. Mapping warnings are explicitly labelled and support only the
bounded accept/skip acknowledgement semantics; they never mutate a target by
acceptance alone.

### Clustering and manual editing

Deterministic lexical candidate generation uses bounded grouping and explicit
evidence; it does not depend on SERP, AI or an external service. Locked clusters,
manual memberships and explicit human decisions survive recalculation.

Manual merge/split/move/lock operations are project-authorized transactional
services with stable locking, revisioned history and safe Undo where no
intervening material state invalidates it. The connected UI calls those services
through project-scoped HTTP boundaries. Primary membership cannot be silently
removed; moving/splitting a primary requires an explicit replacement.

### Pages, mapping and conflicts

Existing and planned pages are first-class project entities before crawler work.
Planned pages can later promote to existing identity without losing mappings.
Each active cluster has at most one current target while previous mapping decisions
remain auditable.

Mapping-conflict detection is deterministic and conservative. It covers competing
pages only with same known intent plus meaningful shared topic, overloaded pages
with distinct topic anchors, high-demand unmapped clusters only when real current
volume evidence meets the configured threshold, and structurally invalid targets.
Warnings are grouped/bounded and never perform an automatic remap.

### Priority and Ready lifecycle

Priority is deterministic and explainable. It uses only available local evidence:
latest volume, latest difficulty, optional manual business relevance, mapping
completeness and review completeness. Missing metrics remain missing and contribute
no invented value. Component evidence and data completeness are exposed.

Ready is a guarded current-state decision, not a sticky badge. It requires an
active reviewed cluster, an explicit mapping (including explicit Ignore) and no
pending primary-keyword review. Material remaps, cluster mutations or new/reopened
required reviews invalidate stale Ready back to Planning.

### Connected workspace

The existing server-paginated Keyword Grid remains the performance-oriented source
surface. The Keywords page now connects Grid, Intent, Clusters and Mapping entry
points without replacing the Grid with an unbounded browser dataset. The server
supplies explicit `intelligence.project_id`; the client does not infer project
identity from URL shape.

The workspace exposes review state, locks, page targeting, conflict refresh,
priority/data completeness, Ready transition and manual cluster editing. Empty,
error, busy and no-result states are explicit where applicable. Proprietary font
binaries and foreign-service dependencies remain outside the runtime package.

## Verification evidence

Post-review full permanent CI `39828064083` passed on
`216a4d2448defdd2f481dcf0beb55dbeeb112f10`:

- **237 PHP tests / 1317 assertions** passed.
- PHP 8.4 and PHP 8.5 compatibility suites passed.
- Frontend formatter/linter passed.
- TypeScript passed.
- Production frontend build passed.
- Laravel Pint passed across 224 files.
- PHPStan/Larastan passed across 169 analyzed files with no errors.
- Runtime npm audit reported zero vulnerabilities.
- Composer runtime audit reported no security advisories.
- Runtime-only Composer installation passed.
- Clean production release tree built and booted Laravel 13.38.0 successfully.
- Connected acceptance verifies the reviewed -> mapped -> Ready workflow and
  rejection of cross-project identifiers.
- Manual cluster HTTP operations, mapping conflicts, stale review refusal, Ready
  invalidation, project isolation and Persian RTL/LTR direction/font contracts are
  covered by the passing suite.

Whole-branch review rechecked the intended high-risk boundaries: authorization,
project isolation, idempotency, lock/manual override preservation, stale decisions,
revision-safe Undo and silent-remap risk. No open PR review threads remained at the
post-review boundary.

## Runtime and operational notes

The actual dependency baseline requires **PHP 8.4.1+** (`composer.json`). Earlier
PHP 8.3 references are stale; PHP 8.3 compatibility is not claimed. Database queue
and local storage remain defaults. Core Keyword Intelligence does not require an
international API, AI provider, SERP provider or font CDN.

Queue-backed preparation still requires the normal self-host worker/cron setup.
Worker/queue operational acceptance belongs to the first usable self-host release
gate and is not implied complete by this increment.

## Deferred product work

Keyword Intelligence is ready to integrate, but the product is not yet a usable
self-host release. Follow `docs/DELIVERY_PLAN.md` next for bounded operational
release work: fresh browser installation/first owner/lock/health, Persian export
and access isolation, backup/restore with versioned checksum manifest, safe update
and interruption recovery/rollback, and queue/scheduler visibility/instructions.

Only after those release prerequisites are deliberately planned should subsequent
full-product phases proceed through Site Intelligence/crawler, technical
issues/history, content/tasks/internal linking and performance/opportunity/
recommendation systems.