# Persian Export Design

Date: 2026-10-23
Status: Proposed design after in-chat approval
Branch: `feature/persian-export`
Base: `main@c4f03303408a676d43a3fdbf94f1f49e00affbe5`

## 1. Purpose

The Persian Export subsystem provides a trustworthy, spreadsheet-friendly snapshot of a project's current SEO planning data without turning export into a backup or restore format.

The first release must work on the self-hosted runtime with no Composer, Node, npm, SSH, shell access, external API, or international network dependency. It must remain usable on shared hosting and Iranian hosting environments where outbound internet may be unavailable.

The primary user outcome is simple: an authorized operator can open a project, download one CSV, and receive the current keyword-planning state in a form that is safe for common spreadsheet software, preserves Persian text accurately, respects project isolation, and uses the same domain semantics as the application UI.

## 2. Scope

V1 exports the project's current keyword-planning snapshot as one UTF-8 CSV file.

Each row represents one canonical `Keyword` in the selected project. The row may include current intent, current metrics, cluster membership and state, target/page mapping, review state, and the same priority calculation used by the product.

The export is synchronous and browser-driven. It does not require the queue or scheduler to be healthy.

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
11. **Fail on integrity ambiguity.** If the database contains contradictory “current” state that the domain does not permit, export returns a controlled data-integrity error rather than guessing.

## 5. Authorization

A dedicated `ProjectPolicy::export()` ability will be added.

V1 grants export only to workspace roles whose existing `WorkspaceRole::canManageProjects()` returns true:

- Owner
- Admin
- SEO Manager

SEO Specialist, Content Writer, Viewer, and non-members cannot export bulk project data in V1.

This is intentionally stricter than `ProjectPolicy::view()`. Being allowed to view a project page does not automatically grant bulk data extraction.

The controller must authorize the concrete `Project` before any snapshot work starts.

## 6. User Experience

The project workspace exposes a real `خروجی CSV` action only when the current user is authorized to export.

The action starts a normal authenticated download. V1 does not introduce a modal, export queue, polling UI, fake progress bar, or background job.

The filename is deterministic and operator-friendly:

`seo-plan-v1-<project-slug-or-id>-<UTC timestamp>.csv`

If filename-safe project-name normalization produces an empty result, the project id is used.

For an empty project, the download still succeeds and contains the full header row with zero data rows.

Generation errors return the normal project UI with a stable Persian error message. Internal exceptions are logged without exposing SQL, server paths, or sensitive context to the browser.

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
- booleans serialized as `1` or `0` where a boolean column is present;
- null serialized as an empty cell;
- timestamps serialized as ISO-8601 UTC strings.

The CSV writer must be covered by byte-level tests, not only parsed-value assertions.

### 7.2 Schema version

The schema identifier is `seo-plan/1`.

The response includes non-sensitive metadata headers after generation:

- `X-SEO-Export-Schema: seo-plan/1`
- `X-SEO-Export-Row-Count: <count>`
- `X-SEO-Export-SHA256: <hex digest>`
- `X-SEO-Export-Generated-At: <ISO-8601 UTC>`

These headers are informational and testable. The CSV remains independently useful when saved without HTTP metadata.

### 7.3 Row ordering

Rows are ordered by canonical `keywords.id` ascending.

No UI sort order, pagination state, or saved view changes V1 export ordering. This avoids a hidden dependency on browser state and creates a stable contract.

### 7.4 Column groups

The exact final header list is fixed by the implementation plan and tests, but V1 must contain the following semantic groups.

#### Export identity

- schema version;
- exported-at UTC;
- project id;
- project name;
- keyword id.

#### Keyword

- display text;
- normalized text;
- keyword status;
- source count;
- sorted distinct source names.

V1 does not expose `KeywordSource.raw_payload`.

#### Current intent

- intent;
- confidence;
- source;
- assignment id;
- assigned/updated timestamp where available.

Only the current intent assignment is exported.

#### Latest metrics

For at least `volume` and `difficulty`:

- value;
- metric row id;
- source id;
- source label;
- observed-at UTC.

Metric history is not flattened into additional rows.

#### Cluster

Where a keyword has cluster membership:

- cluster id;
- cluster name;
- primary-keyword id;
- whether this keyword is primary;
- cluster intent;
- confidence;
- review state;
- business relevance;
- lifecycle state;
- lock state;
- revision;
- origin;
- archived-at where applicable.

Because `keyword_cluster_members.keyword_id` is unique, a keyword can have at most one membership row. An archived cluster is still represented as archived membership rather than silently discarded, but archived clusters do not receive a current priority score.

#### Mapping and page

Where the cluster has a target:

- target id;
- target type;
- target revision;
- page id;
- page title;
- page kind;
- page type;
- page URL.

The exporter verifies that the target, cluster, page, and keyword all belong to the same project even though the database already has composite foreign-key protection. Defense in depth remains required at the read boundary.

#### Review/current planning signals

- pending review count for the keyword;
- pending mapping-conflict flag for the keyword/cluster when applicable;
- whether the cluster's review requirements are complete under the same semantics used by priority/readiness logic.

#### Priority

For an active cluster, the exporter uses `ClusterPriorityScore::calculate()` directly with the same current inputs used by the Keyword Intelligence workspace:

- latest volume;
- latest difficulty;
- business relevance;
- presence of mapping;
- review completeness;
- metric evidence.

The CSV includes:

- priority score;
- data completeness;
- component points/availability needed to explain the result.

V1 does not create a second priority formula and does not persist a new priority value merely for export.

### 7.5 Exact free-text preservation and spreadsheet safety

CSV files opened by spreadsheet software can execute cells beginning with formula-significant characters such as `=`, `+`, `-`, or `@`. Quoting alone is not a sufficient defense.

Human-facing free-text columns therefore pass through a dedicated spreadsheet-text serializer. If a value is formula-significant or begins with dangerous control whitespace, the human-facing cell is neutralized as text.

To preserve exact source text, every row also contains one `exact_text_json` column. It is a compact UTF-8 JSON object containing exact, unmodified values for the free-text fields included in the row, including at minimum:

- keyword display text;
- keyword normalized text;
- cluster name;
- page title;
- page URL.

The JSON object itself is serialized as a normal CSV field and begins with a JSON structural character rather than formula syntax. Consumers that require exact round-trip text can parse this column. The human-facing columns remain convenient and safe for Excel/LibreOffice.

This export remains a reporting/handoff format, not an import contract.

## 8. Data Semantics

### 8.1 Canonical keywords

The export starts from `Keyword::where('project_id', $project->id)` and never from import staging rows.

Each canonical keyword is emitted exactly once.

### 8.2 Sources

A keyword can have multiple `KeywordSource` rows.

V1 exports:

- `source_count`;
- distinct non-empty source labels sorted lexicographically and joined with a stable separator.

The exporter does not expose raw source payload JSON.

### 8.3 Current intent

`keyword_intent_assignments` does not currently enforce one current row with a database unique constraint.

Therefore the export query must enforce the domain invariant explicitly:

- zero `is_current = true` rows: export blank intent fields;
- exactly one current row: export it;
- more than one current row: fail generation with a stable data-integrity error.

The exporter does not choose “latest id” to hide corruption.

### 8.4 Latest metric selection

The existing Keyword Intelligence workspace defines latest metric selection by:

1. greatest `observed_at`;
2. greatest metric `id` as deterministic tie-breaker.

Persian Export must use the same selection rule.

Metric selection must be limited to keywords from the authorized project. A relation reached by id is not trusted without project scope inherited from the canonical keyword batch.

### 8.5 Cluster membership

`keyword_cluster_members.keyword_id` is unique, so one keyword has at most one membership.

The exporter includes membership even if the cluster is archived because hiding that fact would lose planning provenance. The row marks the archived state explicitly.

Priority is blank/not-applicable for archived clusters because the main workspace only calculates priority for active clusters.

### 8.6 Mapping

`cluster_targets.keyword_cluster_id` is unique, so a cluster has at most one current target row.

A target may point to an existing/planned page or represent a target type with no `page_id`, according to existing domain rules. Null page data remains null; the exporter never invents a URL or title.

### 8.7 Review completeness

Priority review-complete semantics must match current production logic:

- cluster review state is `reviewed`;
- no pending `ReviewItem` exists for the cluster primary keyword under the current project.

The exporter may expose additional review counts for operator visibility, but the boolean passed into `ClusterPriorityScore` must match the application calculation.

## 9. Snapshot Consistency

The generated file represents one database snapshot.

Production MySQL/MariaDB generation uses a repeatable-read consistent transaction around all database reads required for the export. The transaction starts before row iteration and closes immediately after the temporary export stream has been completed and rewound.

The HTTP response must not keep the database transaction open while the client downloads the file.

The export service writes to a temporary rewindable stream during the database snapshot phase, then commits/ends the read transaction before returning the downloadable response.

SQLite may use the framework transaction behavior in unit/feature tests, but connected MariaDB acceptance is authoritative for production snapshot semantics.

## 10. Memory and Temporary Storage

The exporter must not call `get()` on all project keywords or load the complete project graph at once.

Keyword processing uses ascending-id batches (`chunkById` or an equivalent deterministic cursor). Related rows are loaded per batch with bounded queries.

The output uses a rewindable temporary stream such as `php://temp` with a small configured memory threshold. Large output spills to PHP's private temporary file handling and is deleted automatically when the stream closes.

The design must not create user-addressable files under `public/` or durable export files under `storage/app/public`.

The implementation must use `try/finally` or equivalent lifetime management so normal exceptions close the stream.

A test with a large synthetic project must demonstrate bounded memory behavior indirectly through batch/query behavior and stream usage; it must not rely on an unrealistically tiny fixture only.

## 11. Hashing and Row Count

SHA-256 and row count are calculated while rows are written.

The implementation must not read the completed file into one PHP string to calculate the digest.

The digest covers the exact bytes delivered to the user, including BOM, header, CRLFs, and escaped cells.

After generation the stream is rewound for download.

## 12. Components and Boundaries

The implementation should follow existing Laravel application boundaries and keep each unit focused.

### `ProjectExportController`

Responsibilities:

- route-model bind project;
- authorize `ProjectPolicy::export`;
- invoke the export application service;
- return the prepared download response;
- translate known export exceptions into stable user-facing errors.

It must not contain query-building, CSV escaping, metric selection, or priority logic.

### `ExportProjectSeoPlan`

Application service coordinating:

- snapshot lifecycle;
- batched keyword iteration;
- row assembly;
- CSV writing;
- hash/row count;
- response metadata object.

### `ProjectSeoExportSnapshot` / query collaborator

Responsible for loading one bounded keyword batch plus the current related data with project scoping and integrity checks.

The exact class name may change during the implementation plan, but query responsibilities must stay separate from HTTP and CSV serialization.

### `SpreadsheetCsvWriter`

Responsible only for:

- BOM/header/row bytes;
- RFC-compatible escaping;
- CRLF;
- scalar formatting;
- formula-safe presentation cells;
- incremental digest accounting if that responsibility is most cohesive there.

It must not know Eloquent models.

### `SpreadsheetText`

Small deterministic value serializer responsible for formula-injection neutralization and exact-text JSON source extraction rules.

### Export result DTO

Carries the rewindable stream plus non-sensitive metadata such as filename, MIME type, row count, schema version, digest, and generated-at timestamp.

## 13. Error Model

Expected export failures receive stable machine codes and Persian operator messages.

At minimum:

- `export_forbidden`
- `export_integrity_current_intent`
- `export_integrity_project_relation`
- `export_temp_stream_unavailable`
- `export_generation_failed`

Authorization remains a normal 403 path.

Integrity failures are not silently skipped. A file containing partial rows must never be presented as successful.

If generation fails after writing has started to the private temporary stream, the stream is closed and no download begins.

Logs may include project id, export correlation id, exception class, and safe diagnostic context. They must not include credentials, session values, `.env`, or raw import payloads.

## 14. HTTP and Caching

The download response uses an explicit CSV content type and attachment disposition.

The response is private and non-cacheable by shared intermediaries. Recommended headers include:

- `Cache-Control: private, no-store`
- `Pragma: no-cache`
- `X-Content-Type-Options: nosniff`

The route is authenticated and verified, consistent with the project workspace routes.

Rate limiting may be conservative but must not prevent normal repeated operator exports. V1 does not use signed public download URLs.

## 15. UI Integration

The action is placed in the existing project Keywords/Intelligence workspace where the current planning state is visible.

The server-provided project capabilities determine whether the export action is rendered. Frontend hiding is convenience only; backend authorization remains authoritative.

The action label is Persian-first and RTL-compatible. The downloaded filename remains ASCII-safe for broad hosting/browser compatibility.

No new global navigation section is required for V1.

## 16. Test Contract

Implementation is test-driven. Required coverage includes the following.

### Authorization and isolation

- Owner/Admin/SEO Manager can export their project.
- SEO Specialist/Content Writer/Viewer cannot export in V1.
- non-member cannot export.
- manipulating project ids cannot leak keyword, metric, cluster, target, page, source, or review data from another project.

### CSV bytes and Persian fidelity

- BOM is the first three bytes.
- CRLF row endings are exact.
- commas, quotes, newlines, Persian text, Arabic/Persian character variants, and half-space survive correctly.
- null and numeric formatting are deterministic.
- empty project produces header-only CSV.

### Formula injection

- values beginning with `=`, `+`, `-`, `@`, tab, carriage return, or other selected dangerous prefixes are not executable spreadsheet formulas in human-facing cells.
- `exact_text_json` preserves the original exact value.

### Current-state semantics

- no current intent gives blank intent columns.
- exactly one current intent is exported.
- duplicate current intent rows fail rather than guess.
- latest metric uses `observed_at`, then id tie-break.
- multiple source rows are summarized deterministically.
- one keyword produces one row.
- archived cluster membership is represented and priority is not calculated as active.
- active mapping/page fields are correct.
- review completeness matches production priority semantics.
- priority score and data completeness exactly match `ClusterPriorityScore::calculate()`.

### Snapshot and performance behavior

- processing is batched by keyword id.
- no all-keywords materialization occurs.
- output stream spills beyond its in-memory threshold without changing bytes.
- digest is for exact delivered bytes.
- database snapshot closes before response streaming to the client.

### Connected MariaDB acceptance

A MariaDB 10.11 acceptance path must:

1. migrate a real database;
2. create workspace/user/project data;
3. insert Persian keywords, multiple metric observations, intent, cluster, page, target, and reviews;
4. perform authenticated export;
5. verify project isolation;
6. parse the downloaded CSV and verify the intended values;
7. verify priority parity and exact Persian text;
8. verify the metadata row count and SHA-256 against the downloaded bytes.

This acceptance must run in permanent CI, not as a diagnostic-only workflow.

## 17. Release-Tree Acceptance

The export code must be present and functional in the production release tree created by `ReleasePackageBuilder`.

No development package, Composer executable, Node, npm, pnpm, Vite dev server, or source-only frontend build step may be required at runtime to generate an export.

The existing runtime-only release-tree smoke remains mandatory after this subsystem is added.

## 18. Security Review Checklist

Before merge, review explicitly for:

- spreadsheet formula injection;
- cross-project joins/subqueries;
- authorization bypass;
- raw payload leakage;
- hidden user/auth data leakage;
- path traversal through filenames;
- public temporary files;
- memory blow-up;
- transaction held open during client download;
- ambiguous current-state selection;
- exception detail leakage.

## 19. Acceptance Criteria

Persian Export is complete only when all of the following are true:

1. authorized operator can download a Persian-safe CSV from a real project;
2. unauthorized roles cannot bulk-export;
3. one canonical keyword is emitted once and only once;
4. current intent, latest metrics, cluster/mapping, review, and priority semantics match the application;
5. Persian text and half-space survive byte/parse tests;
6. formula injection is neutralized while exact text remains recoverable through `exact_text_json`;
7. cross-project data cannot leak;
8. large export uses bounded batches and temporary streaming storage;
9. the database snapshot finishes before network transfer;
10. exact output SHA-256 and row count are exposed and verified;
11. MariaDB 10.11 connected acceptance is permanent and green;
12. PHP 8.4 and PHP 8.5 CI remain green;
13. static analysis, formatter, frontend checks, backend tests, dependency audits, and release-tree HTTP smoke remain green;
14. no external network service is required for core export;
15. the final branch head is reviewed, permanently green, and merged with an exact-head guard.

## 20. Follow-On Boundary

After Persian Export is merged and verified on `main`, the lifecycle roadmap proceeds to Backup/Restore.

Backup/Restore may reuse low-level safe streaming or integrity utilities only where the abstractions are genuinely generic. It must not treat this CSV as a database backup or restore source.
