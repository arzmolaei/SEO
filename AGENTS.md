# SEO OS Engineering Rules

This repository is the source of truth for SEO OS.

## Read First

Before changing product behavior, read in this order:

1. `docs/OWNER_INTENT.md` — authoritative product direction, V1 north star, frozen scope, and owner priorities.
2. `docs/PROJECT_STATE.md` — durable cross-session checkpoint and current implementation boundary.
3. `docs/release-readiness/foundation-keyword-core.md` — verified baseline already completed; do not reimplement it.
4. `docs/superpowers/specs/2026-10-22-seo-os-design.md` — ARCHIVED historical context of an abandoned commercial/team design; never a source of scope.
5. The active implementation plan in `docs/superpowers/plans/`, but only if that plan is inside the currently allowed owner scope.

Then fetch the current `main` head, open PRs and CI status before choosing a working branch. Git + CI evidence outrank chat/session memory for implementation status.

`docs/OWNER_INTENT.md` is authoritative for **what the product should optimize for next**. `docs/PROJECT_STATE.md` is authoritative for what has actually been completed and where implementation evidence stopped. Older handoff notes, master-spec phases and delivery history are not standing permission to expand scope.

## Working Method

- Work on a feature branch or isolated worktree; do not develop directly on `main`.
- Never resume an old feature branch for a new subsystem merely because a previous chat used it; branch from current `main` unless the project-state checkpoint explicitly says otherwise.
- Use test-driven development for product behavior: RED → GREEN → refactor.
- A failing check is diagnosed before it is patched. Fix root causes, not symptoms.
- Keep changes scoped to the active task; avoid unrelated refactors.
- Preserve explicit module boundaries and communicate through application services/contracts.
- Never claim a task complete without fresh verification evidence.
- Never commit secrets, `.env`, production credentials, generated private data, or customer data.
- If a chat/model/tool session ends, stop at the last verified commit. The next session must resume from repository evidence, not reconstructed chain-of-thought or guesses.
- Update `docs/PROJECT_STATE.md` when a substantial subsystem reaches a reviewed, fully green boundary.
- Do not start a new product subsystem merely because it appears next in an older roadmap. First confirm it is allowed by `docs/OWNER_INTENT.md`.

## Product Constraints

- PHP 8.3+ is the supported runtime floor (Symfony 7.4 LTS components); permanent compatibility gates are PHP 8.3, 8.4 and 8.5.
- Laravel 13 + Inertia 3 + React + TypeScript are the current application foundation.
- Persian is the initial default locale; English and RTL/LTR support are foundational.
- Core runtime must not require an international CDN, AI provider, OAuth provider, or external API.
- Self-host defaults are database queue + local filesystem storage.
- MariaDB 10.11+ is preferred; MySQL 8+ is supported. SQLite is allowed for tests.
- Production releases must not require Node.js or Composer to run after release assets are built.
- Preserve original SEO input data; normalized/derived values live separately.
- Project/workspace boundaries are authorization boundaries.
- Large datasets require server-side filtering/sorting/pagination; do not render entire collections in the browser.
- Bulk/destructive changes must be attributable and reversible where technically safe.
- The product is a personal SEO workspace for one owner (not team, agency or commercial). Build only the ordered slices in `docs/DELIVERY_PLAN.md`.
- Existing crawler and workspace/role code may remain and must not be broken, but must not be extended; everything listed as out of scope in `docs/OWNER_INTENT.md` must not be built.

## Required Verification

CI is the source of truth when the local environment cannot install dependencies.

Equivalent local checks are:

```bash
composer install --prefer-dist --no-interaction
cp .env.example .env
php artisan key:generate
php artisan migrate --force
npm ci
php artisan wayfinder:generate --with-form
npm run check
npm run types:check
npm run build
composer lint:check
composer types:check
php artisan test
npm audit --omit=dev --audit-level=high
composer audit --no-dev
```

Do not use `npm audit fix --force` as a shortcut. Investigate dependency advisories and upgrade deliberately.

## Generated Files

Wayfinder action/route files are generated and gitignored. Generate them before TypeScript checks:

```bash
php artisan wayfinder:generate --with-form
```

## Definition of Done

Where applicable, a feature includes behavior, validation, loading/empty/error states, authorization, tests, RTL/LTR correctness, accessibility basics, performance considerations, safe migrations, logs/observability, and documentation.

For the current product direction, a feature is not "done" merely because it expands the old master spec. It must either improve the keyword-led SEO workflow, protect the data/lifecycle needed to run that workflow, or fix a demonstrated defect within the allowed V1 boundary.

## Autonomous continuation contract

- Continue an active task autonomously only while it remains inside `docs/OWNER_INTENT.md`.
- Complete one bounded task to verified completion, commit/push it, wait for final-head CI, then continue to the next **approved V1 task** without routine confirmation.
- The user delegates routine product/technical decisions inside that boundary; record material choices in the active spec/progress file. Keep each task separate and preserve all existing security/quality gates.
- Never build out-of-scope items from `docs/OWNER_INTENT.md` (crawler expansion, architecture views, backlinks, SERP/AI providers, Google APIs, CMS, team/agency, SaaS).
- Keep `docs/PROJECT_STATE.md` and the active progress document current at verified boundaries, including exact task, commit, CI evidence, incomplete scope and next action.
- On interruption, the next agent reads Git and these documents and resumes the first unverified **allowed** task. Never redo completed tasks or call an incomplete subsystem a finished product.
- Focus tests on user behavior, authorization, data integrity, concurrency and real acceptance risks. Do not add implementation-mirroring tests or repeat broad gates unless changes/failures justify it.
- A usable self-host release additionally needs verified installation, normal keyword use, export, backup/restore and update/recovery flows. Foundation or Keyword Intelligence completion does not satisfy those release requirements by itself.
- Work through the ordered slices in `docs/DELIVERY_PLAN.md`; after the last slice, fix only problems found in real use.
