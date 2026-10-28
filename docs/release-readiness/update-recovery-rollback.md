# Update + Interrupted Recovery/Rollback release readiness

Checkpoint: 2026-10-24

This report records what is **verified by Git and CI evidence** for the Update/Rollback subsystem and what is still **not** verified. It is not a claim that the whole self-host lifecycle is finished: Final end-to-end lifecycle acceptance is still open.

## Boundary

Update/Rollback implements a browser-driven, offline-capable release update for the packaged self-host runtime: upload and verify a `seo-update/1` package, quiesce the instance, capture a pre-update safety snapshot, stage an immutable private release slot, switch one atomic active-release pointer, continue on a fresh target request, apply exact-history migrations, validate health and finalize `product_version`. Failed or interrupted updates roll back deterministically or stop in a fail-closed `recovery_required` state.

It is not a remote update service, a release-signing infrastructure, an automatic update scheduler or a Composer/Node-based updater.

Design: `docs/superpowers/specs/2026-10-23-update-recovery-rollback-design.md`  
Written review: `docs/superpowers/specs/2026-10-23-update-recovery-rollback-review.md`  
Implementation plan: `docs/superpowers/plans/2026-10-23-update-recovery-rollback.md`  
Package build tooling: `docs/update-package-build.md`  
Operator notes: `docs/update-recovery-operations.md`  
Integration branch / PR: `feature/update-recovery-rollback` / PR #8

## Integration evidence

All values below were read from GitHub on 2026-10-24.

- PR #8 final feature head: `dd69e765ef4d5dddb977d3595423cded6fe8f592` (139 commits, 99 changed files).
- Exact-head PR CI: workflow `CI` run `40074249167` (pull_request) and `40074245436` (push), both success; workflow `Update Rollback MariaDB` run `40074249250` (pull_request) and `40074245432` (push), both success.
- PR #8 merged into `main` at `2026-10-24T07:29:14Z` as `43342c667859d68a2bfe62b1fb2258aafedb6a5c`.
- Independent post-merge `main` CI at that merge commit:
    - workflow `CI` run `40074320817`: success across `verify`, PHP 8.4, PHP 8.5, Self-host Installer MariaDB, Persian Export MariaDB and Backup/Restore MariaDB;
    - workflow `Update Rollback MariaDB` run `40074320854`: success (connected exact-suffix rollback acceptance and connected fresh-runtime recovery acceptance, both with `--fail-on-skipped`).

Deviation from the plan text: the Update/Rollback MariaDB gate is a separate workflow file (`.github/workflows/update-rollback-mariadb.yml`), not a seventh job inside `ci.yml`. It runs on `main`, `feature/**` and pull requests, so it is a permanent gate; branch protection must require it explicitly if merge protection is configured.

## Implemented contract (by plan task)

1. Stable launcher, active-release pointer resolution and stable root binding (`bootstrap/runtime-release.php`; corrupt/absent/symlinked pointers fail closed).
2. `seo-update/1` manifest, streaming writer/reader and release path contract.
3. Durable update journal, atomic pointer writer and persistent maintenance gate.
4. Shared verified system-state snapshot recovery kernel reused from Backup/Restore.
5. Owner-only upload verification and safe preview.
6. Quiescence, safety snapshot, immutable release staging and additive public assets.
7. Atomic source-to-target switch and pre-migration bootstrap failure recovery.
8. Exact-history migration runner with verified reverse suffix.
9. Target health validation and finalization (only `product_version` is updated).
10. Interrupted-update rollback and source-version snapshot recovery coordinator.
11. Persian-first owner-only settings UI (`به‌روزرسانی و بازیابی نسخه`) and owner-only continue/recover routes.
12. Update package build tooling (`scripts/release/build-update-package.php`) and runtime release-slot smoke (`scripts/release/smoke-update-runtime.php`, run in the `verify` job).
13. Connected MariaDB acceptance and permanent gate (partially complete; see gaps).

## Verified behavior and where it is proven

SQLite feature tests (run in `verify`, PHP 8.4 and PHP 8.5):

- fresh target migrates, finalizes only version, preserves local state/sessions/pending work (`ContinueSystemUpdateTest`);
- source process cannot continue target migrations in the same request graph; HTTP continuation rejects a source request then finalizes on a fresh target request (`ContinueSystemUpdateTest`);
- health failure retains target and the exact migration suffix for the recovery coordinator; history drift fails closed; unlogged partial DDL requires recovery; corrupt/missing/foreign safety bundle blocks before any migration (`ContinueSystemUpdateTest`);
- broken target before migration reverts exactly to the root source or the exact prior slot; after migration started it never reverts the pointer (`TargetBootstrapFailureTest`);
- reserved database queue work blocks safety capture/staging (`PrepareSystemUpdateTest`, `LifecycleDatabaseQueueTest`);
- exact-suffix rollback, interruption after source-pointer switch resumes on the source release, ambiguous migration state retains all artifacts and never takes the bootstrap-only escape, HTTP recovery demands a fresh login (`RecoverInterruptedUpdateTest`);
- switch/confirm authorization, stale digest, source pointer drift and unresolved-restore refusal (`SwitchSystemUpdateReleaseTest`).

Connected MariaDB 10.11 (permanent `Update Rollback MariaDB` workflow):

- `UpdateRollbackMariaDbAcceptanceTest`: real exact-suffix rollback restores the source snapshot, domain data, durable file, `.env` bytes, installation identity, pending queue row and both source/target public assets.
- `UpdateRuntimeProcessMariaDbAcceptanceTest`: target recovery and source recovery run in two separate real PHP processes booted from the slot release and the root release respectively.
- `UpdateMigrationsMariaDbAcceptanceTest` (runs in the `backup-restore-mariadb` job of `ci.yml` with `--fail-on-skipped`, using the `BACKUP_*` connected environment): non-transactional DDL reverses the exact suffix, unlogged persistent DDL fails closed, process exit during committed data-only work never repeats it, queue admission commits reservations before releasing lifecycle ownership, data-only history drift is not mistaken for a completed down.

Runtime release tree (`verify` job): package build and root -> private slot -> root smoke with stable environment/storage and source/target asset checks, no Composer/Node on the simulated production tree.

## Connected browser lifecycle acceptance (2026-10-28)

Branch `feature/update-connected-acceptance`, permanent workflow `Lifecycle E2E` (`.github/workflows/lifecycle-e2e.yml`, driver `tests/Acceptance/Lifecycle/`). A real Chrome drives a runtime-only release tree served by separate PHP processes against MariaDB 10.11, with the installer-prescribed cron/worker commands running:

browser install -> realistic Persian keyword data -> Persian update UI:

1. successful update (version, active slot, schema, data, `.env`, installation identity, journal closed, target asset served, grid served);
2. target bootstrap failure -> launcher reverts to source -> recovery;
3. post-migration health failure -> exact suffix reversed -> source snapshot -> re-login;
4. web-server process killed during a target migration -> exact rollback to source schema/data;
5. pre-switch block (reserved queue work) -> owner cancels -> retry succeeds;
6. migration-history drift after switch -> `recovery_required`, no automatic recovery offered, artifacts retained, traffic fail-closed.

First green exact-head run: `Lifecycle E2E` 40641665038 (with `CI` 40641664954 and `Update Rollback MariaDB` 40641664934) at `042d5b3`.

Defects this acceptance exposed and fixed (each with a RED regression test):

- browser install HTTP 500 before any database (rate limiter bound to the database cache) — `fd6c8d7`;
- fresh runtime rendered English/LTR — `922f94b`;
- installer step 6 could not re-read cron/worker evidence;
- update impossible on installations using the `mariadb` driver the installer writes (fingerprint branch only accepted `mysql`); export/backup snapshot isolation skipped on that driver — `ecb40bd`;
- every request after the release switch fatally redeclared the launcher classes — slot launcher copy loaded during maintenance admission;
- pre-switch failures left a permanent maintenance gate with no exit — owner cancel (design §22.1);
- any target migration exception/interruption was terminal; now rolled back when no DDL/history residue exists (design §28), residue still fails closed — `95d1ba3`;
- queue polling took the exclusive lifecycle lock and turned concurrent user requests into 503s.

## Known gaps — NOT verified

1. **Whole-branch security review** — see the review record below once completed.
2. **Package authenticity is not provided.** SHA-256 proves integrity only. The approved V1 scope is trusted-code manual installation (design §3/§29); release signing remains **required before public or commercial automatic distribution** and is not claimed.
3. **Host upload limits.** Release packages (~38 MB today) exceed PHP's default `upload_max_filesize`/`post_max_size` (2M/8M). Operators must raise them (documented requirement; acceptance runs with 256M). Chunked upload is not implemented.
4. **Framework strings are English** (validation, throttling, password-confirmation page) — no `lang/fa` yet.

## Rollback semantics (operator-relevant)

- Pre-migration target bootstrap failure: the dependency-free launcher restores the source pointer without any database mutation.
- Migration or health failure: only the exact verified target migration suffix is reversed; the source release is re-activated; then the source-version safety snapshot is applied from a fresh source request. Login is required afterwards.
- Ambiguous partial/unlogged DDL, migration-history drift, a corrupt or missing safety snapshot, runtime-secret drift or an unprovable source schema end in `recovery_required`. Automatic recovery then refuses to guess; all artifacts (safety snapshot, journal, staged slot) are retained for manual review.
- Destination DB credentials, APP_KEY, `.env`, installation id and database fingerprint are never rewritten by update or recovery.

## Verification commands (CI is the source of truth)

```bash
composer install --prefer-dist --no-interaction
cp .env.example .env && php artisan key:generate
php artisan migrate --force
npm ci
php artisan wayfinder:generate --with-form
npm run check && npm run types:check && npm run build
composer lint:check && composer types:check
php artisan test
php scripts/release/smoke-update-runtime.php /absolute/built-runtime-release
```

The connected gate additionally needs MariaDB 10.11 and the `UPDATE_ROLLBACK_*` environment documented in `.github/workflows/update-rollback-mariadb.yml`.
