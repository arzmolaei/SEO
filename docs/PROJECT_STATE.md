# SEO OS — Durable Project State & Handoff

Last checkpoint: 2026-10-22

This file is the durable source of truth for continuing SEO OS across agent
sessions. **Do not reconstruct project state from chat history when this file and
Git disagree. Git + CI win.**

## Stable baseline

- Repository: `arzmolaei/SEO`.
- Stable branch: `main`.
- Foundation + Keyword Core was reviewed and merged through PR #1.
- Keyword Intelligence was reviewed and merged through PR #2.
- Current `main` baseline after Keyword Intelligence:
  `95bb5a014834590c30ff2562615b050b71c997f0`.
- Active integration branch: `feature/self-host-installer` through PR #4.
- Never develop directly on `main`, reset an active branch, force-push over
  unexpected movement or create a duplicate subsystem PR.

Always fetch current heads and exact-head CI before new code. Recorded hashes are
evidence, not permission to assume Git has not moved.

## Completed product slices

### Foundation + Keyword Core

Complete and integrated. The slice includes Laravel 13 + Inertia/React/TypeScript,
Persian-first RTL/LTR, auth/onboarding, Workspace/Membership/Project isolation,
Project shell, Persian keyword normalization preserving original source text,
streaming/resumable keyword import, canonical keyword/provenance/metric history,
server-side Keyword Grid, Saved Views, transactional bulk changes/audit/safe Undo,
allowlist-first runtime release packaging and connected foundation acceptance.

Detailed evidence: `docs/release-readiness/foundation-keyword-core.md`.

### Keyword Intelligence

Complete and integrated. Tasks 1–11 provide intent history, deterministic Persian
classification, project-scoped Review Queue, relational/reversible clusters,
manual merge/split/move/lock with safe Undo, existing/planned Pages, mapping
history, deterministic mapping-conflict review, explainable local priority and the
connected Grid/Intent/Clusters/Mapping workspace.

Keyword Intelligence never introduced crawler, SERP, vector or AI dependencies.
Manual decisions/locks survive recalculation and core workflows stay usable without
international APIs.

Detailed evidence: `docs/release-readiness/keyword-intelligence.md`.

### Self-host Installer — implementation and acceptance complete

Tasks 1–8 are implemented, connected-acceptance verified and whole-branch reviewed
on `feature/self-host-installer`. Do not restart the installer plan from stale chat.

Implementation evidence before the final documentation commits:

- implementation SHA: `75772ff85cc1eda7da8330af43dc7d89161c8954`;
- permanent CI: `39859189369` — success;
- PHP 8.4 and PHP 8.5 compatibility: success;
- normal backend suite: **310 passed, 1 connected-only skip, 1853 assertions**;
- MariaDB 10.11 connected installer acceptance: success;
- frontend formatter/lint, TypeScript and production build: success;
- Pint and PHPStan/Larastan: success;
- runtime npm and Composer security audits: success;
- runtime-only Composer install: success;
- release-tree build and real HTTP `/install` smoke: success.

The installer now provides:

1. DB-independent host preflight with PHP 8.4.1+ and local runtime checks;
2. browser-managed production environment and persistent APP_KEY;
3. real MySQL/MariaDB capability verification with sanitized failures;
4. resumable in-process Laravel migrations without shell commands;
5. exactly-once first owner/workspace bootstrap with local password rules;
6. public registration disabled by default after self-host bootstrap;
7. scheduler heartbeat, real database queue probe and shared-host cron commands;
8. explicit acknowledgement for unobserved external execution while true failures
   remain blocking;
9. final revalidation of current truth rather than journal markers alone;
10. `.installed` lock written last, owner authenticated and installer routes locked;
11. endpoint-scoped installer throttling that does not break a normal multi-step
    installation;
12. a runtime-only release package whose installer boots without Node/npm/Composer
    on the production host.

The first MariaDB diagnostic exposed a shared-throttle 429 regression during normal
installation; it was fixed with a named endpoint-scoped limiter and the permanent
MariaDB acceptance subsequently passed.

Detailed evidence: `docs/release-readiness/self-host-installer.md`.

PR #4 must still receive a fresh permanent CI success on the final
documentation-complete SHA before merge. Older green evidence does not replace that
gate.

## Fonts / self-host rule

- Default interface font: IRANSansX FaNum.
- Selectable alternatives: Yekan Bakh and Vazirmatn.
- Vazirmatn is bundled locally from its OFL source for release builds.
- Proprietary IRANSansX/Yekan Bakh binaries are never committed publicly; licensed
  files are installed into the ignored private-font directory.
- Core runtime must not depend on an international font/CDN request.

## Exact next lifecycle work

After PR #4 final-head CI and merge, the first usable self-host lifecycle gate
continues in this order:

1. **Persian Export** — real project data, UTF-8/Persian correctness, streaming and
   project/access isolation.
2. **Backup + Restore** — database, uploads and settings with a versioned checksum
   manifest; restore into a clean installation and verify users, authorization and
   SEO data.
3. **Update + interrupted recovery/rollback** — compatibility checks, mandatory
   pre-update backup, migrations, health, interruption/failure recovery and real
   rollback fixtures.
4. **Final lifecycle acceptance** — clean release install -> real project/import/
   intelligence workflow -> export -> backup/restore -> update/recovery ->
   operational health.

Do not start Site Intelligence/crawler, Search Console, SERP, AI or vector work
before that lifecycle gate is deliberately completed.

## Mandatory continuation protocol

Before changing code in a new agent/session:

1. Read `AGENTS.md`.
2. Read this file and `docs/HANDOFF.md` completely.
3. Read release readiness, `docs/DELIVERY_PLAN.md`, master spec and the current
   active spec/plan.
4. Fetch current `main`, active branch heads and current CI.
5. Continue only the genuinely next subsystem; do not resurrect completed tasks.
6. Use TDD for bounded behavior: RED -> correct GREEN -> refactor -> verification.
7. Keep permanent CI gates intact; diagnostic workflows must not survive as a
   weakened pipeline.
8. Update durable state at verified subsystem boundaries.
9. Do not stop for routine user confirmation; continue autonomously task-by-task.
10. Required architecture/spec review gates for a genuinely new subsystem still
    apply; standing autonomy does not falsify artifact approval.

## Definition of trustworthy progress

Code existence is not completion. Applicable formatter/lint, TypeScript, production
frontend build, Pint, PHPStan/Larastan, backend tests, runtime dependency audits,
authorization/isolation coverage, connected acceptance and release/runtime smoke
must be green. CI must correspond to the current final head; older, cancelled or
stale runs are not proof.

The actual runtime floor is **PHP 8.4.1+**. Older PHP 8.3 references are stale and
must not be advertised as verified compatibility.

If execution is externally interrupted, preserve the latest verified SHA/CI and
exact first unfinished behavior in Git. The next session resumes from repository
evidence, not guesses.
