# Keyword Intelligence: Intent + Clustering + Mapping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans and implement this plan task-by-task with TDD. Each product behavior must be observed RED before GREEN.

**Goal:** Extend the completed Foundation + Keyword Core slice into a usable keyword intelligence workflow: Review Queue -> Intent -> Cluster -> Primary/Secondary -> Existing/Planned Page -> Mapping Conflicts -> Priority.

**Architecture:** Keep the Laravel 13 modular monolith. Intent, clustering, page-targeting, review, and prioritization are separate domain/application services that operate on existing project-scoped canonical keywords and reuse the existing ChangeSet/Activity audit foundation. Rule-based deterministic behavior is the default; SERP/AI layers remain optional future integrations.

**Tech Stack:** PHP 8.3+, Laravel 13, Inertia Laravel 3, React 19, TypeScript, Vite+, Tailwind CSS 4, MariaDB/MySQL, PHPUnit 12, PHPStan/Larastan, Laravel Pint, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-22-seo-os-design.md` sections 10-15.

**Current evidence:** `docs/superpowers/progress/2026-10-22-keyword-intelligence.md`. Intent Review increment design: `docs/superpowers/specs/2026-10-22-intent-review-design.md`.

## Global Constraints

- Core workflows must work without AI or international APIs.
- Every query/mutation is project scoped and follows existing workspace/project authorization boundaries.
- User overrides are attributable and auditable.
- High-confidence automation may auto-apply only when explicitly configured; medium/low confidence remains reviewable.
- Existing original keyword text and provenance/metric history are immutable inputs to this subsystem.
- No cluster/mapping operation may silently destroy keyword membership or page targeting history.
- UI is Persian-first, RTL-safe, keyboard-friendly, and bilingual-ready.
- Heavy recalculation work must be queue-ready and idempotent.

## Review Focus

1. Ambiguous Persian phrases with mixed commercial/informational signals must not be silently forced to a wrong intent.
2. Re-running classifiers or clusterers must be deterministic/idempotent unless inputs changed.
3. Locked clusters and explicit human overrides must survive automatic recalculation.
4. Cross-project keyword/cluster/page IDs must never be accepted through guessed identifiers.
5. Mapping conflicts must be surfaced as reviewable warnings instead of silently remapping existing targets.

---

### Task 1 — Intent domain model and override history

**Files:**
- Create: `app/Domain/Keywords/KeywordIntent.php`
- Create: `app/Domain/Keywords/IntentConfidence.php`
- Create: `app/Models/KeywordIntentAssignment.php`
- Create: `database/migrations/2026_10_22_010000_create_keyword_intent_assignments_table.php`
- Create: `app/Services/Keywords/AssignKeywordIntent.php`
- Test: `tests/Feature/Keywords/KeywordIntentAssignmentTest.php`

**Produces:** supported intents (`informational`, `commercial`, `transactional`, `navigational`, `local`, `mixed`, `unknown`), confidence, source (`rule|user|future_serp|future_ai`), current assignment and immutable override history.

- [x] RED tests for valid intent assignment, project isolation, manual override attribution, and repeated identical assignment idempotency.
- [x] Implement enums/schema/model/service using existing project authorization and activity/change foundations.
- [x] Verify manual user override wins over non-user automatic suggestions.
- [x] Full CI GREEN and commit `feat: add keyword intent assignments`.

### Task 2 — Deterministic rule-based Intent Engine

**Files:**
- Create: `app/Domain/Keywords/IntentRule.php`
- Create: `app/Services/Keywords/ClassifyKeywordIntent.php`
- Create: `config/keyword-intelligence.php`
- Test: `tests/Unit/Keywords/ClassifyKeywordIntentTest.php`

**Produces:** explainable classification result containing intent, confidence, matched rule IDs and human-readable evidence.

- [x] RED corpus tests for Persian commercial/transactional/informational/navigational/local/mixed/unknown examples.
- [x] RED tests that ambiguous phrases return `mixed|unknown` with medium/low confidence rather than false certainty.
- [x] Implement deterministic ordered rules using normalized keyword text without mutating stored original text.
- [x] Verify repeated classification is identical for identical inputs.
- [x] Full CI GREEN and commit `feat: add rule based intent engine`.

### Task 3 — Review Queue foundation for uncertain intent

**Files:**
- Create: `app/Models/ReviewItem.php`
- Create: `database/migrations/2026_10_22_011000_create_review_items_table.php`
- Create: `app/Domain/Review/ReviewItemType.php`
- Create: `app/Services/Review/UpsertIntentReviewItems.php`
- Create: `app/Http/Controllers/ReviewQueueController.php`
- Create: `resources/js/pages/projects/review-queue.tsx`
- Test: `tests/Feature/Review/IntentReviewQueueTest.php`

**Produces:** project-scoped review queue that upserts uncertain intent decisions and supports accept/change/skip without duplicate queue rows.

- [x] RED tests for low/medium confidence queueing, no queue for accepted high confidence, idempotent refresh, authorization and resolution attribution.
- [x] Implement review schema/service/controller/page.
- [x] Add keyboard-first row actions and empty/loading/error states.
- [x] Verify resolved items do not re-open unless classifier evidence/input changed.
- [x] Full CI GREEN and commit `feat: add intent review queue`.

### Task 4 — Cluster domain and membership model

**Files:**
- Create: `app/Models/KeywordCluster.php`
- Create: `app/Models/KeywordClusterMember.php`
- Create: `app/Domain/Keywords/ClusterReviewState.php`
- Create: `database/migrations/2026_10_22_012000_create_keyword_clusters_tables.php`
- Create: `app/Services/Keywords/CreateKeywordCluster.php`
- Test: `tests/Feature/Keywords/KeywordClusterDomainTest.php`

**Produces:** project-scoped clusters with name, intent, primary keyword, confidence, review state, lock state and explicit members.

- [x] RED tests for create cluster, one membership per project keyword/cluster relation, primary keyword membership invariant, project isolation and locked-state persistence.
- [x] Implement schema/models/service and indexes.
- [x] Verify deleting/reassigning primary keyword cannot leave an invalid cluster.
- [x] Full CI GREEN and commit `feat: add keyword cluster domain`.

### Task 5 — Lexical/feature clustering engine

**Files:**
- Create: `app/Domain/Keywords/KeywordClusterCandidate.php`
- Create: `app/Services/Keywords/GenerateKeywordClusterCandidates.php`
- Create: `app/Services/Keywords/ApplyKeywordClusterCandidates.php`
- Test: `tests/Unit/Keywords/GenerateKeywordClusterCandidatesTest.php`
- Test: `tests/Feature/Keywords/ApplyKeywordClusterCandidatesTest.php`

**Produces:** deterministic, explainable first-stage clustering without AI/SERP dependency.

- [x] RED tests for shared-token/normalized Persian phrase grouping, stop-word-safe behavior, unrelated keyword separation and deterministic output.
- [x] RED apply tests that locked clusters/manual moves are preserved and rerun is idempotent.
- [x] Implement candidate generation with explicit evidence/score, then transactional apply service.
- [x] Queue medium/low-confidence cluster decisions as review items.
- [x] Full CI GREEN and commit `feat: add deterministic clustering engine`.

### Task 6 — Cluster operations: merge, split, move, lock

**Files:**
- Create: `app/Services/Keywords/MergeKeywordClusters.php`
- Create: `app/Services/Keywords/SplitKeywordCluster.php`
- Create: `app/Services/Keywords/MoveKeywordBetweenClusters.php`
- Create: `app/Services/Keywords/SetKeywordClusterLock.php`
- Test: `tests/Feature/Keywords/KeywordClusterOperationsTest.php`

**Produces:** safe manual cluster editing with audit/change history and undo support where state is unchanged since the operation.

- [x] RED tests for merge/split/move/lock authorization, membership invariants, audit attribution and safe undo/conflict refusal.
- [x] Implement transactional services reusing ChangeSet/ActivityEvent patterns.
- [x] Verify automatic clustering cannot modify locked clusters.
- [x] Full CI GREEN and commit `feat: add cluster editing operations`.

### Task 7 — Existing/Planned Page foundation

**Files:**
- Create: `app/Models/Page.php`
- Create: `app/Domain/Pages/PageKind.php`
- Create: `database/migrations/2026_10_22_013000_create_pages_table.php`
- Create: `app/Services/Pages/CreatePlannedPage.php`
- Create: `app/Services/Pages/RegisterExistingPage.php`
- Test: `tests/Feature/Pages/PageFoundationTest.php`

**Produces:** first-class existing/planned page entities usable before crawler implementation.

- [x] RED tests for existing URL normalization/uniqueness, planned page without live URL, project isolation and page type/status.
- [x] Implement page schema/models/services without crawler fields that cannot yet be sourced.
- [x] Verify planned page can later be promoted to existing without losing its identity/cluster mappings.
- [x] Full CI GREEN and commit `feat: add page targeting foundation`.

### Task 8 — Cluster-to-page Mapping domain

**Files:**
- Create: `app/Models/ClusterTarget.php`
- Create: `app/Domain/Keywords/ClusterTargetType.php`
- Create: `database/migrations/2026_10_22_014000_create_cluster_targets_table.php`
- Create: `app/Services/Keywords/MapClusterToTarget.php`
- Test: `tests/Feature/Keywords/KeywordMappingTest.php`

**Produces:** mapping from cluster to `existing_page|planned_page|merge_target|redirect_target|ignore` with history and explicit status.

- [ ] RED tests for every target type, project isolation, one active mapping per cluster, manual remap attribution and invalid cross-project page IDs.
- [ ] Implement schema/service/policies and history integration.
- [ ] Verify remap does not erase prior mapping history.
- [ ] Full CI GREEN and commit `feat: add cluster target mapping`.

### Task 9 — Mapping conflict detection and review

**Files:**
- Create: `app/Domain/Keywords/MappingConflictType.php`
- Create: `app/Services/Keywords/DetectKeywordMappingConflicts.php`
- Test: `tests/Feature/Keywords/KeywordMappingConflictTest.php`

**Produces:** explainable warnings for same-intent multi-page targets, overloaded unrelated cluster targets, high-value unmapped clusters and structurally invalid targets available at this phase.

- [ ] RED tests for same-page unrelated clusters, same-intent competing pages, unmapped cluster and resolved-conflict idempotency.
- [ ] Implement deterministic detector that upserts Review Queue items instead of silently changing mappings.
- [ ] Verify conflict resolution survives recalculation until evidence changes.
- [ ] Full CI GREEN and commit `feat: add mapping conflict detection`.

### Task 10 — Priority scoring and Ready lifecycle

**Files:**
- Create: `app/Domain/Keywords/ClusterPriorityScore.php`
- Create: `app/Services/Keywords/CalculateClusterPriority.php`
- Modify: `app/Models/KeywordCluster.php`
- Test: `tests/Unit/Keywords/CalculateClusterPriorityTest.php`
- Test: `tests/Feature/Keywords/KeywordReadyLifecycleTest.php`

**Produces:** explainable priority score based only on currently available local data (search demand, difficulty when present, business relevance/manual input, mapping completeness, review completeness); no invented external metrics.

- [ ] RED tests for deterministic score components and missing-metric behavior.
- [ ] RED lifecycle tests: cluster cannot be `ready` with unresolved required reviews or missing mapping unless explicitly ignored.
- [ ] Implement score value object/service and lifecycle transition guard.
- [ ] Full CI GREEN and commit `feat: add keyword cluster prioritization`.

### Task 11 — Keyword Intelligence workspace and vertical-slice acceptance

**Files:**
- Create/Modify: `resources/js/pages/projects/keywords.tsx`
- Create: `resources/js/components/keyword-intelligence/*`
- Modify: `resources/js/i18n/index.ts`
- Modify: `routes/web.php`
- Create: `tests/Feature/Acceptance/KeywordIntelligenceAcceptanceTest.php`
- Create: `docs/release-readiness/keyword-intelligence.md`

**Produces:** connected UI/workflow from canonical keyword -> intent review -> cluster -> manual cluster edits -> page target -> conflict review -> priority -> ready.

- [ ] Write RED backend acceptance test before final integration fixes.
- [ ] Implement project-scoped Intent/Clusters/Mapping tabs and Review Queue entry points, preserving existing high-performance Grid.
- [ ] Verify keyboard review actions, loading/empty/error states and Persian RTL core screens.
- [ ] Run full PHP suite, PHPStan/Pint, frontend check/typecheck/build, runtime audits and PHP compatibility/release gates already defined in CI.
- [ ] Whole-branch review focused on authorization, idempotency, locked/manual overrides and silent remapping risks.
- [ ] Create PR to `main`, require fresh final-head CI, merge only when green.
- [ ] Commit `docs: complete keyword intelligence slice`.

---

## Execution Rules

- Execute in order with RED -> GREEN -> full relevant verification -> commit for each task.
- Do not add SERP API, AI provider or crawler dependency in this slice.
- Never make an automatic decision irreversible; uncertainty goes to Review Queue.
- Never recalculate through explicit user locks/overrides.
- CI is the source of truth when local sandbox tooling/network is incomplete.
- Do not merge until the acceptance workflow and whole-branch final CI are green.
