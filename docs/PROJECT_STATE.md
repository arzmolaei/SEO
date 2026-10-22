# SEO OS — Durable Project State & Handoff

Last checkpoint: 2026-10-22

This file is the durable source of truth for continuing SEO OS across agent
sessions. **Do not reconstruct project state from chat history when this file and
Git disagree. Git + CI win.**

## Stable baseline

- Repository: `arzmolaei/SEO`
- Stable branch: `main`
- Foundation + Keyword Core was reviewed and merged through PR #1.
- Keyword Intelligence is implemented/reviewed on
  `feature/keyword-intelligence` and carried by integration PR #2.
- Never develop directly on `main`, reset an active branch, force-push over
  unexpected movement, or create a duplicate subsystem PR.

Always fetch current heads and exact-head CI before new code. Recorded hashes are
evidence, not permission to assume Git has not moved.

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

## Keyword Intelligence — completed implementation boundary

Keyword Intelligence Tasks **1–11 are implemented and post-review verified**.
Do not restart Task 9, Task 10 or Task 11 from stale plans/chat.

Post-review implementation evidence:

- implementation SHA: `216a4d2448defdd2f481dcf0beb55dbeeb112f10`
- permanent CI: `39828064083` — success
- full backend suite: **237 tests / 1317 assertions**
- PHP 8.4 + 8.5 compatibility: success
- frontend formatter/check, TypeScript and production build: success
- Pint + PHPStan: success
- runtime npm/Composer security audits: success
- runtime-only Composer install and production release-tree smoke: success

The connected slice now provides:

1. attributed intent assignment and immutable override history;
2. deterministic/explainable Persian rule-based intent classification;
3. project-scoped Review Queue with stale-evidence protection;
4. cluster domain/membership and primary-member invariants;
5. deterministic lexical candidate generation/application with bounded grouping;
6. merge/split/move/lock plus revision-safe attributed Undo;
7. existing/planned Page entities and stable page promotion;
8. one current cluster target with mapping history/revision protection;
9. deterministic mapping-conflict review without silent remapping;
10. explainable local priority scoring and guarded `planning|ready` lifecycle;
11. connected Grid/Intent/Clusters/Mapping workspace and vertical acceptance.

The existing server-side Keyword Grid remains performance-oriented and is not
replaced by an unbounded client dataset. Manual cluster controls use only existing
project-scoped service boundaries. Mapping conflicts are explicitly labelled in
the Review Queue. The client receives explicit project identity rather than
inferring it from URL structure.

PR #2 is the single integration vehicle. Merge only after a fresh permanent CI on
the final documentation-complete branch head. If this file is read from `main`
after that integration, Keyword Intelligence is integrated and no longer active
work.

Detailed evidence lives in `docs/release-readiness/keyword-intelligence.md` and
`docs/superpowers/progress/2026-10-22-keyword-intelligence.md`.

## Exact next implementation phase

After PR #2 integrates, create a new bounded branch/plan from updated `main` for
the **first usable self-host release gate**. Do not start Site Intelligence/crawler,
SERP, AI or vector work before deliberately addressing the operational gate.

Required release acceptance still includes:

- clean runtime-only package and supported environment checks;
- fresh browser installation, first owner, installer lock and system health;
- normal Project -> import -> keyword intelligence workflow;
- real Persian project export with access isolation;
- backup database/uploads/settings with a versioned checksum manifest;
- restore into a clean install and verify users, authorization and SEO data;
- safe update from a preceding release with backup/migrations/health;
- interrupted/failed update recovery and rollback on real fixtures;
- queue/scheduler failure visibility and self-host operational instructions;
- no dead UI/actions represented as completed modules.

Installer/System Health/release packaging foundations already exist. Inspect and
reuse them; they do **not** by themselves satisfy backup/restore/update or fresh
installation acceptance.

Only after the operational release gate is verified continue the master product
phases: Site Intelligence/crawler -> technical issues/history -> content/tasks/
internal linking -> performance/opportunities/recommendations -> full operational
release acceptance. External APIs/CMS/AI remain optional integrations.

## Mandatory continuation protocol

Before changing code in a new agent/session:

1. Read `AGENTS.md`.
2. Read this file and `docs/HANDOFF.md` completely.
3. Read release readiness, `docs/DELIVERY_PLAN.md`, master spec and current active
   plan/progress.
4. Fetch current `main` and active branch heads plus current CI.
5. Use a new branch only for the genuinely next subsystem after PR #2 integration.
6. Use TDD for bounded behavior: RED -> correct GREEN -> refactor -> verification.
7. Keep permanent CI gates intact; diagnostic workflows must not survive as a
   weakened pipeline.
8. Update durable state at verified subsystem boundaries.
9. Do not stop for routine user confirmation; continue autonomously task-by-task.

## Definition of trustworthy progress

Code existence is not completion. Applicable formatter/lint, TypeScript, production
frontend build, Pint, PHPStan/Larastan, backend tests, runtime dependency audits,
authorization/isolation coverage, connected acceptance and release/runtime smoke
must be green. CI must correspond to the current final head; older/cancelled runs
are not proof.

The actual runtime floor is **PHP 8.4.1+**. Older PHP 8.3 references are stale and
must not be advertised as verified compatibility.

If execution is externally interrupted, preserve the latest verified SHA/CI and
exact first unfinished behavior in Git. The next session resumes from repository
evidence, not guesses.