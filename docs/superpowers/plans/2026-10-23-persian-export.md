# Persian Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a secure, project-scoped, Persian-safe `seo-plan/1` CSV export that reuses current keyword-intelligence semantics, stays memory bounded, works without background workers or external network access, and is verified on MariaDB 10.11.

**Architecture:** Keep export read-only and synchronous. A schema class owns the version/header contract, spreadsheet helpers own safe bytes, a snapshot service loads one project-scoped batch at a time, and one coordinator writes the completed file into a private rewindable temp stream inside a consistent DB snapshot. The DB transaction ends before the controller starts the HTTP download.

**Tech Stack:** Laravel 13, PHP 8.4.1+, Eloquent/query builder, Inertia 3, React 19, TypeScript, MySQL/MariaDB 10.11, PHPUnit 12.5.

**Spec:** `docs/superpowers/specs/2026-10-23-persian-export-design.md`

## Global Constraints

- Runtime floor remains PHP `>= 8.4.1`; permanent compatibility gates remain PHP 8.4 and 8.5.
- V1 endpoint is exactly `GET /projects/{project}/exports/seo-plan.csv` under existing `auth` + `verified` middleware.
- Schema id is exactly `seo-plan/1`; the 74-column ordered header in the spec is immutable for V1 and has one code source of truth.
- Export is read-only: no migration, export-history table, durable artifact, upload, queue job, scheduler dependency, shell command, external API, AI, SERP, CDN, or international network requirement.
- CSV bytes use UTF-8 BOM, comma delimiter, double-quote enclosure, CRLF rows, `.` decimals, ISO-8601 UTC timestamps, `1`/`0` booleans and blank null cells.
- Human-facing strings whose first byte is one of `=`, `+`, `-`, `@`, tab, CR, or LF are prefixed with ASCII apostrophe `'`; exact originals remain in `exact_text_json` with no Unicode normalization.
- Every keyword/relation query is scoped to the authorized project. Contradictory current state fails the whole generation rather than being guessed around.
- Priority calls existing `ClusterPriorityScore::calculate()` and uses the cluster primary keyword as `priority_basis_keyword_id`; archived clusters have all priority columns blank.
- Production MySQL/MariaDB generation uses repeatable-read and ends the DB transaction before response streaming/download.
- Output is generated in bounded batches into `php://temp/maxmemory:2097152`; no all-project materialization and no file under `public/` or `storage/app/public`.
- Connected MariaDB 10.11 acceptance is required before merge.
- Known operator messages are fixed:
  - `export_integrity_current_intent` -> `خروجی به‌دلیل ناسازگاری داده‌های نیت قابل تولید نیست.`
  - `export_integrity_project_relation` -> `خروجی به‌دلیل ناسازگاری داده‌های پروژه قابل تولید نیست.`
  - `export_temp_stream_unavailable` -> `فضای موقت امن برای تولید خروجی در دسترس نیست.`
  - `export_generation_failed` -> `تولید خروجی انجام نشد. دوباره تلاش کنید.`

## Review Focus

1. Formula/control-prefix text must be harmless in spreadsheet-facing cells while exact Persian/half-space text survives in `exact_text_json`; pin in Task 1.
2. Two current intent assignments for one keyword must abort the whole export with `export_integrity_current_intent`; pin in Tasks 2 and 4.
3. A secondary cluster-member row must use the primary keyword's metrics/evidence for repeated cluster priority; pin in Task 2.
4. Concurrent MariaDB edits must not create a mixed snapshot, and the transaction must be closed before HTTP body streaming; pin in Tasks 3 and 6.
5. 10,000+ rows and mid-generation failures must stay batch/temp-stream bounded and leave no successful partial download or durable artifact; pin in Tasks 3 and 6.

---

### Task 1: Schema and Spreadsheet-safe CSV Primitives

**Files:**
- Create: `app/Services/Exports/ProjectSeoExportSchema.php`
- Create: `app/Support/Exports/SpreadsheetText.php`
- Create: `app/Support/Exports/SpreadsheetCsvWriter.php`
- Test: `tests/Unit/Exports/ProjectSeoExportSchemaTest.php`
- Test: `tests/Unit/Exports/SpreadsheetTextTest.php`
- Test: `tests/Unit/Exports/SpreadsheetCsvWriterTest.php`

**Interfaces:**
- `ProjectSeoExportSchema::ID = 'seo-plan/1'`.
- `ProjectSeoExportSchema::HEADERS` is the exact 74-column ordered list from the spec; all later code/tests consume this constant instead of duplicating the list.
- `SpreadsheetText::safe(?string $value): ?string` prefixes ASCII apostrophe when first byte is `= + - @ \t \r \n`; otherwise it returns the exact string.
- `SpreadsheetText::exactJson(array $fields): string` emits compact UTF-8 JSON with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, preserving caller key order.
- `SpreadsheetCsvWriter::__construct(mixed $stream)`, `begin(array $headers): void`, `write(array $cells): void`, `rowCount(): int`, `sha256(): string`, `rewind(): void`.

- [ ] **Step 1: Write schema/text RED tests**

Assert schema id/header count/order (74), ordinary Persian/half-space preservation, exact apostrophe neutralization for all seven dangerous first-byte classes, and exact recovery via JSON decode.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Unit/Exports/ProjectSeoExportSchemaTest.php tests/Unit/Exports/SpreadsheetTextTest.php`
Expected: FAIL because classes do not exist.

- [ ] **Step 3: Implement schema and text serializer**

No Unicode normalization or trimming. Schema constant is copied exactly from the approved spec.

- [ ] **Step 4: Write writer RED tests**

Assert BOM at byte zero, `ProjectSeoExportSchema::HEADERS`, CRLF-only rows, RFC-style quote/comma/newline escaping, deterministic null/boolean/decimal output, data-row count excluding header, and SHA-256 equal to `hash('sha256', exact_bytes)`.

- [ ] **Step 5: Implement writer and verify GREEN**

Run: `php artisan test tests/Unit/Exports`
Expected: PASS.

- [ ] **Step 6: Commit**

Commit: `feat: add project export schema and CSV primitives`

---

### Task 2: Project-scoped Snapshot Query and Domain Parity

**Files:**
- Create: `app/Services/Exports/ProjectSeoExportSnapshot.php`
- Create: `app/Services/Exports/ProjectSeoExportException.php`
- Test: `tests/Feature/Exports/ProjectSeoExportSnapshotTest.php`

**Interfaces:**
- `ProjectSeoExportSnapshot::loadBatch(Project $project, ?int $afterId, int $limit): array{rows:list<array<string,mixed>>, next_after_id:?int}`.
- Each row returns schema fields 5–74 (`keyword_id` through `exact_text_json`); Task 3 fills export-wide fields 1–4.
- `ProjectSeoExportException::__construct(string $codeName, string $userMessage, ?Throwable $previous = null)`, `codeName(): string`, `userMessage(): string`.
- Snapshot calls `ClusterPriorityScore::calculate()` directly.

- [ ] **Step 1: Write project-isolation RED fixture**

Create two projects with Persian keywords, sources, metric history, intents, cluster primary+secondary membership, target/page and reviews. Exporting project A must never return any project B id/text.

- [ ] **Step 2: Pin latest/current semantics**

Latest metric is greatest `observed_at`, then greatest metric id. Zero current intents -> blank fields; one -> that assignment; two -> throw `export_integrity_current_intent` with the fixed Persian message.

- [ ] **Step 3: Pin cluster/review/priority parity**

Secondary row uses its own row metrics in columns 16–25 but the cluster primary metrics in priority columns 51–60; `priority_basis_keyword_id` is primary id; score/components equal direct `ClusterPriorityScore::calculate()` output. Archived cluster preserves cluster fields but columns 50–73 are null/blank.

- [ ] **Step 4: Verify RED**

Run: `php artisan test tests/Feature/Exports/ProjectSeoExportSnapshotTest.php`
Expected: FAIL because snapshot/exception do not exist.

- [ ] **Step 5: Implement bounded batch queries**

Start from `keywords.project_id = $project->id`, ascending id, `limit($limit)`, and fetch only relations for batch keyword ids plus represented primary keyword ids. Never whole-project `Keyword::get()`.

- [ ] **Step 6: Implement integrity checks and deterministic source/review assembly**

Cross-project relation mismatch throws `export_integrity_project_relation`. `source_names_json` is a compact JSON array of distinct non-empty labels sorted by binary UTF-8 order.

- [ ] **Step 7: Verify GREEN + PHPStan**

Run: `php artisan test tests/Feature/Exports/ProjectSeoExportSnapshotTest.php && composer types:check`
Expected: PASS.

- [ ] **Step 8: Commit**

Commit: `feat: assemble project SEO export snapshot`

---

### Task 3: Coordinator, Repeatable-read Snapshot, Temp Stream, Hash and Filename

**Files:**
- Create: `app/Services/Exports/ProjectSeoExportResult.php`
- Create: `app/Services/Exports/ExportProjectSeoPlan.php`
- Test: `tests/Feature/Exports/ExportProjectSeoPlanTest.php`

**Interfaces:**
- `ExportProjectSeoPlan::handle(Project $project, ?CarbonImmutable $generatedAt = null): ProjectSeoExportResult`.
- Result exposes stream, filename, MIME type, row count, schema version, SHA-256 and generated-at; `close(): void` is idempotent.
- Coordinator batch size constant is `250` and is not a product maximum.

- [ ] **Step 1: Write empty/multi-batch RED tests**

Empty project returns BOM+header and zero rows. 501 keywords require at least three snapshot batch loads. Output order is ascending keyword id.

- [ ] **Step 2: Pin filename/private-temp behavior**

Filename is `seo-plan-v1-<ascii-safe-token>-<UTC timestamp>.csv`; path/control/traversal characters cannot enter it; Persian-only project name falls back to project id. No public/durable export path is created.

- [ ] **Step 3: Pin transaction and failure cleanup**

Force failure after bytes were written: no result returns, transaction rolls back if active, temp stream closes. Assert successful `handle()` returns only after transaction is no longer active.

- [ ] **Step 4: Verify RED**

Run: `php artisan test tests/Feature/Exports/ExportProjectSeoPlanTest.php`
Expected: FAIL because coordinator/result do not exist.

- [ ] **Step 5: Implement coordinator**

Open `php://temp/maxmemory:2097152` as `w+b`. For MySQL/MariaDB set next transaction isolation to `REPEATABLE READ`, begin transaction, create writer with `ProjectSeoExportSchema::HEADERS`, loop `loadBatch()`, fill fields 1–4, apply `SpreadsheetText::safe()` to human-facing strings, finish, commit, rewind, return. On failure rollback when active, close stream, rethrow known export exception or wrap as `export_generation_failed` with fixed message.

- [ ] **Step 6: Verify export core GREEN**

Run: `php artisan test tests/Unit/Exports tests/Feature/Exports/ProjectSeoExportSnapshotTest.php tests/Feature/Exports/ExportProjectSeoPlanTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

Commit: `feat: generate bounded project SEO export`

---

### Task 4: Authorization, HTTP Download Contract and Controlled Errors

**Files:**
- Create: `app/Http/Controllers/ProjectSeoExportController.php`
- Modify: `app/Policies/ProjectPolicy.php`
- Modify: `app/Http/Controllers/KeywordGridController.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Exports/ProjectSeoExportDownloadTest.php`
- Modify: `tests/Feature/Keywords/KeywordGridTest.php`

**Interfaces:**
- `ProjectPolicy::export(User $user, Project $project): bool` delegates to existing project-management roles: Owner/Admin/SeoManager.
- `ProjectSeoExportController::__invoke(Request $request, Project $project, ExportProjectSeoPlan $export): StreamedResponse|RedirectResponse`.
- Named route `projects.exports.seo-plan` is exact `/projects/{project}/exports/seo-plan.csv` in existing authenticated/verified group.
- Keyword page gets `canExport: bool`; shared Inertia flash gets `flash.export_error`.

- [ ] **Step 1: Write authorization RED tests**

Owner/Admin/SEO Manager -> CSV success. SEO Specialist/Content Writer/Viewer/non-member -> 403. Guessed project id cannot expose another project.

- [ ] **Step 2: Write response-contract RED tests**

Assert attachment filename, CSV content type, `Cache-Control: private, no-store`, `Pragma: no-cache`, `X-Content-Type-Options: nosniff`, schema/row-count/generated-at headers, and SHA-256 header matching captured streamed body bytes.

- [ ] **Step 3: Write controlled-error RED tests**

Known export exception redirects to `projects.keywords.index` and flashes only its fixed Persian `userMessage()`. Raw SQL/path/exception text never appears. Unexpected exceptions remain 500 and never produce partial CSV.

- [ ] **Step 4: Verify RED**

Run: `php artisan test tests/Feature/Exports/ProjectSeoExportDownloadTest.php`
Expected: FAIL because route/controller/policy ability are absent.

- [ ] **Step 5: Implement policy/route/controller**

Authorize before generation. Stream only the already-completed result resource; DB transaction is already closed. Response callback reads stream and calls `result->close()` in `finally`.

- [ ] **Step 6: Add `canExport` and flash sharing**

`KeywordGridController` supplies capability. `HandleInertiaRequests` shares session key `export_error` as `flash.export_error`.

- [ ] **Step 7: Verify GREEN**

Run: `php artisan test tests/Feature/Exports/ProjectSeoExportDownloadTest.php tests/Feature/Keywords/KeywordGridTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

Commit: `feat: expose authorized project CSV export`

---

### Task 5: Persian-first Keywords Workspace Export Action

**Files:**
- Modify: `resources/js/pages/projects/keywords.tsx`
- Test/verify: `tests/Feature/Keywords/KeywordGridTest.php`

**Interfaces:**
- `Keywords` accepts `canExport: boolean`; shared page props include `flash.export_error?: string|null`.
- Authorized page renders a normal `<a>` to `/projects/${projectId}/exports/seo-plan.csv` with label `خروجی CSV`; unauthorized page renders no export action.
- Export never inherits grid filter/sort/saved-view/tab state.

- [ ] **Step 1: Extend grid prop tests**

Owner page -> `canExport=true`; Viewer can view project page but -> `canExport=false`.

- [ ] **Step 2: Implement UI action/error alert**

Place action in Keywords header near existing summary controls. Use normal browser navigation, not Inertia CSV parsing. Render `flash.export_error` as visible non-secret alert.

- [ ] **Step 3: Verify frontend and affected backend**

Run: `php artisan wayfinder:generate --with-form && npm run check && npm run types:check && npm run build`
Run: `php artisan test tests/Feature/Keywords/KeywordGridTest.php tests/Feature/Exports/ProjectSeoExportDownloadTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

Commit: `feat: add Persian project export action`

---

### Task 6: Large-project and MariaDB 10.11 Connected Acceptance

**Files:**
- Create: `tests/Feature/Exports/ProjectSeoExportLargeDatasetTest.php`
- Create: `tests/Feature/Acceptance/PersianExportMariaDbAcceptanceTest.php`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Large fixture uses at least 10,000 canonical keywords but does not define a product cap.
- Connected test is guarded by `PERSIAN_EXPORT_CONNECTED=1`.
- CI adds independent `persian-export-mariadb` job with its own `mariadb:10.11` service/database and does not reuse installer DB state.

- [ ] **Step 1: Add large-dataset strengthening test**

Insert 10,000 keywords efficiently, export, assert row count/order/hash, assert multiple bounded batch loads and no durable artifact before/after. If this exposes a real problem, treat it as RED and fix production; do not shrink the fixture.

- [ ] **Step 2: Add connected Persian data acceptance**

On real migrated MariaDB create Persian half-space text, metric history, intent, primary+secondary cluster, page mapping, reviews and formula-significant text. Call real authenticated HTTP route and assert exact schema/header order, BOM, exact-text recovery, latest metric tie-break, priority basis parity, response digest, role isolation and no cross-project data.

- [ ] **Step 3: Prove repeatable-read with concurrent connection**

Mutate a later-row value from a second DB connection after export snapshot begins but before later batches are loaded; exported rows must remain one snapshot, while the mutation is visible after export completes.

- [ ] **Step 4: Add permanent CI job**

Job: `persian-export-mariadb`, Ubuntu 24.04, MariaDB 10.11, PHP 8.4 + `pdo_mysql`, Composer install, `.env`/APP_KEY, `php artisan migrate --force`, then `php artisan test tests/Feature/Acceptance/PersianExportMariaDbAcceptanceTest.php --fail-on-skipped`.

- [ ] **Step 5: Require all permanent gates on one head**

Expected success: verify, PHP 8.4, PHP 8.5, installer MariaDB and Persian Export MariaDB.

- [ ] **Step 6: Commit**

Commit: `test: add connected Persian export acceptance`

---

### Task 7: Whole-branch Review, Release Evidence, PR and Merge Gate

**Files:**
- Create: `docs/release-readiness/persian-export.md`
- Modify: `docs/PROJECT_STATE.md`
- Modify: `docs/HANDOFF.md`
- Modify: `docs/DELIVERY_PLAN.md`
- Product files only if review finds a real defect.

**Interfaces:**
- Release-readiness records final implementation SHA, exact permanent CI run, schema `seo-plan/1`, role matrix, 10k evidence, MariaDB 10.11 acceptance and non-goals.
- Durable state advances next lifecycle boundary to Backup + Restore only after Persian Export is merged and post-merge CI is green.

- [ ] **Step 1: Run final local/focused verification**

Run: `php artisan test tests/Unit/Exports tests/Feature/Exports`
Run: `composer lint:check && composer types:check`
Run: `php artisan wayfinder:generate --with-form && npm run check && npm run types:check && npm run build`
Run: `php artisan test`
Expected: PASS; connected export acceptance may skip outside its dedicated CI job.

- [ ] **Step 2: Whole-branch review**

Check for project exfiltration, raw payload/secret leakage, formula gaps, partial-file success, transaction lifetime crossing HTTP streaming, all-project materialization, temp leftovers, priority formula duplication, archived-cluster ambiguity and dead UI.

- [ ] **Step 3: Fix each Important/Critical finding with RED first**

Add regression test -> verify RED -> minimal fix -> GREEN -> semantic commit. Never weaken an acceptance test.

- [ ] **Step 4: Record release evidence**

Create `docs/release-readiness/persian-export.md`; update `PROJECT_STATE`, `HANDOFF` and `DELIVERY_PLAN` with exact evidence and Backup + Restore as next subsystem.

- [ ] **Step 5: Commit docs and require exact-head CI**

Commit: `docs: record Persian export release readiness`
Do not reuse older green CI; documentation-complete head must pass every permanent job including `persian-export-mariadb`.

- [ ] **Step 6: Open one PR, review full diff/comments, merge with exact-head guard**

PR: `feature/persian-export` -> `main`. No duplicate export PR. Merge only if head still equals reviewed green SHA.

- [ ] **Step 7: Require post-merge `main` CI**

Only after permanent CI succeeds on the merge commit call Persian Export integrated.

- [ ] **Step 8: Advance lifecycle**

Start the separate Backup + Restore brainstorming/spec process. Do not start crawler/Search Console/SERP/AI work.
