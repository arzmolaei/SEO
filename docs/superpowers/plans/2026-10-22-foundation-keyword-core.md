# Foundation + Keyword Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` (or subagent-driven development when available) and implement task-by-task with TDD. Each product behavior must be observed RED before GREEN.

**Goal:** Deliver the first production-quality vertical slice of SEO OS: Laravel/React foundation, self-hosted runtime defaults, bilingual RTL/LTR shell, authentication, workspace/project isolation, Persian-safe keyword ingestion, provenance-aware storage, scalable keyword grid, saved views, bulk changes, audit history, and installer/health foundations.

**Architecture:** Laravel 13 modular monolith using React + Inertia + TypeScript. Domain modules communicate through explicit application services. MariaDB 10.11+ is preferred and MySQL 8+ is supported; SQLite may be used in CI. Database queues and local storage are the self-host defaults.

**Tech Stack:** PHP 8.3+, Laravel 13, Inertia Laravel 3, React 19, TypeScript, Vite/Vite+, Tailwind CSS 4, MariaDB/MySQL, PHPUnit 12, PHPStan/Larastan, Laravel Pint, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-22-seo-os-design.md`

## Global Constraints

- PHP >= 8.3; production runtime does not require Node.js after assets are built.
- Persian is the initial default locale; English and RTL/LTR are foundational.
- Core runtime assets must not depend on international CDNs.
- Core workflows must not require AI or an international API.
- Default self-host queue: database. Default storage: local.
- Imports stage and validate before committing to canonical keyword records.
- Preserve original keyword text; normalization uses a separate value.
- Metrics are source/time-aware and never overwrite competing source values.
- Project/workspace boundaries are authorization boundaries.
- Large keyword collections use server-side filter/sort/pagination; never full browser rendering.
- Bulk/destructive changes are attributable and reversible where technically safe.
- Every user-facing flow includes loading, empty, validation, and error states.

## Review Focus

1. Persian Unicode variants and invisible whitespace must deduplicate without corrupting original text.
2. Re-import from another source must merge canonical identity while preserving independent provenance/metrics.
3. Malformed large imports must remain staged and must not partially corrupt project data.
4. Cross-project access by guessed IDs/query params must fail authorization.
5. Retried background import commit must be idempotent.

---

### Task 1 — Runtime contract and permanent quality gate

**Files:** `.env.example`, `.github/workflows/ci.yml`, `vite.config.ts`, `tests/Feature/System/EnvironmentContractTest.php`, `docs/development.md`, `AGENTS.md`.

**Produces:** reproducible Laravel 13/Inertia 3 foundation; `SEO OS` name, `fa` locale, `en` fallback, database queue, local filesystem; CI running frontend check/typecheck/build, PHP style/static analysis/tests, and runtime dependency audits.

- [x] Bootstrap from official Laravel React starter pinned to a known SHA and verify Laravel 13/Inertia 3 before committing.
- [x] Establish permanent CI and explicit Wayfinder generation before TypeScript checks.
- [ ] Observe runtime contract test fail for product defaults.
- [ ] Set minimal runtime defaults and observe full CI GREEN.
- [ ] Add development/agent verification documentation.

### Task 2 — Bilingual RTL/LTR application shell and local design foundation

**Files:** locale/direction support in backend and Inertia shared props, `resources/js/i18n/*`, design tokens, root shell/layout tests.

**Produces:** typed `fa|en` locale contract, `rtl|ltr` direction, Persian default shell, English-ready dictionaries, no required external font/CDN dependency.

- [ ] RED tests for `fa -> rtl`, `en -> ltr`, HTML `lang/dir`, and shared locale props.
- [ ] Implement locale/direction contract.
- [ ] Add local semantic design tokens and locally hosted font strategy.
- [ ] Verify RTL/LTR, frontend checks, build, and backend tests.

### Task 3 — Authentication and first-run owner baseline

**Files:** existing Laravel Fortify/passkey auth adapted for SEO OS, auth pages/layouts, registration/login tests.

**Produces:** secure local email/password auth without required OAuth; first account may become initial workspace owner through the onboarding flow.

- [ ] RED tests for login/logout/invalid credentials/session regeneration/guest protection.
- [ ] RED registration validation tests.
- [ ] Implement branded localized auth experience with existing Laravel security primitives.
- [ ] Verify complete auth suite and frontend build.

### Task 4 — Workspace and membership domain

**Files:** Workspace, Membership, WorkspaceRole, migrations, policies, application services, tests.

**Produces:** `Workspace`, membership roles (`owner`, `admin`, `seo_manager`, `seo_specialist`, `content_writer`, `viewer`) and explicit authorization boundary.

- [ ] RED create-workspace and membership-role tests.
- [ ] Implement models/migrations/services/policies.
- [ ] Verify owner invariants and unauthorized membership changes.

### Task 5 — Projects and strict project isolation

**Files:** Project model/domain, create/update services, project policies/routes/tests.

**Produces:** projects owned by a workspace; users can only read/change projects reachable through membership/project access rules.

- [ ] RED cross-workspace/project isolation tests including guessed IDs.
- [ ] Implement project model/service/policies.
- [ ] Verify project create/list/show and isolation.

### Task 6 — Project shell and Command Center skeleton

**Files:** Inertia project routes/pages/layout, sidebar/navigation, project context/shared props, tests.

**Produces:** persistent project workspace shell with Overview, Keywords, Pages, Site Audit, Content, Internal Links, Tasks, Performance, Reports, Integrations, Settings.

- [ ] RED authenticated project shell/inertia-prop tests.
- [ ] Implement modern project shell with empty/loading/error states.
- [ ] Add Guided/Expert mode state placeholder without blocking navigation.
- [ ] Verify RTL and responsive structure.

### Task 7 — Persian keyword normalizer

**Files:** Keywords domain normalizer/value objects and unit tests.

**Produces:** deterministic `normalize(string): string` while preserving original input at persistence boundary.

- [ ] RED corpus tests for Arabic/Persian Yeh/Kaf, Unicode normalization, ZWNJ/zero-width chars, repeated whitespace, safe punctuation handling.
- [ ] Implement minimal deterministic normalizer.
- [ ] Add idempotence/property-style cases (`normalize(normalize(x)) === normalize(x)`).
- [ ] Verify no destructive transformation of stored original text.

### Task 8 — ImportBatch staging and column mapping

**Files:** ImportBatch/StagedKeywordRow models/migrations, mapping DTO/validator, import controller/service, tests.

**Produces:** uploaded CSV import becomes a staged batch with source, original filename, mapping, row counts, warnings/errors, status; no canonical keyword mutation yet.

- [ ] RED tests for valid mapping, missing keyword column, invalid numeric fields, malformed/empty rows, authorization.
- [ ] Implement staging schema and mapping validation.
- [ ] Verify invalid input stays staged with row-level errors and zero canonical keyword writes.

### Task 9 — Streaming CSV parsing and resilient staging

**Files:** streaming CSV reader/application job, import progress/status, tests.

**Produces:** chunked/streamed parsing that does not load a large file entirely into memory.

- [ ] RED tests for quoted commas/newlines, BOM/encoding handling, large fixture, interrupted job/retry behavior.
- [ ] Implement streaming parser and progress checkpoints.
- [ ] Verify failure state retains diagnostics and remains retryable.

### Task 10 — Canonical keyword commit with provenance and metrics

**Files:** Keyword, KeywordSource, KeywordMetric migrations/models/services/jobs/tests.

**Produces:** canonical keyword identity per project + normalized text; multiple source records and time-aware metrics; idempotent staged-batch commit.

- [ ] RED tests for canonical dedup within a project.
- [ ] RED re-import test from a second source preserving both source/metric histories.
- [ ] RED retry/idempotency test.
- [ ] Implement transactional commit service/job and unique/index constraints.
- [ ] Verify no cross-project merging.

### Task 11 — High-performance Keyword Grid

**Files:** keyword query/filter DTOs, controller/resource, Inertia page/data-grid components/tests.

**Produces:** server-side search/filter/sort/pagination with explicit allowed columns/operators and scalable UI virtualization/pagination.

- [ ] RED query tests for search, numeric filters, source/status filters, sort, invalid operators, project isolation.
- [ ] Implement indexed query service and safe filter parser.
- [ ] Implement grid with column visibility/freeze, keyboard selection, empty/loading/error states.
- [ ] Verify browser never receives the entire dataset.

### Task 12 — Saved Views and filter validation

**Files:** SavedKeywordView model/migration/service/endpoints/components/tests.

**Produces:** per-user/workspace/project named views storing versioned validated filter/sort/column state.

- [ ] RED tests for create/update/apply/default view and authorization.
- [ ] RED test rejecting unknown fields/operators in stored filters.
- [ ] Implement schema/versioned serialization and UI.
- [ ] Verify views remain project-scoped.

### Task 13 — Bulk updates, Activity Events, and ChangeSets

**Files:** Audit module models/migrations/services, bulk keyword action, tests.

**Produces:** auditable bulk tag/status/value changes with before/after ChangeSet data and safe undo for supported actions.

- [ ] RED bulk update authorization/validation tests.
- [ ] RED audit attribution and undo tests.
- [ ] Implement transactional bulk service + activity/change records.
- [ ] Verify partial failure cannot leave an untracked half-applied change.

### Task 14 — Installer and System Health foundation

**Files:** requirement-check services, install-state guard, health checks/routes/pages/tests.

**Produces:** CMS-like `/install` foundation and System Health abstractions for PHP/extensions, writable storage, database, queue/scheduler capability, disk, application state.

- [ ] RED environment checker tests using injected probes.
- [ ] Implement checks without shell-only assumptions.
- [ ] Add install lock/state and safe post-install redirect.
- [ ] Verify normal web installation does not require Node/Composer on target production host.

### Task 15 — Release/security quality gates

**Files:** CI/release scripts/docs/security baseline tests.

**Produces:** reproducible test/typecheck/build/static-analysis/runtime audits, dependency-review rules, release-package contract excluding dev/runtime build dependencies.

- [ ] Pin/verify framework/toolchain contracts.
- [ ] Investigate dev-only npm advisories without `--force`; runtime audit must remain clean/high-severity gated.
- [ ] Add release package verification (built assets present; `.env`, `node_modules`, dev secrets absent).
- [ ] Verify Composer runtime audit.

### Task 16 — First vertical-slice acceptance

**Files:** acceptance tests/fixtures/documentation and any narrowly scoped fixes discovered by acceptance.

**Produces:** a user can install/boot, authenticate, create workspace/project, stage a real keyword CSV, normalize/deduplicate, commit provenance-aware keywords, browse/filter the grid, save a view, bulk update with audit history.

- [ ] Write end-to-end backend acceptance test before final integration fixes.
- [ ] Run full PHP suite, PHPStan/Pint, frontend check/typecheck/build, runtime audits.
- [ ] Validate Persian/RTL core screens and no required external asset dependency.
- [ ] Produce release-readiness notes and next subsystem boundary (Clustering/Mapping).

---

## Execution Rules

- Never implement unrelated refactors while completing a task.
- If a check fails, diagnose root cause before patching.
- Every task ends with a full relevant verification run; CI is the source of truth when the local sandbox lacks Composer/network access.
- Never merge the feature branch until the complete plan has passed whole-branch review.
- The spec is authoritative when this plan and implementation details conflict.
