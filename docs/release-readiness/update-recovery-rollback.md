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

## Known gaps — NOT verified

Do not describe Update/Rollback as fully release-ready until these are closed or explicitly accepted.

1. **No connected-MariaDB successful-update acceptance.** Plan Task 13 asks for a full success path on real MariaDB: verify -> maintenance -> drain -> safety snapshot -> private slot -> assets -> pointer switch -> fresh target boot -> migrations -> health -> `.installed.product_version`. The success path is proven only at SQLite feature level and by the release-slot smoke, not as one connected MariaDB run.
2. **No connected-MariaDB acceptance for bootstrap failure and post-migration health failure.** These are proven at SQLite level only.
3. **No connected-MariaDB acceptance for hard interruption between target migration and rollback, nor an unexpected-drift -> `recovery_required` case at the whole-update level.** Drift and interruption are covered at runner/coordinator level (including MariaDB for DDL/data-only interruption), not as a full-lifecycle connected run.
4. **Whole-branch review (plan Task 13 Step 5) has no recorded artifact in the repository.** Only the written design review and per-PR reviews exist. Treat the final launcher-fail-open / secret-leakage / migration-guess / maintenance-bypass / stale-snapshot review as open.
5. **Package authenticity is not provided.** SHA-256 proves integrity only. V1 is trusted-code manual installation. Release signing remains **NEEDS VERIFICATION before public or commercial automatic distribution**.
6. **No browser-driven acceptance of the Persian update page against a real packaged runtime.** That belongs to Final end-to-end lifecycle acceptance and has not been done.

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
