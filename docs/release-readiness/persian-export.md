# Persian Export release readiness

Checkpoint: 2026-10-23

## Boundary

Persian Export implements the versioned `seo-plan/1` project handoff format as a synchronous, browser-driven CSV export. It is deliberately not a backup/restore format and does not create a durable export artifact, migration, export-history table, queue job, scheduler dependency, external API dependency, crawler, SERP integration or AI dependency.

Design: `docs/superpowers/specs/2026-10-23-persian-export-design.md`  
Implementation plan: `docs/superpowers/plans/2026-10-23-persian-export.md`  
Integration branch / PR: `feature/persian-export` / PR #5  
Base: `main@c4f03303408a676d43a3fdbf94f1f49e00affbe5`

## Implemented contract

- Exact endpoint: `GET /projects/{project}/exports/seo-plan.csv` under authenticated + verified middleware.
- Dedicated `ProjectPolicy::export()` ability. Owner, Admin and SEO Manager may export; SEO Specialist, Content Writer, Viewer and non-members may not bulk-export project data.
- Schema id: `seo-plan/1` with an immutable ordered 74-column V1 header.
- One canonical project keyword per row, ordered by keyword id ascending.
- UTF-8 BOM, comma delimiter, RFC-compatible quoting, CRLF line endings, `.` decimal notation, ISO-8601 UTC timestamps, `1`/`0` booleans and blank nulls.
- Spreadsheet formula/control-prefix protection with exact original Persian/free text retained in `exact_text_json`.
- Current intent integrity enforcement: contradictory current intent fails the export rather than guessing.
- Latest volume/difficulty semantics match Keyword Intelligence: `observed_at` descending, then metric id descending as deterministic tie-breaker.
- Cluster/mapping/review state remains explicitly project-scoped.
- Cluster priority calls the existing `ClusterPriorityScore::calculate()` implementation. Secondary keyword rows expose their own row metrics while repeated cluster priority explicitly names the cluster primary keyword as `priority_basis_keyword_id`.
- Archived cluster provenance is retained while priority fields are blank.
- Generation uses deterministic batches of 250 and a private `php://temp/maxmemory:2097152` rewindable stream; no project-wide keyword collection or user-addressable temporary file is created.
- MySQL/MariaDB generation uses a repeatable-read transaction. The complete CSV is generated before commit; the transaction is closed before the HTTP body is streamed to the browser.
- SHA-256 and row count are calculated incrementally while bytes are written. Response metadata exposes schema, row count, digest and generated-at time.
- Known integrity/generation failures return stable Persian operator messages; no partial CSV is returned as success.
- Keywords workspace exposes a real normal-browser download link (`خروجی CSV`) only when `canExport` is true, and renders stable export failures as an alert. It intentionally does not use Inertia navigation for the CSV response.

## Verification evidence

### Permanent implementation gate before final review documentation

Commit `1898ee48349948d8caa28004920c6e396ae96326` passed permanent CI run `39911325085` across all five permanent jobs:

- PHP 8.4 compatibility — success;
- PHP 8.5 compatibility — success;
- full `verify` job — success, including frontend formatter/lint, TypeScript, production build, Pint, PHPStan/Larastan, backend tests, runtime npm/Composer audits, runtime-only Composer install and release-tree HTTP smoke;
- existing self-host Installer MariaDB 10.11 acceptance — success;
- dedicated Persian Export MariaDB 10.11 acceptance — success.

The dedicated export gate runs with `--fail-on-skipped` and covers:

1. a 10,000-keyword project through the real export coordinator, proving bounded batching, private temp-stream use, ascending row order, incremental SHA-256 and absence of public/durable export files;
2. a connected MariaDB 10.11 project built through the real Stage -> Commit keyword import path, Persian text with a real ZWNJ, formula-dangerous values, current intent, primary/secondary cluster membership, planned-page mapping, metric history, priority-basis parity, project isolation, raw-payload exclusion, exact-text recovery, viewer denial and transaction level returning to zero before response streaming.

### Whole-branch performance review finding

Review found one important shared-host performance defect: selected metrics were validated by issuing `Keyword::find()` for each selected metric even though the bounded relation-keyword batch had already been loaded and project-validated. Regression commit `5f262644f25d4155dc96c8b153086b31772ed6f0` proved four redundant point lookups for only two keywords.

The verified fix is commit `1e3aaf62a4297c12cedfbf5d5d435dd6f6b1ef09`. One-shot verification run `39911869862` succeeded with the query-budget regression test, the focused Export suite, Pint and PHPStan/Larastan before creating the fix commit; the temporary workflow removed itself in that same commit. Metric validation now uses the already validated bounded keyword-id set and source map instead of per-metric keyword queries.

The documentation-complete branch head must still pass the permanent five-job CI gate before PR #5 is merged. Older success is implementation evidence, not permission to merge a newer head.

## Whole-branch review focus and result

Review checked:

- project-id exfiltration and guessed-project authorization;
- raw import payload / secret leakage;
- spreadsheet formula/control-prefix handling and exact Persian text preservation;
- contradictory-current-intent behavior;
- partial-file success on generation failure;
- transaction lifetime crossing HTTP streaming;
- project-wide materialization and query fan-out;
- temporary/public file leftovers;
- duplicate priority formula semantics;
- archived-cluster ambiguity;
- dead/fake UI actions.

No remaining Critical or Important correctness/security finding is known after the N+1 regression fix. Permanent final-head CI and PR review remain the merge gate.

## Explicit non-goals

Persian Export does not back up or restore the application. It does not contain `.env`, credentials, users, sessions, cache, queue jobs, uploads, full metric history, raw import payloads or full audit history. It is not an import contract and it creates no persistent export record.

Backup + Restore is the next separate lifecycle subsystem after PR #5 is merged and post-merge `main` CI succeeds.
