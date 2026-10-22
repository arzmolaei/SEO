# SEO OS — handoff to the next implementation agent

Checkpoint: 2026-10-22. Repository evidence and current CI always override stale
chat context. This handoff records the current verified implementation boundary;
it does not mean the full product is complete.

## Start here

Read `AGENTS.md`, `docs/PROJECT_STATE.md`, the foundation release-readiness report,
the master product spec, the active Keyword Intelligence plan and progress file.
Fetch origin and check actual HEAD/latest CI before changing code.

- Repository: `arzmolaei/SEO`.
- Continue **`feature/keyword-intelligence`**; never reset it or develop on main.
- Foundation + Keyword Core: completed, reviewed and merged through PR #1.
- Keyword Intelligence Tasks **1–10 verified**.
- Latest verified implementation: `f51f5d76aca3331153383726bbc76eebffab6846`.
- Permanent CI: `39813493267`, success on that exact implementation head/PR merge ref.
- Full suite at this boundary: **226 backend tests / 1229 assertions**.
- PHP 8.4/8.5 compatibility, frontend check/TypeScript/build, Pint, PHPStan,
  runtime npm/Composer audits and runtime-only release smoke all passed.
- Open subsystem PR: #2. Do not create a duplicate and do not merge before Task 11
  acceptance, whole-branch review and a fresh final-head CI.

## Delivered since Task 8

### Task 9 — mapping conflicts

Deterministic, bounded mapping warnings are implemented and verified. They cover
competing pages only when known intent also shares meaningful topic anchors,
overloaded pages with distinct anchors, high-demand unmapped clusters only from
real latest volume evidence, and structurally invalid targets. Detection never
silently remaps. Review fingerprints preserve resolved decisions until evidence
changes, changed evidence reopens them, stale acknowledgements fail, and HTTP
resolution is project-authorized/audited.

Verified Task 9 implementation boundary: `bd38cc696de7eaca60776c52abd62c4e32929fe1`,
CI `39810763754`, success. The old pending RED-contract document is historical
evidence only; do not treat Task 9 as unfinished.

### Task 10 — priority + Ready lifecycle

Priority is local, deterministic and explainable. It uses only known signals:
latest recorded volume, latest recorded difficulty, optional manual business
relevance (1–5), mapping completeness and review completeness. Missing metrics
remain missing and contribute no invented value. The score exposes component
points/evidence plus data completeness.

Clusters have a separate `planning|ready` lifecycle. Ready requires an active,
reviewed cluster, an explicit current target (including explicit Ignore), and no
pending review for its primary keyword. A later real remap, reopened/new pending
review, or cluster mutation invalidates Ready back to Planning instead of leaving
a stale readiness decision. Those state changes remain auditable and cluster
mutation snapshots include the new lifecycle inputs so Undo stays revision-safe.

Final Task 10 boundary: `f51f5d76aca3331153383726bbc76eebffab6846`,
CI `39813493267`, success; 226 tests / 1229 assertions.

## Exact next work — Task 11

Task 11 is the first unfinished task. Follow
`docs/superpowers/plans/2026-10-22-keyword-intelligence.md` exactly:

1. Write a connected backend acceptance contract first and observe RED where the
   missing integration truly fails.
2. Connect the existing canonical Keyword Grid to intent, cluster, page-target,
   mapping-conflict, priority and Ready workflows with project-scoped HTTP
   boundaries. Do not replace the existing high-performance grid with an
   unbounded client-side dataset.
3. Expose manual cluster editing and page targeting without bypassing the existing
   transactional services, project-first locking, revisions, locks or history.
4. Preserve Persian-first RTL, keyboard review actions and explicit
   loading/empty/error states.
5. Run full backend/static/style/frontend/runtime/release gates, then perform a
   whole-branch review focused on authorization, idempotency, locks/manual
   overrides, stale decisions and silent-remap risks.
6. Merge PR #2 only after the final branch head and PR CI are green.

## Critical integration invariants

- Preserve original keyword data, provenance, manual intent, locked clusters and
  memberships. Automation must never silently overwrite explicit human decisions.
- All cluster/mapping/page mutations remain project-scoped, authorized and use the
  existing serialization/locking services rather than raw HTTP ORM writes.
- Mapping changes increment cluster revision; old cluster Undo must not erase later
  mapping decisions.
- `PromotePageTargets` uses Laravel event autodiscovery only. Do not manually
  register it and duplicate execution.
- Merge archives source cluster identities. Never reactivate an invalid empty
  source; active clusters require a primary member.
- Ready is a decision derived from current evidence, not a sticky badge. Later
  material SEO/review changes must invalidate it.
- No crawler/SERP/AI/vector dependency belongs in Task 11. Crawler follows stable
  keyword-to-page relationships.
- Commercial font binaries, `.env`, secrets and local runtime fixtures stay ignored.

## Engineering and verification

Use RED → correct RED → minimal GREEN → refactor → meaningful focused verification
→ full permanent CI → semantic commit/push. Diagnose failures before changing code.
Never force-push, overwrite history, weaken gates, remove a legitimate regression
test or infer success from a cancelled/older run. Continue task-by-task without
routine user confirmation.

The actual dependency floor is **PHP ^8.4.1**. Older 8.3 references are stale;
do not advertise PHP 8.3 compatibility or downgrade Laravel blindly.

## What remains beyond Keyword Intelligence

Task 11 connected UI/acceptance/review is still unfinished, so the subsystem is
not yet complete or merge-ready. The wider SEO OS product also remains unfinished.
After this slice, follow `docs/DELIVERY_PLAN.md`: real self-host fresh-install,
normal-use, Persian export, backup/restore, update/interruption/recovery acceptance,
then Site Intelligence/crawler, technical history/issues, content/tasks/internal
linking and performance/opportunity/recommendation work. Never present those later
systems as delivered before their own acceptance evidence exists.

If execution is externally interrupted, record the last verified SHA/CI and exact
first unfinished behavior in Git. Resume from repository evidence instead of
rebuilding completed work.
