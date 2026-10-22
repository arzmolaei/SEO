# SEO OS — handoff to the next implementation agent

Checkpoint: 2026-10-22. The user requested that development pause and all current
work/explanations be committed for another AI. This handoff does not mean the
whole product is complete.

## Start here

Read `AGENTS.md`, `docs/PROJECT_STATE.md`, the foundation release-readiness report,
the master product spec, the active Keyword Intelligence plan and its progress
file. Fetch origin and check actual HEAD/latest CI; repository evidence wins.

- Repository: `arzmolaei/SEO`.
- Continue **`feature/keyword-intelligence`**; never reset it or develop on main.
- Foundation + Keyword Core: completed, reviewed and merged through PR #1.
- Keyword Intelligence Tasks **1–8 verified**.
- Latest verified implementation: `d19e4ab390668ed606b8414c069e1a197a4e768e`.
- Permanent CI: [39804656866](https://github.com/arzmolaei/SEO/actions/runs/39804656866), success on that exact implementation SHA.
- Open subsystem PR: [#2](https://github.com/arzmolaei/SEO/pull/2). Do not create a duplicate or merge before Task 11 acceptance/review/final-head CI.
- Documentation-only handoff commits may follow this implementation SHA. Check their CI separately.

## Delivered since Task 4

5. Deterministic Persian lexical candidates with explainable evidence, scalable
   blocking, safe opt-in high-confidence application and idempotent review.
6. Transactional merge/split/move/lock, attributed history and revision-safe Undo.
7. Existing/planned pages, conservative local URL normalization and stable promotion.
8. Cluster target types, one current mapping, remap history, source attribution,
   atomic page promotion and protection against stale cluster Undo.

Task 8 verification: 204 backend tests / 1166 assertions, full Pint and PHPStan;
8 mapping tests / 32 assertions on actual MariaDB 10.11. Final CI also passed
frontend lint/TypeScript/build, PHP compatibility, runtime audits and release
package/runtime-only smoke gates. Independent mapping review found no actionable
security/data-integrity findings. See progress/specs for exact earlier evidence.

## Exact next work — Task 9

No Task 9 production code exists. Its bounded design is:
`docs/superpowers/specs/2026-10-22-mapping-conflicts-design.md`.

Six test contracts were run and genuinely failed because the new detector does
not exist. The user requested handoff before implementation. Their exact source
and RED result are preserved in:
`docs/superpowers/contracts/2026-10-22-mapping-conflicts-red.md`.
Copy that source back into `tests/Feature/Keywords/KeywordMappingConflictTest.php`
and reconfirm RED. Then implement Task 9, including remaining invalid-target and
HTTP review-resolution coverage. Do not mistake this pending test document for
a completed detector or remove existing verification gates.

Warnings must be deterministic, evidence-based and reviewable, never silently
remap. Same intent alone is not a conflict; require shared meaningful topic.
Missing metrics are not high demand. Resolved evidence stays closed until changed;
stale acknowledgements must fail. Keep queries/project state bounded and avoid
all-pairs comparisons. Complete Task 9 with final-head CI before Task 10, then
Task 11 connected UI/acceptance/whole-branch review.

## Critical integration invariants

- Preserve original keyword data, manual intent, locked clusters and memberships.
- All cluster/mapping/page mutations use project-first serialization and stable
  row-lock ordering, service authorization and project-scoped lookups.
- Mapping changes increment cluster revision: old membership Undo must not erase
  later mapping decisions. Page promotion does this for active AND archived sources.
- `PromotePageTargets` uses Laravel event autodiscovery only. Manual registration
  duplicates execution; an exactly-once revision regression test covers this.
- Merge archives source identities. Do not reactivate an empty source; primary
  membership is mandatory for active clusters.
- UI integration is Task 11. Services existing does not mean cluster/page/mapping
  screens, generation triggers or the entire workflow are already usable.
- No crawler/SERP/AI/vector service in this slice. Crawler follows stable mapping.
- Commercial font binaries, `.env`, secrets and local runtime fixtures stay ignored.

## Engineering and verification

Use RED → confirm correct RED → minimal GREEN → refactor → meaningful focused
verification → full permanent CI → semantic commit/push. Diagnose failures before
fixing. Use official formatter output; full `composer lint:check` must actually
finish successfully (dirty-only formatting previously missed new-file indentation).
Do not add tests that merely mirror implementation or repeat unchanged broad gates.
Each task is separate; do not ask the user for routine permission to continue.
Never force-push, overwrite history, weaken gates or infer success from cancelled CI.

The current Composer/Laravel dependency floor is **PHP ^8.4.1** despite older spec/
AGENTS references to 8.3. Do not advertise 8.3 runtime compatibility or downgrade
Laravel blindly; reconcile supported-runtime documentation in the release work.

In this workspace PHP is provided through Docker, not the host:
`docker exec seo-os-dev php artisan test`. The `seo-os-mariadb` container uses
MariaDB 10.11 on a private test network. Do not rely on these local containers,
ignored fixtures or scratch logs surviving into another environment; recreate
normal development dependencies from repository manifests as needed.
Frontend Wayfinder generation needs PHP; generate before TypeScript/build.

## What still prevents a complete usable product

Task 9 conflicts, Task 10 priority/Ready guards and Task 11 connected UI/acceptance
are unfinished. The wider SEO OS product is also unfinished. Follow
`docs/DELIVERY_PLAN.md` and the master spec after the current slice.

A self-host release additionally needs real fresh-install, normal-use, Persian
export, backup/restore and update/interruption/recovery acceptance. Installer,
health and packaging foundations alone do not prove those flows. Crawler/site
intelligence, technical SEO, content/tasks/internal linking and performance/
opportunities remain later master-plan work. Never present these as delivered.

If interrupted, record the last verified SHA/CI and exact first unfinished task
in Git. Continue from evidence; do not rebuild completed foundations or promise
background execution after the session ends.
