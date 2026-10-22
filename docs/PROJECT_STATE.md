# SEO OS — Durable Project State & Handoff

Last checkpoint: 2026-10-22

This file is the durable source of truth for continuing SEO OS across ChatGPT/Codex/agent sessions. **Do not reconstruct project state from chat history when this file and Git disagree. Git + CI win.**

## Stable baseline

- Repository: `arzmolaei/SEO`
- Stable branch: `main`
- Foundation vertical slice was merged through PR #1 (`Feature/foundation keyword core`).
- Pre-checkpoint merge baseline: `171ca8b953130559c06be2a56cbd002fa98fe76c`.
- The old working branch `feature/foundation-keyword-core` was compared with `main` after the merge and had no file-tree differences. Do not use it as the starting point for the next subsystem.
- The latest verification observed on that old branch before this handoff was CI run `39782868570` on commit `42ce87681fe7d475d97d6d0454e9e4b237e1d8cd`, conclusion: success.

Always re-read the current `main` head and its CI before doing new implementation work; the hashes above are evidence/history, not permission to assume the repository has not moved.

## Completed vertical slice: Foundation + Keyword Core

This slice is complete and reviewed. Do **not** reimplement it in a new session.

Implemented and verified:

1. Laravel 13 + Inertia 3 + React + TypeScript foundation.
2. Persian-first i18n with typed RTL/LTR application direction contract.
3. Auth/onboarding baseline using Laravel/Fortify primitives.
4. Workspace + Membership domain with role boundaries and last-owner invariant.
5. Project domain with hard workspace/project isolation against guessed identifiers.
6. Project shell / Command Center foundation with Guided/Expert mode state.
7. Persian keyword normalization preserving original source text.
8. Keyword import staging with mapping diagnostics, provenance, duplicate warnings and authorization.
9. Streaming CSV ingestion supporting UTF-8 BOM, UTF-16LE/BE, large-file lazy reading, checkpoints and idempotent retry.
10. Canonical project-scoped keyword identity, independent source provenance and metric history.
11. Keyword Grid with server-side search/filter/sort/pagination, status/source/volume filters, safe query allowlists, column visibility, keyboard navigation, loading/error/retry states.
12. Saved Keyword Views with validated/versioned project/user-scoped state.
13. Transactional bulk keyword status changes with ChangeSet/Activity audit records and safe undo.
14. Installer/System Health foundations and self-host runtime checks.
15. Release-package contract + allowlist-first production release tree builder + runtime smoke verification.
16. Whole-slice acceptance flow and whole-branch review.

See `docs/release-readiness/foundation-keyword-core.md` for the detailed evidence and guarantees.

## Fonts / self-host rule

- Default interface font: **IRANSansX FaNum**.
- Selectable alternatives: **Yekan Bakh** and **Vazirmatn**.
- Vazirmatn is bundled locally from its OFL-licensed source for release builds.
- IRANSansX/Yekan Bakh proprietary binaries are **not** committed to the public repository. Licensed ZIPs are installed locally into the ignored private font directory using the repository font installer.
- Core runtime must not depend on an international font/CDN request.

## Active implementation — resume here

Continue existing `feature/keyword-intelligence`; do not create or reset it.
Tasks 1–4 are verified (latest boundary `9b1107c`, CI `39790760139`).
Task 5 is in progress: lexical candidate generation, safe application and review.
Source of current execution evidence: `docs/superpowers/progress/2026-10-22-keyword-intelligence.md`.
The branch includes the newer `main` handoff instructions through merge `114277a`.
After Task 5 final-head CI passes, execute Task 6, then remaining tasks in order.
Do not stop for routine confirmation. Preserve unfinished state explicitly if
an external interruption/tool limit occurs. Full install/backup/restore/update
acceptance remains future delivery work, not completed by the current slice.

## Keyword Intelligence subsystem boundary

The next slice is **Keyword Intelligence: Intent + Clustering + Mapping**.

Intended sequence:

`Review Queue -> Intent -> Cluster -> Primary/Secondary -> Existing/Planned Page -> Mapping Conflicts -> Priority`

Do not jump to the crawler first. The crawler/Site Intelligence slice follows after keyword-to-page relationships are stable, so crawl data can attach to meaningful SEO entities rather than becoming an isolated audit report.

### Next-slice design constraints

- Deterministic/rule-based intelligence must work without external AI.
- AI, SERP APIs and external services remain optional enhancement integrations.
- Use confidence-driven behavior: high-confidence automation, medium-confidence suggestion, low-confidence review.
- Every automated/suggested classification must be explainable and reversible where practical.
- Review Queue should concentrate ambiguous work rather than forcing users to inspect the full dataset.
- Cluster merge/split/move operations must preserve history and avoid destructive silent rewrites.
- Mapping must support existing pages and planned pages and expose cannibalization/mapping conflicts.
- Preserve the existing project-scoping and authorization invariants at HTTP and domain-service boundaries.

## Mandatory continuation protocol for any new agent/session

Before changing code:

1. Read `AGENTS.md`.
2. Read this file completely.
3. Read `docs/release-readiness/foundation-keyword-core.md`.
4. Read the architecture/spec and the relevant implementation plan under `docs/superpowers/`.
5. Fetch the current `main` head from GitHub and inspect current CI status.
6. Confirm the working tree/ref you intend to use is based on current `main`.
7. Resume the explicitly named active branch above. Only create a new branch from current main when starting a genuinely new subsystem.
8. Do not copy/rebuild Foundation + Keyword Core just because the new session lacks chat context.
9. Use TDD for each bounded behavior: RED -> minimal GREEN -> verification -> commit.
10. Keep permanent CI gates intact. Diagnostic CI edits must be temporary and removed before the feature is considered verified.
11. Never force-push over unexpected branch movement. Re-read the branch head and reconcile.
12. Update this checkpoint when a substantial subsystem reaches a reviewed, fully green boundary.

## Definition of trustworthy progress

A feature is not considered complete merely because code exists. Completion requires the applicable combination of:

- formatter/lint green;
- TypeScript green;
- production frontend build green;
- Pint green;
- PHPStan/Larastan green;
- backend tests green;
- runtime npm/Composer audits green;
- authorization/isolation coverage where relevant;
- acceptance coverage for connected workflows;
- release/runtime smoke checks where packaging or deployment is affected.

If a session ends because of chat length, tool limits, interruption, or model switch, stop at the last verified commit and update this file when possible. The next session must continue from repository evidence, not from guesses about what the previous assistant was thinking.
