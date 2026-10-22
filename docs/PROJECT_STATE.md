# SEO OS — Durable Project State & Handoff

Last checkpoint: 2026-10-22

This file is the durable source of truth for continuing SEO OS across agent
sessions. **Do not reconstruct project state from chat history when this file and
Git disagree. Git + CI win.**

## Stable baseline

- Repository: `arzmolaei/SEO`
- Stable branch: `main`
- Foundation + Keyword Core was reviewed and merged through PR #1.
- Active subsystem branch: `feature/keyword-intelligence`.
- Active integration PR: #2 into `main`.
- Never develop directly on `main`, reset the active branch, force-push over
  unexpected movement, or create a duplicate subsystem PR.

Always fetch the current branch/main heads and inspect current CI before new code.
Recorded hashes are evidence, not permission to assume Git has not moved.

## Completed vertical slice: Foundation + Keyword Core

This slice is complete and reviewed. Do **not** reimplement it.

Implemented and verified foundations include Laravel 13 + Inertia/React/TypeScript,
Persian-first RTL/LTR, auth/onboarding, Workspace/Membership/Project isolation,
Project shell, Persian keyword normalization preserving original source text,
streaming/resumable keyword import, canonical keyword/provenance/metric history,
server-side Keyword Grid, Saved Views, transactional bulk changes/audit/safe Undo,
installer/System Health foundations, allowlist-first runtime release packaging and
connected foundation acceptance. See
`docs/release-readiness/foundation-keyword-core.md` for detailed evidence.

## Fonts / self-host rule

- Default interface font: IRANSansX FaNum.
- Selectable alternatives: Yekan Bakh and Vazirmatn.
- Vazirmatn is bundled locally from its OFL source for release builds.
- Proprietary IRANSansX/Yekan Bakh binaries are never committed publicly; licensed
  files are installed into the ignored private-font directory.
- Core runtime must not depend on an international font/CDN request.

## Active implementation — resume here

Continue existing `feature/keyword-intelligence`; do not create/reset it.

Keyword Intelligence Tasks **1–10 are verified**. The latest verified
implementation boundary before this documentation update is:

- implementation SHA: `f51f5d76aca3331153383726bbc76eebffab6846`
- permanent PR CI: `39813493267` — success
- full backend suite: 226 tests / 1229 assertions
- PHP 8.4 + 8.5 compatibility: success
- frontend formatter/check, TypeScript and production build: success
- Pint + PHPStan: success
- runtime npm/Composer security audits: success
- runtime-only production release-tree smoke: success

Task 9 mapping conflicts and Task 10 priority/Ready lifecycle are therefore not
pending work anymore. `docs/HANDOFF.md` and
`docs/superpowers/progress/2026-10-22-keyword-intelligence.md` contain the current
boundary details.

**Task 11 — connected Keyword Intelligence workspace + vertical-slice acceptance —
is the exact next implementation task.** Do not start crawler/SERP/AI work first.
Do not merge PR #2 before Task 11 acceptance, whole-branch review and fresh
final-head CI are all green.

## Verified Keyword Intelligence behavior through Task 10

The verified subsystem now contains:

1. attributed intent assignment and immutable override history;
2. deterministic/explainable Persian rule-based intent classification;
3. project-scoped Intent Review Queue with stale-evidence protection;
4. cluster domain/membership and primary-member invariants;
5. deterministic lexical candidate generation/application with bounded grouping;
6. merge/split/move/lock plus revision-safe attributed Undo;
7. existing/planned Page entities and stable page promotion;
8. one current cluster target with mapping history/revision protection;
9. deterministic mapping-conflict review: competing pages, overloaded targets,
   real-evidence high-demand unmapped clusters and invalid targets, without silent
   remapping;
10. explainable local priority scoring and a guarded `planning|ready` lifecycle.

Priority uses only real available local evidence: latest volume, latest difficulty,
optional manually supplied business relevance, mapping completeness and review
completeness. Missing metrics remain missing. Ready requires completed cluster
review, an explicit mapping (including explicit Ignore) and no pending review for
the primary keyword. Material remaps, cluster mutations or new/reopened pending
reviews invalidate stale Ready decisions back to Planning.

## Keyword Intelligence Task 11 boundary

Task 11 must connect the verified services into a usable project workflow:

`Canonical Keyword -> Intent/Review -> Cluster -> Manual Cluster Edits -> Existing/Planned Page -> Mapping -> Conflict Review -> Priority -> Ready`

Requirements:

- Start with a genuine RED backend acceptance contract.
- Keep the existing server-side high-performance Keyword Grid; do not replace it
  with an unbounded browser dataset.
- Add project-scoped HTTP/application boundaries that call the existing services;
  do not mutate cluster/mapping/page state directly from controllers/raw ORM paths.
- Expose Intent, Clusters/Mapping and Review Queue entry points with Persian-first
  RTL, keyboard accessibility and explicit loading/empty/error states.
- Preserve locked/manual decisions, project isolation, idempotency and revision
  protection.
- Run full PHP/PHPStan/Pint/frontend/build/runtime/audit/release gates on final
  head, then whole-branch review before merge.

## Mandatory continuation protocol

Before changing code in a new agent/session:

1. Read `AGENTS.md`.
2. Read this file and `docs/HANDOFF.md` completely.
3. Read Foundation release readiness, the master spec, active plan and progress.
4. Fetch current `main` and active branch heads plus current CI.
5. Resume the explicitly named active branch; create a new branch only for a
   genuinely new subsystem after the current PR is integrated.
6. Use TDD for bounded behavior: RED -> correct GREEN -> refactor -> verification.
7. Keep permanent CI gates intact; diagnostic changes must never survive as a
   weakened permanent pipeline.
8. Update durable state at verified subsystem boundaries.
9. Do not stop for routine user confirmation; continue autonomously task-by-task.

## Definition of trustworthy progress

Code existence is not completion. Applicable formatter/lint, TypeScript, production
frontend build, Pint, PHPStan/Larastan, backend tests, runtime dependency audits,
authorization/isolation coverage, connected acceptance and release/runtime smoke
must be green. CI must correspond to the current final head; older/cancelled runs
are not proof.

The actual current runtime floor is **PHP 8.4.1+**. Older PHP 8.3 references are
stale and must be reconciled during release documentation rather than by blindly
downgrading Laravel.

## Beyond the current slice

Full self-host release acceptance remains future work after Keyword Intelligence:
fresh install, real normal-use flow, Persian export, backup/restore, versioned
update, interruption/recovery/rollback, queue/scheduler operational visibility.
After the stable keyword-to-page relationship exists, continue master-plan work
with Site Intelligence/crawler, technical issues/history, content/tasks/internal
linking and performance/opportunity/recommendation systems. Do not claim any of
those later systems delivered before their own verified acceptance gates.

If execution is externally interrupted, preserve the latest verified SHA/CI and
exact first unfinished behavior in Git. The next session resumes from repository
evidence, not guesses.
