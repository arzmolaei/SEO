# Persian Export Design

Date: 2026-10-23
Status: Awaiting written-spec review
Branch: `feature/persian-export`
Base: `main@c4f03303408a676d43a3fdbf94f1f49e00affbe5`

## 1. Purpose

The Persian Export subsystem provides a trustworthy, spreadsheet-friendly snapshot of a project's current SEO planning data without turning export into a backup or restore format.

The first release must work on the self-hosted runtime with no Composer, Node, npm, SSH, shell access, external API, or international network dependency. It must remain usable on shared hosting and Iranian hosting environments where outbound internet may be unavailable.

The primary user outcome is simple: an authorized operator can open a project, download one CSV, and receive the current keyword-planning state in a form that is safe for common spreadsheet software, preserves Persian text accurately, respects project isolation, and uses the same domain semantics as the application UI.

## 2. Scope

V1 exports the project's current keyword-planning snapshot as one UTF-8 CSV file.

Each row represents one canonical `Keyword` in the selected project. The row includes current keyword state plus the current intent, latest metrics, cluster membership and state, target/page mapping, review signals, and the same priority calculation used by the product where an active cluster exists.

The export is synchronous and browser-driven. It does not require the queue or scheduler to be healthy.

V1 is read-only with respect to domain data. It adds no migration, export-history table, durable export artifact, or server-side export record.

## 3. Explicit Non-Goals

This subsystem does not:

- back up the application or database;
- include `.env`, credentials, users, authentication secrets, session data, cache data, queue jobs, or uploaded files;
- provide restore or import-from-export behavior;
- export raw import payloads wholesale;
- export complete metric history;
- export activity/audit history wholesale;
- create a ZIP archive;
- create a permanent server-side export artifact;
- require background workers;
- add crawler, Search Console, SERP, vector, or AI functionality;
- become a generic report builder.

Backup/Restore remains the next independent lifecycle subsystem and must not inherit accidental coupling from this export format.

## 4. Design Principles

1. **Project isolation is mandatory.** Every exported relation must be proven to belong to the selected project.
2. **Use current domain truth.** Export must reuse existing intent, metric, mapping, review, and priority semantics rather than invent parallel calculations.
3. **One canonical keyword per row.** The file is optimized for planning handoff and spreadsheet analysis.
4. **Safe for Persian spreadsheet use.** UTF-8 BOM, CRLF, deterministic quoting, and formula-injection defense are required.
5. **Preserve exact text.** Spreadsheet-safe presentation must not make exact Persian text unrecoverable.
6. **Bound memory.** The exporter must not load the entire project into PHP memory.
7. **Consistent snapshot.** A single export cannot mix before/after states from concurrent edits.
8. **No network dependency.** Export generation is fully local.
9. **No permanent export leftovers.** Temporary storage must be self-cleaning and inaccessible from the web root.
10. **Deterministic output.** Identical database state and export time metadata produce stable ordering and deterministic relation selection.
11. **Fail on integrity ambiguity.** If the database contains contradictory current state that the domain does not permit, export returns a controlled data-integrity error rather than guessing.

## 5. Authorization

A dedicated `ProjectPolicy::export()` ability will be added.

V1 grants export only to workspace roles whose existing `WorkspaceRole::canManageProjects()` returns true:

- Owner
- Admin
- SEO Manager

SEO Specialist, Content Writer, Viewer, and non-members cannot export bulk project data in V1.

This is intentionally stricter than `ProjectPolicy::view()`. Being allowed to view a project page does not automatically grant bulk data extraction.

The controller must authorize the concrete `Project` before any snapshot work starts.

## 6. User Experience and Route

The existing project Keywords/Intelligence workspace exposes a real `خروجی CSV` action only when the current user is authorized to export.

The exact V1 endpoint is:

`GET /projects/{project}/exports/seo-plan.csv`

It is protected by the same authenticated and verified middleware boundary as the project workspace.

The action starts a normal browser download. V1 does not introduce a modal, export queue, polling UI, fake progress bar, or background job.

The filename is:

`seo-plan-v1-<ascii-safe-project-token>-<UTC timestamp>.csv`

The project token is generated from an allowlist of ASCII filename characters. Path separators, control characters, and traversal syntax are never accepted from project text. If project-name normalization produces an empty token, the project id is used.

For an empty project, the download still succeeds and contains the full header row with zero data rows.

Known generation errors redirect back to the project workspace with a stable Persian operator message. Internal exceptions are logged without exposing SQL, server paths, or sensitive context to the browser.

## 7. Output Contract

### 7.1 Encoding and CSV dialect

The file uses:

- UTF-8;
- UTF-8 BOM at byte zero;
- comma delimiter;
- double-quote enclosure;
- RFC-4180-compatible escaping;
- CRLF row endings;
- decimal point `.` independent of server locale;
- booleans serialized as `1` or `0`;
- null serialized as an empty cell;
- timestamps serialized as ISO-8601 UTC strings.

The CSV writer is covered by byte-level tests, not only parsed-value assertions.

### 7.2 Schema version and response metadata

The schema identifier is `seo-plan/1`.

After generation, the response includes:

- `X-SEO-Export-Schema: seo-plan/1`
- `X-SEO-Export-Row-Count: <count>`
- `X-SEO-Export-SHA256: <hex digest>`
- `X-SEO-Export-Generated-At: <ISO-8601 UTC>`

The SHA-256 is calculated over the CSV entity bytes handed to the HTTP response, including BOM, header, CRLFs, and escaped cells. Normal browser transport decoding must therefore yield a saved file with the same digest.

These headers are informational and testable. The CSV remains independently useful when saved without HTTP metadata.

### 7.3 Row ordering

Rows are ordered by canonical `keywords.id` ascending.

No UI sort order, pagination state, filter, or saved view changes V1 export ordering. This avoids hidden browser-state coupling and gives the schema a stable contract.

### 7.4 Exact V1 header order

Schema `seo-plan/1` uses this exact ordered header list:

1. `schema_version`
2. `exported_at_utc`
3. `project_id`
4. `project_name`
5. `keyword_id`
6. `keyword_display_text`
7. `keyword_normalized_text`
8. `keyword_status`
9. `source_count`
10. `source_names_json`
11. `intent_assignment_id`
12. `intent`
13. `intent_confidence`
14. `intent_source`
15. `intent_updated_at_utc`
16. `volume_value`
17. `volume_metric_id`
18. `volume_source_id`
19. `volume_source`
20. `volume_observed_at_utc`
21. `difficulty_value`
22. `difficulty_metric_id`
23. `difficulty_source_id`
24. `difficulty_source`
25. `difficulty_observed_at_utc`
26. `cluster_id`
27. `cluster_name`
28. `cluster_primary_keyword_id`
29. `cluster_is_primary`
30. `cluster_intent`
31. `cluster_confidence`
32. `cluster_review_state`
33. `cluster_business_relevance`
34. `cluster_lifecycle_state`
35. `cluster_is_locked`
36. `cluster_revision`
37. `cluster_origin`
38. `cluster_archived_at_utc`
39. `target_id`
40. `target_type`
41. `target_revision`
42. `page_id`
43. `page_title`
44. `page_kind`
45. `page_type`
46. `page_url`
47. `keyword_pending_review_count`
48. `cluster_primary_pending_review_count`
49. `cluster_primary_pending_mapping_conflict`
50. `priority_basis_keyword_id`
51. `priority_volume_value`
52. `priority_volume_metric_id`
53. `priority_volume_source_id`
54. `priority_volume_source`
55. `priority_volume_observed_at_utc`
56. `priority_difficulty_value`
57. `priority_difficulty_metric_id`
58. `priority_difficulty_source_id`
59. `priority_difficulty_source`
60. `priority_difficulty_observed_at_utc`
61. `priority_business_relevance`
62. `priority_has_mapping`
63. `priority_review_complete`
64. `priority_score`
65. `priority_data_completeness`
66. `priority_search_demand_available`
67. `priority_search_demand_points`
68. `priority_difficulty_available`
69. `priority_difficulty_points`
70. `priority_business_relevance_available`
71. `priority_business_relevance_points`
72. `priority_mapping_points`
73. `priority_review_points`
74. `exact_text_json`

Changing this list or its semantics requires a new schema version. Columns may not be silently repurposed.

### 7.5 Exact free-text preservation and spreadsheet safety

CSV files opened by spreadsheet software can execute cells beginning with formula-significant characters such as `=`, `+`, `-`, or `@`. Quoting alone is not sufficient defense.

Every ordinary string cell passes through a spreadsheet-safe serializer before CSV encoding. A string beginning with formula-significant syntax or selected dangerous control whitespace is neutralized as text in the human-facing cell.

To preserve exact source text, every row also contains `exact_text_json`. It is a compact UTF-8 JSON object containing the exact, unmodified free-text values represented by the row, including:

- `project_name`
- `keyword_display_text`
- `keyword_normalized_text`
- `cluster_name`
- `page_title`
- `page_url`

The JSON field begins with JSON structural syntax, not spreadsheet formula syntax. A consumer that needs exact round-trip text can parse this field. The human-facing columns remain convenient and safe for Excel/LibreOffice.

This export remains a reporting/handoff format, not an import contract.

## 8. Data Semantics

### 8.1 Canonical keywords

The export starts from project-scoped canonical `keywords`, never from import staging rows.

Each canonical keyword is emitted exactly once.

### 8.2 Sources

A keyword can have multiple `KeywordSource` rows.

V1 exports:

- `source_count` as the number of source rows;
- `source_names_json` as a compact JSON array of distinct non-empty source labels sorted by deterministic UTF-8 binary order.

The exporter does not expose `KeywordSource.raw_payload`.

### 8.3 Current intent

`keyword_intent_assignments` currently permits multiple rows marked current at the database level, although the domain expects one current assignment.

The export boundary enforces that invariant:

- zero `is_current = true` rows: current-intent columns are blank;
- exactly one current row: it is exported;
- more than one current row: generation fails with `export_integrity_current_intent`.

The exporter does not choose the latest id to hide corruption.

### 8.4 Latest metric selection

The existing Keyword Intelligence workspace defines the latest metric by:

1. greatest `observed_at`;
2. greatest metric `id` as deterministic tie-breaker.

Persian Export uses the same rule for row-level `volume` and `difficulty` and for the priority-basis metrics.

Metric selection is restricted to canonical keyword ids already proven to belong to the authorized project.

### 8.5 Cluster membership

`keyword_cluster_members.keyword_id` is unique, so one keyword has at most one membership.

If membership points to an archived cluster, the export still represents the cluster and its `cluster_archived_at_utc`. Hiding archived membership would lose planning provenance.

For archived clusters, all columns from `priority_basis_keyword_id` through `priority_review_points` are blank. Priority is a current planning signal and is not calculated for archived clusters.

### 8.6 Mapping

`cluster_targets.keyword_cluster_id` is unique, so a cluster has at most one target row.

A target may point to a page or may have no `page_id` according to existing domain rules. Null page data remains null; the exporter never invents a URL or title.

The exporter verifies project identity for keyword, cluster, target, and page even where composite foreign keys already provide database protection.

### 8.7 Review signals

`keyword_pending_review_count` counts pending `ReviewItem` rows for the current row's keyword inside the selected project.

`cluster_primary_pending_review_count` counts pending review rows for the represented cluster's primary keyword inside the selected project.

`cluster_primary_pending_mapping_conflict` is `1` when the cluster primary keyword has a pending `mapping_conflict` review item in the project, otherwise `0`. When there is no cluster, these cluster-level review columns are blank.

### 8.8 Priority basis and parity

The product currently calculates cluster priority from the cluster **primary keyword**, not from every member keyword.

Therefore a secondary keyword row must never imply that its own row-level metrics were used for the repeated cluster score.

For an active cluster:

- `priority_basis_keyword_id` is the cluster primary keyword id;
- all `priority_volume_*` and `priority_difficulty_*` columns come from that primary keyword's latest metrics;
- `priority_business_relevance` is the cluster business relevance;
- `priority_has_mapping` reflects whether the cluster has a target;
- `priority_review_complete` is true only when cluster review state is `reviewed` and there is no pending review item for the cluster primary keyword;
- `ClusterPriorityScore::calculate()` is called directly with those values and their metric evidence.

The resulting priority group is repeated for each member row so spreadsheet consumers can filter/sort any keyword without joining another sheet, but `priority_basis_keyword_id` makes the basis explicit.

The exporter does not create a second priority formula and does not persist a new priority value merely for export.

## 9. Snapshot Consistency

The generated file represents one database snapshot.

Production MySQL/MariaDB generation uses an explicit repeatable-read consistent transaction around every database read required for the export. The transaction starts before keyword iteration and ends immediately after the temporary export stream is complete.

The HTTP response must not keep the database transaction open while the client downloads the file.

The export service therefore:

1. opens the consistent read snapshot;
2. generates the complete CSV into a private rewindable temporary stream in bounded batches;
3. ends the database snapshot;
4. rewinds the stream;
5. returns the download response.

SQLite may use framework transaction behavior in unit/feature tests, but connected MariaDB acceptance is authoritative for production snapshot semantics.

## 10. Memory and Temporary Storage

The exporter must not materialize all project keywords or the full related graph in memory.

Keyword processing uses ascending-id batches (`chunkById` or an equivalent deterministic cursor). Related rows are loaded per batch with bounded queries.

The output uses a rewindable temporary stream such as `php://temp` with a small memory threshold. Large output spills to PHP's private temporary-file mechanism and is removed automatically when the stream closes.

The implementation must not create user-addressable files under `public/` or durable export artifacts under `storage/app/public`.

Normal exception paths close the stream through `try/finally` or equivalent lifetime management.

A large synthetic-project test must prove batch behavior and temporary-stream use. V1 acceptance targets at least 10,000 keyword rows without all-row materialization; the implementation must not encode 10,000 as a product limit.

## 11. Hashing and Row Count

SHA-256 and row count are calculated incrementally while bytes are written.

The implementation must not read the completed CSV into one PHP string to calculate the digest.

After generation, the stream is rewound for download.

## 12. Components and Boundaries

V1 uses these application boundaries:

### `app/Http/Controllers/ProjectSeoExportController.php`

Responsibilities:

- route-model bind project;
- authorize `ProjectPolicy::export`;
- invoke the application service;
- return the prepared download response;
- translate known export exceptions into stable UI errors.

It does not contain query-building, CSV escaping, metric selection, or priority logic.

### `app/Services/Exports/ExportProjectSeoPlan.php`

Coordinates:

- snapshot lifecycle;
- batched keyword iteration;
- row assembly;
- CSV writing;
- hash/row count;
- result metadata.

### `app/Services/Exports/ProjectSeoExportSnapshot.php`

Loads one bounded keyword batch plus current related data with project scoping and integrity checks.

### `app/Support/Exports/SpreadsheetCsvWriter.php`

Responsible only for:

- BOM/header/row bytes;
- RFC-compatible escaping;
- CRLF;
- scalar formatting;
- incremental digest/row accounting.

It does not know Eloquent models.

### `app/Support/Exports/SpreadsheetText.php`

A deterministic serializer responsible for spreadsheet formula neutralization of ordinary string cells and for creating `exact_text_json` from unmodified row text.

### `app/Support/Exports/ProjectSeoExportResult.php`

A small result object carrying the rewindable stream plus non-sensitive metadata: filename, MIME type, row count, schema version, digest, and generated-at timestamp.

No model, migration, or persistent export table is added for V1.

## 13. Error Model

Expected failures receive stable machine codes and Persian operator messages.

At minimum:

- `export_forbidden`
- `export_integrity_current_intent`
- `export_integrity_project_relation`
- `export_temp_stream_unavailable`
- `export_generation_failed`

Authorization remains a normal 403 path.

Integrity failures are not silently skipped. A partial file is never presented as successful.

If generation fails after writing has begun to the private temporary stream, the stream is closed and no download starts.

Logs may contain project id, export correlation id, exception class, and safe diagnostic context. They must not contain credentials, session values, `.env`, or raw import payloads.

## 14. HTTP and Caching

The response uses an explicit CSV content type and attachment disposition.

The response is private and non-cacheable by shared intermediaries:

- `Cache-Control: private, no-store`
- `Pragma: no-cache`
- `X-Content-Type-Options: nosniff`

V1 uses no signed public download URL.

No export-specific rate limiter may be stricter than ordinary interactive use to the point that a normal operator cannot retry a failed download. Authorization, not obscurity or throttling, is the primary bulk-export boundary.

## 15. UI Integration

The action is placed in the existing project Keywords/Intelligence workspace where the current planning state is visible.

The server-provided project capabilities determine whether the action is rendered. Frontend hiding is convenience only; backend authorization is authoritative.

The action label is Persian-first and RTL-compatible. The downloaded filename remains ASCII-safe for broad hosting/browser compatibility.

No new global navigation section is required for V1.

## 16. Test Contract

Implementation is test-driven.

### Authorization and isolation

- Owner/Admin/SEO Manager can export their project.
- SEO Specialist/Content Writer/Viewer cannot export in V1.
- non-member cannot export.
- manipulating project ids cannot leak keyword, metric, cluster, target, page, source, or review data from another project.

### CSV bytes and Persian fidelity

- BOM is the first three bytes.
- the exact 74-column header order matches `seo-plan/1`.
- CRLF row endings are exact.
- commas, quotes, embedded newlines, Persian text, Arabic/Persian character variants, and half-space survive correctly.
- null and numeric formatting are deterministic.
- empty project produces header-only CSV.

### Formula injection

- human-facing strings beginning with `=`, `+`, `-`, `@`, tab, carriage return, or other selected dangerous prefixes are not executable spreadsheet formulas.
- `exact_text_json` preserves the original exact free text.

### Current-state semantics

- no current intent gives blank intent columns.
- exactly one current intent is exported.
- duplicate current intent rows fail rather than guess.
- latest metric uses `observed_at`, then metric id tie-break.
- multiple source rows are summarized deterministically.
- one keyword produces one row.
- archived cluster membership is represented and the full priority group is blank.
- mapping/page fields are correct.
- row-level metrics remain distinct from priority-basis metrics for secondary members.
- `priority_basis_keyword_id` is the primary keyword id.
- review-complete behavior matches the production workspace.
- priority score and data completeness exactly match `ClusterPriorityScore::calculate()`.

### Snapshot and performance behavior

- keyword processing is batched by ascending id.
- no all-keywords materialization occurs.
- at least 10,000 rows exercise bounded batch generation in acceptance/performance-oriented testing without becoming a product cap.
- the temporary stream can spill beyond its memory threshold without changing bytes.
- digest matches exact CSV entity bytes.
- database snapshot closes before response streaming to the client.

### Connected MariaDB 10.11 acceptance

Permanent CI must run a connected acceptance that:

1. migrates a real MariaDB 10.11 database;
2. creates user/workspace/project data;
3. inserts Persian keywords, multiple metric observations, intent, cluster membership, page, target, and reviews;
4. performs an authenticated export;
5. proves project isolation;
6. parses the downloaded CSV and verifies the intended values and 74-column contract;
7. verifies secondary-keyword priority basis uses cluster-primary metrics;
8. verifies exact Persian/half-space text through `exact_text_json`;
9. verifies formula neutralization;
10. verifies metadata row count and SHA-256 against the downloaded bytes.

This acceptance is a permanent CI gate, not a diagnostic workflow.

## 17. Release-Tree Acceptance

The export code must be present and functional in the production release tree created by `ReleasePackageBuilder`.

No development package, Composer executable, Node, npm, pnpm, Vite dev server, or source-only frontend build step may be required at runtime to generate an export.

The existing runtime-only release-tree HTTP smoke remains mandatory after this subsystem is added.

## 18. Security Review Checklist

Before merge, review explicitly for:

- spreadsheet formula injection;
- cross-project joins/subqueries;
- authorization bypass;
- raw payload leakage;
- hidden user/auth data leakage;
- filename/path traversal;
- public temporary files;
- memory blow-up;
- transaction held open during client download;
- ambiguous current-state selection;
- exception-detail leakage.

## 19. Acceptance Criteria

Persian Export is complete only when all of the following are true:

1. an authorized operator can download a Persian-safe CSV from a real project;
2. unauthorized roles cannot bulk-export;
3. one canonical keyword is emitted once and only once;
4. schema `seo-plan/1` uses the exact 74-column contract above;
5. current intent, latest metrics, cluster/mapping, review, and priority semantics match the application;
6. secondary member rows make the cluster-primary priority basis explicit;
7. Persian text and half-space survive byte/parse tests;
8. formula injection is neutralized while exact text remains recoverable through `exact_text_json`;
9. cross-project data cannot leak;
10. large export uses bounded batches and temporary streaming storage;
11. the database snapshot finishes before network transfer;
12. exact CSV entity SHA-256 and row count are exposed and verified;
13. MariaDB 10.11 connected acceptance is permanent and green;
14. PHP 8.4 and PHP 8.5 CI remain green;
15. static analysis, formatter, frontend checks, backend tests, dependency audits, and release-tree HTTP smoke remain green;
16. no external network service is required for core export;
17. the final branch head is reviewed, permanently green, and merged with an exact-head guard.

## 20. Follow-On Boundary

After Persian Export is merged and verified on `main`, the lifecycle roadmap proceeds to Backup/Restore.

Backup/Restore may reuse low-level safe streaming or integrity utilities only where the abstractions are genuinely generic. It must not treat this CSV as a database backup or restore source.
