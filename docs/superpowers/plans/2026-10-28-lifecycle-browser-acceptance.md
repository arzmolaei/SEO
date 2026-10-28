# Lifecycle Browser Acceptance Implementation Plan

**Date:** 2026-10-28

**Goal:** Replace lifecycle service-level domain seeding with real browser workflows, prove Keyword Intelligence/export/backup/restore end to end, then run the existing update/recovery matrix on the restored installation.

**Base:** `feature/workspace-entry-keyword-import@962244e60ecc79c6925261da0d915855ea5e63f1`

## Constraints

- Work only on `feature/lifecycle-browser-acceptance`; do not merge PR #22 or modify `main`.
- Ordinary product operations must use the browser UI. The lifecycle harness may only prepare disposable infrastructure, observe evidence, and inject controlled update faults.
- Preserve all existing interruption, rollback, drift, and fail-closed update cases.
- Require exact-head GitHub Actions evidence before claiming completion.

## Task 1 — Real project/import flow

Files: `tests/Acceptance/Lifecycle/run.mjs`, `tests/Acceptance/Lifecycle/harness.php`

1. Create `example.ir` from `/dashboard` through the Persian project form and derive the project id from the resulting UI link.
2. Upload `fixtures/keywords-fa.csv` at `/projects/{project}/imports`.
3. Map `Keyword` and `Search Volume`, submit background processing, poll server-truth UI state while the real database queue worker runs, then commit valid rows from the UI.
4. Assert the committed keyword grid contains the fixture data and the installation has at least 30 committed keywords.
5. Remove the obsolete `harness('seed')` command and its service/model imports so lifecycle acceptance cannot silently regress to service-level domain setup.

## Task 2 — Keyword Intelligence and Persian export

File: `tests/Acceptance/Lifecycle/run.mjs`

1. From the keyword workspace, trigger cluster suggestions through the real UI and wait for the background worker.
2. Open Review Queue and accept a real cluster suggestion, then return to the keyword workspace.
3. Mark a cluster reviewed, create a planned target page, search it through the UI, and map the cluster to that page.
4. Download `/projects/{project}/exports/seo-plan.csv` through the authenticated browser session and validate HTTP metadata, UTF-8 BOM, `seo-plan/1` header content, Persian text, and a real ZWNJ.

## Task 3 — Browser backup/restore into a clean installation

File: `tests/Acceptance/Lifecycle/run.mjs`

1. Download a real `.seobak` from instance A through `/settings/backup` and persist only the disposable CI copy.
2. Browser-install clean instance B on a separate disposable MariaDB database with a distinct destination owner/installation identity.
3. Upload, verify, digest-confirm, and restore the backup through the browser UI.
4. Verify with non-secret hashes/evidence that source `APP_KEY` became usable, the destination database remained selected, destination installation identity was preserved, `restored_from_installation_id` points to source, and source domain data was restored.
5. Log in to B using the restored source owner and prove the restored project/keywords are operable.

## Task 4 — Existing update/recovery matrix on restored instance

File: `tests/Acceptance/Lifecycle/run.mjs`

Run the existing successful update, broken boot recovery, post-migration health rollback, hard interruption, pre-switch quiescence block/cancel/retry, and migration-history drift fail-closed cases against restored instance B. Preserve current assertions and add an explicit post-success operational-health check on restored project data.

## Task 5 — Verification and handoff

Files: `docs/HANDOFF.md`, `docs/PROJECT_STATE.md`

1. Push the bounded implementation commit and inspect exact-head `CI`, `Update Rollback MariaDB`, and `Lifecycle E2E` results.
2. Diagnose failures before patching; if product code is required, use RED → GREEN and do not weaken gates.
3. After implementation-head workflows are green, update the two authoritative handoff/state documents with exact commit SHA, workflow run IDs, remaining limitations, and next action.
4. Push the documentation checkpoint and verify exact-head workflows again before completion claims.
