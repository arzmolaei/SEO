# Persian Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a secure, project-scoped, Persian-safe `seo-plan/1` CSV export that reuses current keyword-intelligence semantics, stays memory bounded, works without background workers or external network access, and is verified on MariaDB 10.11.

**Architecture:** Keep export read-only and synchronous. Separate spreadsheet-safe byte serialization from project snapshot queries, coordinate those through one application service that generates into a private rewindable temporary stream inside a consistent database snapshot, then close the transaction before HTTP download begins. The controller owns authorization/response concerns only; the existing Keywords workspace only exposes the action when the server says the user can export.

**Tech Stack:** Laravel 13, PHP 8.4.1+, Eloquent/query builder, Inertia 3, React 19, TypeScript, MySQL/MariaDB 10.11, PHPUnit 12.5.

**Spec:** `docs/superpowers/specs/2026-10-23-persian-export-design.md`

## Global Constraints

- Runtime floor remains PHP `>= 8.4.1`; permanent compatibility gates remain PHP 8.4 and 8.5.
- V1 endpoint is exactly `GET /projects/{project}/exports/seo-plan.csv` under existing `auth` + `verified` middleware.
- Schema id is exactly `seo-plan/1`; the 74-column ordered header in the spec is immutable for V1.
- Export is read-only: no migration, export-history table, durable artifact, upload, queue job, scheduler dependency, shell command, external API, AI, SERP, CDN, or international network requirement.
- UTF-8 BOM, comma delimiter, double-quote enclosure, CRLF row endings, `.` decimals, ISO-8601 UTC timestamps, `1`/`0` booleans, and blank null cells are part of the byte contract.
- Formula-significant spreadsheet cells must be neutralized while exact free text remains recoverable through `exact_text_json`.
- Every keyword/relation query is scoped to the authorized project and contradictory current state fails generation instead of being guessed around.
- Priority must call existing `ClusterPriorityScore::calculate()` and use the cluster primary keyword as the explicit `priority_basis_keyword_id`.
- Archived clusters export cluster provenance but every priority column is blank.
- Production MySQL/MariaDB generation uses a repeatable-read snapshot and ends the transaction before response streaming/download.
- Output is generated in bounded keyword batches into a private rewindable temporary stream; no all-project materialization and no file under `public/` or `storage/app/public`.
- Connected MariaDB 10.11 acceptance is required before merge.

## Review Focus

1. **Formula/control-prefix text** — values beginning with `=`, `+`, `-`, `@`, tab/newline/control whitespace must be harmless in spreadsheet-facing cells while exact Persian/half-space source text survives in `exact_text_json`; pin in Task 1.
2. **Contradictory current intent** — two `is_current = true` assignments for one keyword must abort the whole export with `export_integrity_current_intent`, never choose a winner or return a partial file; pin in Tasks 2 and 4.
3. **Secondary cluster members** — repeated cluster priority on a secondary keyword row must use the primary keyword's metrics/evidence and expose that basis explicitly; pin in Task 2.
4. **Concurrent edits during export** — a MariaDB export must observe one repeatable-read snapshot and release the transaction before the HTTP body is streamed; pin in Tasks 3 and 6.
5. **Large/failed generation** — 10,000+ rows must traverse bounded batches and a private temp stream, and an exception after bytes were written must close/discard the stream with no successful download or durable artifact; pin in Tasks 3 and 6.

---

### Task 1: Spreadsheet-safe CSV Byte Contract

**Files:**
- Create: `app/Support/Exports/SpreadsheetText.php`
- Create: `app/Support/Exports/SpreadsheetCsvWriter.php`
- Test: `tests/Unit/Exports/SpreadsheetTextTest.php`
- Test: `tests/Unit/Exports/SpreadsheetCsvWriterTest.php`

**Interfaces:**
- Produces: `SpreadsheetText::safe(?string $value): ?string` for human-facing string cells.
- Produces: `SpreadsheetText::exactJson(array $fields): string` using compact UTF-8 JSON with unescaped Unicode/slashes and deterministic key order supplied by the caller.
- Produces: `SpreadsheetCsvWriter::__construct(mixed $stream)` where `$stream` is a writable/seekable resource.
- Produces: `SpreadsheetCsvWriter::begin(array $headers): void`, `write(array $cells): void`, `rowCount(): int`, `sha256(): string`, and `rewind(): void`.
- Writer owns BOM/header/data byte encoding and incremental SHA-256; it does not know Eloquent models.

- [ ] **Step 1: Write `SpreadsheetTextTest` RED cases**

Assert ordinary Persian and half-space text is unchanged; `=SUM(1,1)`, `+1`, `-1`, `@name`, tab-prefixed and CR/LF-prefixed values are neutralized as literal text; `exactJson()` still contains the exact originals byte-for-byte after JSON decode.

- [ ] **Step 2: Run the text test and verify RED**

Run: `php artisan test tests/Unit/Exports/SpreadsheetTextTest.php`
Expected: FAIL because `SpreadsheetText` does not exist.

- [ ] **Step 3: Implement `SpreadsheetText` minimally**

Use one deterministic prefix strategy for dangerous spreadsheet text and no Unicode normalization. Do not modify Persian/Arabic code points, half-spaces, punctuation, or URL text beyond spreadsheet neutralization in the human-facing copy.

- [ ] **Step 4: Write `SpreadsheetCsvWriterTest` RED cases**

Assert exact bytes start with UTF-8 BOM, contain the 74-column header supplied by the test, use CRLF only, escape comma/quote/newline correctly, serialize null/boolean/decimal deterministically, count only data rows, and expose SHA-256 equal to `hash('sha256', exact_output_bytes)`.

- [ ] **Step 5: Run writer test RED, implement writer, rerun GREEN**

Run: `php artisan test tests/Unit/Exports/SpreadsheetCsvWriterTest.php`
Expected: RED before implementation, PASS after implementation.

- [ ] **Step 6: Verify both unit files and style**

Run: `php artisan test tests/Unit/Exports/SpreadsheetTextTest.php tests/Unit/Exports/SpreadsheetCsvWriterTest.php`
Run: `composer lint:check`
Expected: PASS.

- [ ] **Step 7: Commit**

Commit message: `feat: add spreadsheet-safe CSV primitives`

---

### Task 2: Project-scoped Snapshot Query and Domain Parity

**Files:**
- Create: `app/Services/Exports/ProjectSeoExportSnapshot.php`
- Create: `app/Services/Exports/ProjectSeoExportException.php`
- Test: `tests/Feature/Exports/ProjectSeoExportSnapshotTest.php`

**Interfaces:**
- Produces: `ProjectSeoExportSnapshot::loadBatch(Project $project, ?int $afterId, int $limit): array{rows:list<array<string,mixed>>, next_after_id:?int}`.
- Every returned row uses the exact 74 schema keys from the spec, except export-wide values (`schema_version`, `exported_at_utc`, `project_id`, `project_name`) may be filled by Task 3 if that keeps the query collaborator free of presentation metadata.
- Produces: `ProjectSeoExportException::codeName(): string` and `userMessage(): string` with stable codes including `export_integrity_current_intent`, `export_integrity_project_relation`, `export_temp_stream_unavailable`, and `export_generation_failed`.
- Snapshot reuses `ClusterPriorityScore::calculate()` directly.

- [ ] **Step 1: Write the snapshot RED fixture**

Create two project fixtures with Persian keywords, multiple sources, tied metric timestamps, current intent, a cluster with primary+secondary members, target/page, pending reviews and mapping conflict. Assert project A rows never contain project B ids/text.

- [ ] **Step 2: Pin latest/current semantics in RED tests**

Assert latest metric chooses greatest `observed_at`, then greatest metric id; zero current intents exports blanks; exactly one current intent exports it; two current intents throw `export_integrity_current_intent`.

- [ ] **Step 3: Pin cluster/mapping/review/priority parity in RED tests**

Assert secondary-member row uses its own row-level metrics in columns 16–25 but uses the cluster primary keyword in `priority_basis_keyword_id` and columns 51–60. Assert priority score/components equal `ClusterPriorityScore::calculate()` for the same primary evidence. Assert archived cluster retains cluster fields while columns 50–73 are blank.

- [ ] **Step 4: Run the snapshot test and verify RED**

Run: `php artisan test tests/Feature/Exports/ProjectSeoExportSnapshotTest.php`
Expected: FAIL because the snapshot service/exception do not exist.

- [ ] **Step 5: Implement bounded `loadBatch()` queries**

Start only from `keywords.project_id = $project->id`, order by keyword id, limit by `$limit`, and load related sources/intents/metrics/membership/cluster/target/page/reviews only for ids in that batch or the represented primary keyword ids. Do not call `Keyword::get()` for the whole project.

- [ ] **Step 6: Implement integrity checks and priority assembly**

Reject cross-project or contradictory relations even when DB foreign keys normally prevent them. Do not silently skip integrity failures. Keep source labels deterministic and serialize them as compact JSON array in binary UTF-8 sort order.

- [ ] **Step 7: Verify GREEN and static analysis**

Run: `php artisan test tests/Feature/Exports/ProjectSeoExportSnapshotTest.php`
Run: `composer types:check`
Expected: PASS.

- [ ] **Step 8: Commit**

Commit message: `feat: assemble project SEO export snapshot`

---

### Task 3: Export Coordinator, Repeatable-read Snapshot, Temp Stream, Hash and Filename

**Files:**
- Create: `app/Services/Exports/ProjectSeoExportResult.php`
- Create: `app/Services/Exports/ExportProjectSeoPlan.php`
- Test: `tests/Feature/Exports/ExportProjectSeoPlanTest.php`

**Interfaces:**
- Produces: `ExportProjectSeoPlan::handle(Project $project, ?CarbonImmutable $generatedAt = null): ProjectSeoExportResult`.
- Produces immutable result fields/accessors for `stream`, `filename`, `mimeType`, `rowCount`, `schemaVersion`, `sha256`, and `generatedAt`.
- `ProjectSeoExportResult::close(): void` closes the resource idempotently; controller streaming in Task 4 closes it in a `finally` block.
- Coordinator batch size is one class constant (`250` initially) and is not a user-facing product limit.

- [ ] **Step 1: Write coordinator RED tests for empty and multi-batch exports**

Assert empty project still returns BOM + exact header and `rowCount=0`; 501 keywords invoke at least three snapshot batches at batch size 250; row order is ascending keyword id; `X` metadata values can be derived without re-reading the full stream into a PHP string.

- [ ] **Step 2: Pin filename and private stream rules**

Assert filename matches `seo-plan-v1-<ascii-safe-token>-<UTC timestamp>.csv`, traversal/control characters cannot enter it, Persian-only project names fall back to project id if no safe ASCII token remains, and no path under `public/` or `storage/app/public` is created.

- [ ] **Step 3: Pin transaction/cleanup behavior**

Use a spy/fake connection boundary where possible to assert transaction is committed/rolled back before `handle()` returns the result. Force snapshot failure after at least one row and assert no usable result is returned and the temp resource is closed/discarded.

- [ ] **Step 4: Run coordinator test and verify RED**

Run: `php artisan test tests/Feature/Exports/ExportProjectSeoPlanTest.php`
Expected: FAIL because coordinator/result are absent.

- [ ] **Step 5: Implement coordinator**

Open `php://temp/maxmemory:2097152` (2 MiB) in `w+b`. On MySQL/MariaDB set the next transaction isolation to `REPEATABLE READ`, begin transaction, write BOM/header, iterate `ProjectSeoExportSnapshot::loadBatch()`, fill export-wide columns and spreadsheet-safe text, finish writer, end transaction, rewind, and return result. On any failure, rollback when active, close stream, and rethrow known export exception or wrap as `export_generation_failed` without sensitive details.

- [ ] **Step 6: Verify coordinator GREEN and full export tests so far**

Run: `php artisan test tests/Unit/Exports tests/Feature/Exports/ProjectSeoExportSnapshotTest.php tests/Feature/Exports/ExportProjectSeoPlanTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

Commit message: `feat: generate bounded project SEO export`

---

### Task 4: Authorization, HTTP Download Contract, Errors and Response Metadata

**Files:**
- Create: `app/Http/Controllers/ProjectSeoExportController.php`
- Modify: `app/Policies/ProjectPolicy.php`
- Modify: `app/Http/Controllers/KeywordGridController.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Exports/ProjectSeoExportDownloadTest.php`
- Modify test: `tests/Feature/Keywords/KeywordGridTest.php`

**Interfaces:**
- Adds: `ProjectPolicy::export(User $user, Project $project): bool`, delegating to existing project-management role semantics (`Owner`, `Admin`, `SeoManager`).
- Adds invokable controller: `ProjectSeoExportController::__invoke(Request $request, Project $project, ExportProjectSeoPlan $export): Response`.
- Adds named route: `projects.exports.seo-plan` for exact path `/projects/{project}/exports/seo-plan.csv` inside existing `auth` + `verified` group.
- Adds Inertia prop `canExport: bool` to `projects/keywords`.
- Adds shared flash prop `flash.export_error` only for known export generation failures.

- [ ] **Step 1: Write authorization RED tests**

Assert Owner/Admin/SEO Manager receive 200 CSV; SEO Specialist/Content Writer/Viewer/non-member receive 403; guessed project id cannot export another project's rows.

- [ ] **Step 2: Write HTTP byte/header RED tests**

Assert attachment filename, `text/csv` content type, `Cache-Control: private, no-store`, `Pragma: no-cache`, `X-Content-Type-Options: nosniff`, schema/row-count/generated-at headers and SHA-256 header matching the exact response body.

- [ ] **Step 3: Write known-failure RED test**

Inject/force `ProjectSeoExportException` and assert redirect back to `projects.keywords.index` with stable Persian `flash.export_error`; raw SQL/path/exception message must not be rendered. Unknown unexpected exception remains a server error and is not converted into a fake successful/partial CSV.

- [ ] **Step 4: Run download test and verify RED**

Run: `php artisan test tests/Feature/Exports/ProjectSeoExportDownloadTest.php`
Expected: FAIL because route/controller/policy ability are absent.

- [ ] **Step 5: Implement policy, route and streaming response**

Authorize before calling export. Use a streamed download that only reads the already-completed result stream; the DB transaction must already be closed. Close the result in response callback `finally`. Never generate CSV bytes directly in the controller.

- [ ] **Step 6: Add capability and flash props**

`KeywordGridController` supplies `canExport`; `HandleInertiaRequests` shares only the stable export error string from session flash.

- [ ] **Step 7: Verify HTTP and existing grid behavior**

Run: `php artisan test tests/Feature/Exports/ProjectSeoExportDownloadTest.php tests/Feature/Keywords/KeywordGridTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

Commit message: `feat: expose authorized project CSV export`

---

### Task 5: Persian-first Keywords Workspace Export Action

**Files:**
- Modify: `resources/js/pages/projects/keywords.tsx`
- Modify generated Wayfinder output only through `php artisan wayfinder:generate --with-form` if the project tracks generated route helpers
- Test/verify: `tests/Feature/Keywords/KeywordGridTest.php`

**Interfaces:**
- `Keywords` page accepts `canExport: boolean` and shared `flash.export_error?: string|null` from Inertia.
- Authorized UI renders a normal download link to `/projects/${projectId}/exports/seo-plan.csv` with Persian label `خروجی CSV`; unauthorized UI renders no export action.
- Export action is independent of current grid filters, sort, saved view, page and tab state, matching the schema contract.

- [ ] **Step 1: Extend grid feature test for capability props**

Assert Owner gets `canExport=true`; Viewer gets project page access but `canExport=false`.

- [ ] **Step 2: Add UI action and error presentation**

Place the action in the Keywords workspace header next to existing summary controls, use a normal `<a>` download navigation so Inertia does not try to parse CSV, keep RTL copy and accessible label, and render export flash error as a visible non-secret alert.

- [ ] **Step 3: Run frontend gates**

Run: `php artisan wayfinder:generate --with-form`
Run: `npm run check && npm run types:check && npm run build`
Expected: PASS.

- [ ] **Step 4: Run affected backend test**

Run: `php artisan test tests/Feature/Keywords/KeywordGridTest.php tests/Feature/Exports/ProjectSeoExportDownloadTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

Commit message: `feat: add Persian project export action`

---

### Task 6: Large-project and MariaDB 10.11 Connected Acceptance

**Files:**
- Create: `tests/Feature/Exports/ProjectSeoExportLargeDatasetTest.php`
- Create: `tests/Feature/Acceptance/PersianExportMariaDbAcceptanceTest.php`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Large-dataset test exercises at least 10,000 canonical keyword rows without defining 10,000 as a product cap.
- Connected test is guarded by `PERSIAN_EXPORT_CONNECTED=1` and uses actual MySQL/MariaDB connection values from CI.
- CI adds independent `persian-export-mariadb` job using `mariadb:10.11`; it does not share state with the installer MariaDB job.

- [ ] **Step 1: Write large-dataset RED/strengthening test**

Create 10,000 minimal project keywords efficiently, export them, assert `rowCount=10000`, first/last keyword ids are ordered, digest matches streamed bytes, and coordinator requested multiple bounded batches. Assert no durable export files exist before/after.

- [ ] **Step 2: Run large-dataset test**

Run: `php artisan test tests/Feature/Exports/ProjectSeoExportLargeDatasetTest.php`
Expected: PASS if earlier batching is sound; if it fails, treat it as a real performance/behavior RED and fix production rather than shrinking the test.

- [ ] **Step 3: Write connected MariaDB acceptance**

On a real migrated MariaDB database, create Persian keyword text with half-space, source/metric history, intent, primary+secondary cluster, page mapping, reviews and a formula-significant free-text field. Invoke the real authenticated HTTP export and assert authorization, exact header count/order, UTF-8 BOM, exact-text recovery, latest metric tie-break, priority basis parity, response hash, and no cross-project data.

- [ ] **Step 4: Add repeatable-read concurrency assertion**

In the connected acceptance, coordinate a second DB connection to mutate a row after export snapshot begins but before later batches are loaded; assert all rows reflect one snapshot, then assert the mutation is visible after export completes. This proves snapshot consistency rather than merely checking configured isolation text.

- [ ] **Step 5: Add permanent independent CI job**

Create `persian-export-mariadb` with MariaDB 10.11, PHP 8.4 + `pdo_mysql`, application key, production-like DB env, `php artisan migrate --force`, and `php artisan test tests/Feature/Acceptance/PersianExportMariaDbAcceptanceTest.php --fail-on-skipped`.

- [ ] **Step 6: Run/observe permanent CI and fix only real failures**

Expected gates: verify, PHP 8.4, PHP 8.5, installer MariaDB, and Persian Export MariaDB all success on the same head.

- [ ] **Step 7: Commit**

Commit message: `test: add connected Persian export acceptance`

---

### Task 7: Whole-branch Review, Release Evidence, PR and Merge Gate

**Files:**
- Create: `docs/release-readiness/persian-export.md`
- Modify: `docs/PROJECT_STATE.md`
- Modify: `docs/HANDOFF.md`
- Modify: `docs/DELIVERY_PLAN.md`
- No product-code change unless review finds a real defect.

**Interfaces:**
- Release-readiness doc records exact final implementation SHA, permanent CI run id, schema `seo-plan/1`, role matrix, 10k test evidence, MariaDB 10.11 acceptance and known non-goals.
- Durable state moves active lifecycle boundary from Persian Export to Backup + Restore only after final-head evidence is green.

- [ ] **Step 1: Run focused + full verification on final product head**

Run: `php artisan test tests/Unit/Exports tests/Feature/Exports tests/Feature/Acceptance/KeywordIntelligenceAcceptanceTest.php`
Run: `composer lint:check && composer types:check`
Run: `php artisan wayfinder:generate --with-form && npm run check && npm run types:check && npm run build`
Run: `php artisan test`
Expected: PASS; connected-only export acceptance may skip locally but must pass in its permanent CI job.

- [ ] **Step 2: Whole-branch security/correctness review**

Review specifically for: project data exfiltration, raw payload/secret leakage, CSV formula injection gaps, partial-file success paths, transaction lifetime crossing response streaming, all-project `get()` calls, temp-file leftovers, priority formula duplication, archived-cluster ambiguity, and UI dead actions.

- [ ] **Step 3: Fix any Important/Critical finding with RED first**

For each real finding, add a failing regression test, verify RED, implement minimal fix, verify GREEN, and commit semantically. Do not weaken existing acceptance to make review pass.

- [ ] **Step 4: Record release-readiness and durable handoff**

Create `docs/release-readiness/persian-export.md`; update `PROJECT_STATE`, `HANDOFF`, and `DELIVERY_PLAN` to state Installer + Persian Export are integrated only after the merge gate is actually satisfied, with Backup + Restore as the next bounded subsystem.

- [ ] **Step 5: Commit documentation**

Commit message: `docs: record Persian export release readiness`

- [ ] **Step 6: Require exact-head permanent CI**

Do not reuse an older green run. The documentation-complete head must have all permanent jobs successful, including `persian-export-mariadb`.

- [ ] **Step 7: Open/update one PR and review changed files**

Create one integration PR from `feature/persian-export` to `main`. Review the full diff and any PR comments; no duplicate export PR.

- [ ] **Step 8: Merge with exact head guard and verify `main`**

Merge only if the PR head still equals the reviewed/green SHA. Then require post-merge permanent CI on the resulting `main` merge commit before calling Persian Export integrated.

- [ ] **Step 9: Advance lifecycle boundary**

After post-merge green, start the separate Backup + Restore brainstorming/spec process. Do not start crawler/Search Console/SERP/AI work.
