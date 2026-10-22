# SEO OS — Durable Project State

This file is the continuity checkpoint for humans and AI agents. Chat history is **not** the source of truth; this repository is.

Last updated: 2026-10-22

## Current repository state

- Repository: `arzmolaei/SEO`
- Active completed slice branch: `feature/foundation-keyword-core`
- Last verified implementation HEAD before this state-file update: `42ce87681fe7d475d97d6d0454e9e4b237e1d8cd`
- Fresh CI on that HEAD: GitHub Actions run `39782868570` — **SUCCESS**
- Branch is not merged into `main` yet.
- No open PR from `feature/foundation-keyword-core` to `main` at the time of this update.

## Authoritative documents — read in this order

1. `PROJECT_STATE.md` — current continuation checkpoint.
2. `AGENTS.md` — engineering/verification rules.
3. `docs/superpowers/specs/2026-10-22-seo-os-design.md` — product architecture and product principles.
4. `docs/superpowers/plans/2026-10-22-foundation-keyword-core.md` — implementation plan for the completed first slice.
5. `docs/release-readiness/foundation-keyword-core.md` — reviewed release/readiness evidence and next subsystem boundary.

If chat memory conflicts with repository state, trust the repository and fresh verification.

## What is complete

The **Foundation + Keyword Core vertical slice is complete**, reviewed, acceptance-tested, and release-readiness documented. It is the foundation slice, not the entire SEO OS product.

Implemented and verified in this slice:

- Laravel 13 + Inertia 3 + React + TypeScript production foundation.
- Permanent CI: formatting/linting, TypeScript, production build, Pint, PHPStan, backend tests, runtime npm audit, runtime Composer audit, compatibility/release checks.
- Persian-first locale with English-ready RTL/LTR contract.
- Local/self-hosted asset strategy.
- Secure local auth foundation, onboarding state, passkey/2FA-capable Laravel primitives.
- Workspace + memberships + six roles + last-owner invariant.
- Strict workspace/project authorization boundaries and guessed-ID protection.
- Project shell / Command Center foundation + Guided/Expert state.
- Persian keyword normalization preserving original source text.
- Import staging, column mapping validation, row diagnostics and provenance preservation.
- Streaming CSV parser supporting UTF-8 BOM and UTF-16LE/BE.
- Large-file streaming, checkpoint/retry and idempotent staging.
- Canonical project-scoped keywords, source provenance and metric history.
- Idempotent commit and no cross-project canonical merging.
- High-performance server-side Keyword Grid: search/filter/sort/pagination, safe allowlists, status/source/volume filtering.
- Keyword Grid UX foundation including column visibility, keyboard row navigation, loading/error/retry states.
- Saved Keyword Views with versioned validated state and project/user scoping.
- Bulk keyword status updates with Activity Events, ChangeSets and safe undo conflict checks.
- Installer/System Health foundations.
- Release-package contract/builder and production runtime smoke testing.
- End-to-end acceptance test covering workspace/project -> CSV -> stage -> normalize -> canonical commit -> grid -> saved view -> bulk update -> audit -> undo.
- Whole-branch focused review and release-readiness report.

## Font system status

The design system supports:

- **IRANSansX FaNum** — default UI font.
- **Yekan Bakh FaNum** — selectable.
- **Vazirmatn** — selectable and bundled locally under OFL.

IRANSansX/Yekan Bakh binaries are intentionally not committed to the public repository because they are proprietary/licensed assets. Licensed local ZIP installation is supported by the font installer script and private font directory is ignored by Git.

## Evidence

The first-slice release-readiness report is:

`docs/release-readiness/foundation-keyword-core.md`

It records the completed user workflow, quality gates, whole-branch review, release-package hardening, self-host baseline, safety guarantees, UI foundation, and deliberately deferred systems.

The acceptance workflow is:

`tests/Feature/Acceptance/FoundationKeywordCoreAcceptanceTest.php`

## What is NOT complete yet

The full SEO OS product is intentionally not complete. Major remaining subsystems include:

- Keyword Review Queue and intent classification.
- Clustering, confidence, cluster merge/split and primary/secondary keyword logic.
- Keyword-to-page mapping, planned pages and cannibalization conflicts.
- Priority/Opportunity logic on top of keyword/page relationships.
- Site Crawler / Site Intelligence / Technical SEO snapshots and issues.
- Content workflow and briefs.
- Internal linking engine.
- Search Console/performance integrations and Opportunity Engine.
- Full backup/restore/update/server-migration delivery flows beyond the current foundations.
- Advanced reports, CMS integrations and optional AI enhancement layer.
- SaaS billing/entitlements/commercialization features.

## Next subsystem — exact continuation target

The next implementation slice is:

**Keyword Intelligence: Review Queue + Intent + Clustering + Mapping**

Intended sequence:

`Review Queue -> Intent -> Cluster -> Primary/Secondary -> Existing/Planned Page -> Mapping Conflicts -> Priority`

Crawler/Site Intelligence comes **after** this relationship layer is stable so crawl data attaches to meaningful SEO entities instead of becoming an isolated audit report.

## Next exact repository actions

1. Do **not** rewrite the completed foundation slice.
2. Re-read the release-readiness report and compare `feature/foundation-keyword-core` against `main`.
3. Confirm fresh CI is green on current feature HEAD.
4. Open a PR from `feature/foundation-keyword-core` to `main` with release-readiness evidence.
5. Review the final diff; do not merge around failing checks.
6. After integration, create a new feature branch for the next slice, recommended name:
   `feature/keyword-intelligence`
7. Write the next bounded implementation plan before implementation.
8. Execute task-by-task using RED -> GREEN -> refactor -> fresh CI.

## Continuation protocol for any new AI/chat session

When a new chat starts, the user should only need to say something like:

> Continue SEO OS from the repository state. Read `PROJECT_STATE.md` and `AGENTS.md`, inspect the current branch/CI, and continue without re-planning completed work.

The agent must then inspect GitHub itself before modifying code. It must **not** rely on a pasted conversation transcript as the primary state source.

## No-passive-stop rule

When the user says `continue`, continue autonomously until one of these is true:

1. The active bounded task/milestone is complete **and** has fresh verification evidence; or
2. A real external blocker requires user action (credential, paid/licensed asset, account permission, unavailable external system); or
3. Safety rules require stopping.

Do **not** stop merely because CI is pending, because a formatter/static-analysis check failed, or because the conversation is long. Poll pending CI, diagnose failures, fix root causes, re-run verification, and update this state file at meaningful milestones.

If the chat UI reaches its maximum conversation length, create/update the durable checkpoint first when possible; then the next chat resumes from the repository, not from memory.

## Definition of a trustworthy continuation

A future agent should be able to answer these from Git alone before coding:

- What branch is active?
- What was the last verified HEAD and CI result?
- What slice is complete?
- What remains deliberately out of scope?
- What is the exact next subsystem?
- Which specs/plans are authoritative?
- Which quality gates must stay green?

If any answer is unclear, inspect repository history/docs/tests before making product changes.
