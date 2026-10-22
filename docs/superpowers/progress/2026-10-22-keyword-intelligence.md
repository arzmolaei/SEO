# Keyword Intelligence Progress

Branch: `feature/keyword-intelligence`
Plan: `docs/superpowers/plans/2026-10-22-keyword-intelligence.md`

## Task status

- Task 1 — Intent domain and override history: **verified**
  - Manual assignments are immutable history entries.
  - Manual current intent wins over later automatic suggestions.
  - Cross-project assignment is rejected.
  - Intent changes create a ChangeSet and attributed ActivityEvent.
  - Full CI passed on run `39784623206`.
- Task 2 — Deterministic Persian rule-based intent engine: **verified**
  - Rule configuration is explainable and deterministic.
  - Original text is preserved while normalized text is used for matching.
  - Conflicting intent signals become Mixed/Medium rather than false certainty.
  - No-signal phrases remain Unknown/Low.
  - Full permanent CI passed on run `39786352782`.
- Task 3 — Intent Review Queue: **verified**
  - Bounded design: `docs/superpowers/specs/2026-10-22-intent-review-design.md`.
  - RED contracts: `770cdb5`, `3950795`; implementation: `7e871e0`.
  - Browser-discovered RTL sidebar obstruction fixed in `a859100`.
  - Full CI on `a85910065ec85b94d86ae301767e9405be1da0a3`: run `39788659107`, success.
  - 148 backend tests / 968 assertions; static analysis, style, TypeScript, frontend checks/build and runtime audits passed.
  - Independent review: no Critical/Important findings. Deferred minor: actions/reload on page 2+ return to page 1.
  - Runtime-only release tree built and booted locally (`Laravel Framework 13.38.0`), and passed CI release smoke.
  - Persian browser workflow: queue request -> actual database worker -> suggestions -> keyboard accept -> inline change -> keyboard skip -> retry without reopening. No page errors or mobile overflow.
- Task 4 — Cluster domain/membership: **verified**
  - Design: `docs/superpowers/specs/2026-10-22-cluster-domain-design.md`.
  - Observed 12 RED tests in `d55fa32`; GREEN implementation `e720c0a`.
  - Full CI: run `39790364710` on `e720c0a6bf21ea3e9acbf95b4ace02651ae92e43`, success.
  - Full local suite: 160 tests / 1007 assertions; PHPStan/Pint/frontend lint/TypeScript passed.
  - The 12 cluster tests passed on actual MariaDB 10.11, including composite foreign keys and primary deletion restrictions.
  - A MariaDB transaction probe confirmed project deletion cascades correctly with populated clusters; the probe was rolled back.
  - Independent task review: no actionable findings in the supported creation boundary.
  - Creation is transactional/audited, requires the primary as an explicit member, rejects cross-project/duplicate membership and never silently moves a keyword.
  - Decision: one current cluster membership per canonical keyword. Eloquent guards protect primary replacement/removal; future mutation services must acquire locks and never bypass those guards with bulk/raw writes.
  - This task provides domain/application foundations; clustering algorithms and UI are not yet delivered.
- Task 5 — Lexical candidate generation/application: **verified**
  - RED contract commit `3f33506`; additional real REDs covered HTTP visibility, browser payload bounds and accurate shared-anchor evidence.
  - 177 full-suite tests / 1072 assertions passed; PHPStan, Pint, TypeScript, frontend build/check and runtime audits passed locally.
  - 21 related feature tests passed on MariaDB 10.11.
  - Real Chromium: cluster evidence visible, intent-only edit absent, keyboard accept resolves; no JS page errors.
  - 10k unrelated corpus completed in roughly 0.08 seconds; no all-pairs comparisons.
  - Independent review found no security/transaction blockers. Evidence-label issue was regraded for the explainability contract and fixed RED/GREEN.
  - Project-first serialization intentionally trades per-project mutation concurrency for race safety. Full snapshots remain server-side.
  - Auto-apply is opt-in and only High; uncertainty is idempotent Review Queue, with same-evidence resolved decisions retained.
  - Final-head CI `39799527623` on `9fb589035cec7c3f430e5959b1113dedfb012504`: success.
  - Task 6 now active; separate RED/implementation boundary.
- Task 6 — Cluster operations/Undo: **verified**
  - Initial 8 contract tests observed RED in `e571fbb`; 3 additional RED regressions cover lock/review independence, pre-lookup authorization and empty-source reactivation.
  - Full local suite: 188 tests / 1103 assertions; PHPStan and Pint passed. No frontend code changed in this task; permanent CI still runs its frontend/build/audit/release gates.
  - 11 operation/Undo tests passed on actual MariaDB 10.11.
  - Independent review found no service-path blockers; defensive unarchive-primary invariant was strengthened with a RED/GREEN test.
  - Merge archives sources; split uses existing creation; primary movement requires explicit remaining replacement. All edits invalidate automation reuse and audit actor/before/after.
  - Undo uses exact snapshots/revisions; intervening edits or repeated undo conflict. Restoration uses model operations atomically under project-first serialization.
  - Future mapping services MUST increment cluster revision when targets change, preventing Undo from erasing later mapping decisions.
  - Final-head CI `39801676900` on `9adedd5238368b8d23b13552066bbd8935974c5a`: success.
  - Task 7 now active, with its own RED/verification boundary.
- Task 7 — Existing/planned page foundation: **implemented; final-head CI pending**
  - Initial 7 behavior tests observed RED before implementation; additional default-type and IDN identity regressions observed RED then GREEN.
  - Full suite: 196 tests / 1134 assertions, PHPStan and Pint green. Seven DB-sensitive page tests passed on MariaDB 10.11 (29 assertions).
  - Independent review found transitional IDNA domain merging; fixed with non-transitional conversion and Unicode/A-label/distinct-host tests.
  - URLs are never fetched; original input is retained, canonical identity uses a project-scoped SHA-256 index with collision comparison. Path case/query order/HTTP-vs-HTTPS are preserved.
  - Promotion preserves page identity and rejects URL collisions atomically; registration never overwrites an existing title.
  - Page changes have explicit page_id audit entries; keyword Undo validates keyword-only payloads and its existing suite remains green.
  - Next action: final-head CI, then record verified SHA/run and enter Task 8 automatically.
- Tasks 8–11: pending.

## Execution rules

Continue RED → GREEN → full CI verification. Do not advance a task while its current HEAD is unverified. Human/manual overrides and locks must never be silently overwritten by automation.
