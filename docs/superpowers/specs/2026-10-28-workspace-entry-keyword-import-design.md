# Workspace Entry + Keyword Import UI — Design

Date: 2026-10-28
Status: Approved-by-delegation (routine product/technical decisions delegated by the owner).
Base: `feature/update-connected-acceptance` (Update/Rollback gaps closed).

## 1. Problem (verified, not assumed)

A real-browser acceptance of a clean packaged runtime showed that an installed owner cannot perform the primary product workflow:

- after install/login the owner lands on the starter-kit placeholder `dashboard` (English "Dashboard", no navigation to workspaces or projects);
- project listing/creation exist only as JSON endpoints (`workspaces/{workspace}/projects`);
- `ProcessKeywordCsvImport` and `CommitKeywordImportBatch` are reachable from no route, job or page (only tests call them); `keyword-imports/stage` accepts pre-parsed JSON rows only.

So "import → intelligence → export" — required by the first usable self-host release gate — is impossible for a user. Keyword grid, intelligence panel, review queue and Persian export pages already exist and are reused unchanged.

## 2. Scope

In scope (this slice):

1. Persian/RTL **home** at the existing `/dashboard` route (Fortify/installer home): the user's workspaces with their projects (server-paginated), empty states, and a create-project form for members allowed to manage projects.
2. **Keyword import** page per project (`/projects/{project}/imports`):
   - CSV upload or pasted keywords (one per line) — both become one private original file;
   - header detection + bounded preview, explicit column mapping (`keyword` required, `volume` optional — the fields the existing pipeline supports);
   - background processing through the existing resumable stager on the database queue;
   - staged review with server counts and a bounded, paginated list of invalid rows;
   - commit through `CommitKeywordImportBatch` on the queue; discard.
3. Overview call-to-action into import/keywords; sidebar entry for imports.

Out of scope (recorded for later slices): XLSX and vendor presets (KWFinder/Ahrefs/Semrush/Keyword Planner), additional metric columns (difficulty/CPC/competition), mapping templates, multi-workspace administration UI.

## 3. Decisions

- **Reuse, don't parallel.** Staging/normalization/dedup/commit stay in existing services. New code is controllers, two queue jobs, one small reader of headers/preview, UI, and one migration.
- **Original file preserved** (spec §10.3 "original file") under `storage/app/private/uploads/keyword-imports/{project}/{batch}.csv` — inside a durable backup root, so Backup/Restore and Update snapshots carry it. Never web-served. New nullable columns `import_batches.source_path` (relative, validated) and `source_bytes`.
- **States** (string column, no enum change): `awaiting_mapping` → `queued` → `processing` → `staged` → `committing` → `committed`; `failed`; `discarded`. Existing services keep owning `processing/staged/failed/committed`.
- **Background work on the database queue** (self-host default). Requests never parse or commit large files synchronously (shared-host execution limits). The page reports only server truth: status, rows processed so far (`rows()->count()`), timestamps; it says when work is waiting for the worker; no fake percentages. Jobs are idempotent: processing resumes from `checkpoint_row`; commit is already idempotent for `committed` batches.
- **Bounds:** upload ≤ `keywords.import.max_bytes` (default 20 MiB), pasted text ≤ 1 MiB / 50 000 lines; preview reads at most 5 data rows; invalid-row list paginated (25).
- **Authorization:** list/view = `ProjectPolicy::view`; upload/map/commit/discard = `stageKeywordImports`; batch must belong to the route project (scoped binding) — project boundary is the authorization boundary. CSRF via Inertia forms.
- **Errors:** user sees Persian messages; raw exception text (which can contain paths) is never rendered; `failure_message` shown only as a generic code-mapped message.
- **Activity:** commit is attributed to the acting user (existing service). Discard is attributable (`discarded_by`), staged rows deleted, original file removed.

## 4. Failure modes considered

- worker not running → batch stays `queued`, page says so and links nothing external;
- worker killed mid-processing → job retry resumes from checkpoint; duplicate rows not re-staged (stager contract);
- double-submit of commit → second job sees `committed`/`committing` and no-ops;
- concurrent discard vs commit → both transition under a row lock with expected-status checks;
- foreign batch id in another project's URL → 404 via scoped binding;
- maintenance (update/restore) → queue admission already refuses reservations; uploads are ordinary requests and are blocked by the existing gate.

## 5. Verification

- Feature tests (SQLite): home lists only the user's workspaces/projects and creates projects with authorization; import upload/paste → mapping → queued job → staged → commit/discard; cross-project/tenant denial; bounds and validation; Persian error rendering without internal messages.
- Lifecycle browser acceptance (MariaDB + packaged runtime) replaces the service-level seeding with the real UI flow, then continues to intelligence, export, backup/restore and update.
