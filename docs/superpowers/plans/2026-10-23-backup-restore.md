# Backup + Restore Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` and execute this plan task-by-task. Every product-code task follows RED -> observed failure -> minimal GREEN -> focused verification -> semantic commit.

**Goal:** Add a browser-driven, offline-capable, full-instance Backup + Restore lifecycle for the packaged self-hosted SEO OS runtime, with strict owner authorization, deterministic versioned bundles, clean-target restore, interruption recovery, preserved local installation identity, and permanent MariaDB 10.11 acceptance.

**Architecture:** Introduce shared lifecycle primitives (`ProductVersion`, `SystemOwner`, `LifecycleMutex`, `MaintenanceWindow`) first. Define one explicit durable-table catalog and schema fingerprint. Capture durable DB/settings/files into a deterministic application-owned `seo-backup/1` container. Verify an uploaded bundle completely before mutation. Restore only into a clean compatible installation, preserve destination local identity and DB credentials, replace source APP_KEY only when applying restored durable state, and use a durable journal plus an internal safety snapshot for fail-closed recovery.

**Tech Stack:** Laravel 13, PHP 8.4.1+, Eloquent/query builder, Inertia 3, React 19, TypeScript, MySQL/MariaDB 10.11, PHPUnit 12.5, filesystem primitives only for archive/lifecycle operations.

**Spec:** `docs/superpowers/specs/2026-10-23-backup-restore-design.md`

**Written-spec review:** `docs/superpowers/specs/2026-10-23-backup-restore-review.md`

## Global Constraints

- Runtime floor remains PHP `>= 8.4.1`; permanent PHP compatibility gates remain 8.4 and 8.5.
- Browser/runtime behavior must not require Composer, Node, npm, SSH, shell commands, `mysqldump`, ZIP/Phar, external APIs or international network access.
- V1 bundle schema is exactly `seo-backup/1` with magic `SEOBACKUP\n` and the framing contract in the design.
- V1 user backups are intentionally not passphrase-encrypted. Treat the bundle as highly sensitive and never display/log APP_KEY, hashes, credentials or raw restore internals.
- Only the installer-created system owner may use Backup/Restore; requests also require authenticated + verified + recent-password confirmation.
- Durable-table catalog uses **`import_batches`**, not the design typo `keyword_import_batches`.
- Durable restore keeps foreign-key enforcement enabled. Parent-first inserts and reverse child-first deletes are the correctness mechanism.
- Production restore is same non-`dev` ProductVersion + same application-schema fingerprint only. No merge restore and no cross-version migration in V1.
- Source APP_KEY is backed up because durable encrypted values require it. Destination DB credentials, APP_URL, production safeguards, mail/storage/external secrets and unrelated env values are never backed up or overwritten.
- Destination `installation_id` and database fingerprint remain destination-local after restore. Record source provenance separately.
- Complete bundle verification and clean-target evaluation happen before destructive mutation.
- `LifecycleMutex` serializes Backup, Restore and future Update/Rollback. Maintenance mode is an additional quiescence guard.
- An interruption after destructive progress must never be guessed through. It becomes `recovery_required` until safety recovery completes.
- No lifecycle temporary/upload/recovery artifact may be public-web-addressable.
- Every future durable-file mutator must acquire `LifecycleMutex`; this feature must document that invariant.
- Connected MariaDB 10.11 backup/restore/recovery acceptance is a permanent merge gate.
- Current `main` product tree at planning time is `79494174fc0a1d6be23fb7595f3fffa5c3187596`; current `main` commit is `fabf2bf02d18c4e14f4aa97b6ab819da8620b174`.
- This session cannot clone GitHub because its container DNS cannot resolve github.com. When executing here, use official `feature/**` GitHub Actions as the observed RED/GREEN runner and do not claim local test evidence.

## Verified Durable Restore Order

Parent-first insert order:

`users` -> `passkeys` -> `workspaces` -> `memberships` -> `projects` -> `import_batches` -> `staged_keyword_rows` -> `keywords` -> `keyword_sources` -> `keyword_metrics` -> `saved_keyword_views` -> `change_sets` -> `activity_events` -> `keyword_intent_assignments` -> `review_items` -> `keyword_clusters` -> `keyword_cluster_members` -> `pages` -> `cluster_targets`.

Delete order is the exact reverse.

## Review Focus

1. Never accept `keyword_import_batches` into runtime catalog/manifest code; catalog drift must fail tests.
2. ProductVersion must have one source of truth and production release packaging must reject missing/blank/`dev` identity.
3. Owner fallback for legacy locks must fail closed when the original system owner cannot be resolved unambiguously.
4. Bundle parser must be length-bounded, path-safe, duplicate-safe and trailing-data-safe without attacker-sized preallocation.
5. APP_KEY is included, but DB/mail/storage/external credentials are excluded and destination connection identity is preserved.
6. Restore must prove target cleanliness before mutation and reject a second restore.
7. Post-DB-commit interruption must produce `recovery_required`; recovery must restore the destination bootstrap DB/files/key/lock metadata exactly.
8. Backup/restore/update serialization must not rely on maintenance mode alone.
9. MariaDB acceptance must exercise Persian/half-space data and representative intent/cluster/mapping/review state, not only trivial users/projects.
10. Release-tree acceptance must prove the lifecycle code/UI and stable VERSION survive `composer install --no-dev` packaging.

---

### Task 1: Stable ProductVersion and Release-tree Identity

**Files:**
- Create: `VERSION`
- Create: `app/Support/Lifecycle/ProductVersion.php`
- Modify: `app/Support/ReleasePackageBuilder.php`
- Modify: `app/Support/ReleasePackageContract.php`
- Create: `tests/Unit/System/ProductVersionTest.php`
- Create: `tests/Unit/System/LifecycleReleaseIdentityTest.php`

**Interfaces:**
- `ProductVersion::__construct(?string $versionPath = null)` defaults to `base_path('VERSION')`.
- `ProductVersion::current(): string` returns trimmed file content or `dev` only when the source file is unavailable/blank in local/test usage.
- `ProductVersion::isDistributable(): bool` is false for empty/`dev` and true for a stable non-dev identifier.
- `ProductVersion::requireDistributable(): string` throws a controlled `RuntimeException` for missing/blank/`dev` identity.
- Release packages must contain root `VERSION`; release contract validates it as non-empty and non-`dev`.

- [ ] **Step 1: Write ProductVersion RED tests** for trimmed stable value, missing file -> `dev`, blank -> `dev`, and `requireDistributable()` rejection for `dev`.
- [ ] **Step 2: Write release identity RED tests** using temporary source/release trees. Assert missing VERSION and `dev` VERSION cannot yield a valid production release tree; stable VERSION is copied byte-for-byte and accepted.
- [ ] **Step 3: Observe RED** with `php artisan test tests/Unit/System/ProductVersionTest.php tests/Unit/System/LifecycleReleaseIdentityTest.php`.
  Expected: FAIL because lifecycle ProductVersion and release VERSION contract are absent.
- [ ] **Step 4: Implement minimal ProductVersion and release builder/contract changes.** Do not add updater behavior.
- [ ] **Step 5: Verify GREEN** with the same focused command, then `php artisan test tests/Unit/System/ReleasePackageBuilderTest.php tests/Unit/System/ReleasePackageContractTest.php` and `composer types:check`.
- [ ] **Step 6: Commit** `feat: add stable lifecycle product version`.

**Ruling:** The repository VERSION value used during pre-release development may be a stable pre-release identifier; marketing/final release naming is `NEEDS VERIFICATION` before Launch, but the technical invariant is non-empty and non-`dev`.

---

### Task 2: Installation Metadata Upgrade and System Owner

**Files:**
- Modify: `app/Support/InstallationState.php`
- Modify: `app/Actions/Installer/FinalizeInstallation.php`
- Modify: `config/installer.php`
- Create: `app/Support/SystemOwner.php`
- Create: `tests/Feature/System/BackupInstallationStateTest.php`
- Create: `tests/Feature/System/SystemOwnerTest.php`

**Interfaces:**
- `.installed` metadata additionally allows `owner_user_id`, `restored_from_installation_id`, `restored_at`.
- `InstallationState::updateMetadata(array $changes): array` performs atomic lock rewrite, preserves immutable/local fields unless explicitly allowed by this API, keeps restrictive permissions and returns normalized resulting metadata.
- FinalizeInstallation gets the validated owner id from installer journal state, persists it, and uses `ProductVersion::current()` rather than a parallel version source.
- `SystemOwner::resolve(): User` returns the persisted owner when valid. For a legacy lock with no owner id, resolve exactly one valid earliest Owner-membership user, persist once, otherwise fail closed.
- `SystemOwner::is(User $user): bool` compares against resolved system owner id.

- [ ] Write RED tests for new finalization metadata and atomic rewrite preserving `installation_id`/database fingerprint.
- [ ] Write RED tests for persisted owner, legacy unambiguous fallback, ambiguous/invalid fallback denial, and non-owner denial.
- [ ] Observe RED with the two new focused test files.
- [ ] Implement InstallationState metadata upgrade and SystemOwner minimally.
- [ ] Verify focused GREEN plus existing `InstallerStateTest.php`, `InstallerFinalizationTest.php`, `InstallerOwnerTest.php`, then static analysis.
- [ ] Commit `feat: persist and resolve system owner identity`.

---

### Task 3: Shared Lifecycle Mutex and Maintenance Window

**Files:**
- Create: `app/Support/Lifecycle/LifecycleMutex.php`
- Create: `app/Support/Lifecycle/LifecycleMutexHandle.php`
- Create: `app/Support/Lifecycle/MaintenanceWindow.php`
- Modify: `config/backup.php` (create in this task if absent)
- Create: `tests/Unit/System/LifecycleMutexTest.php`
- Create: `tests/Feature/System/MaintenanceWindowTest.php`

**Interfaces:**
- Default mutex path: `storage/app/lifecycle/lifecycle.lock`.
- `LifecycleMutex::acquire(string $operation): LifecycleMutexHandle` uses exclusive non-blocking filesystem locking, records non-secret owner/operation metadata, fails closed if held, and releases idempotently.
- Callers that already own the handle may pass lifecycle ownership to nested capture primitives; never recursively reacquire the same lock.
- `MaintenanceWindow::run(string $operation, Closure $callback): mixed` enters Laravel maintenance state, always attempts cleanup on normal exceptions, and distinguishes lifecycle-owned stale state from unrelated administrator maintenance.
- No DB/file secret is written to lock metadata.

- [ ] RED: exclusivity, idempotent release, stale metadata behavior, nested ownership contract.
- [ ] RED: maintenance enters before callback and exits after success/failure without clearing unrelated maintenance state.
- [ ] Implement minimal primitives.
- [ ] Verify focused GREEN and full backend tests.
- [ ] Commit `feat: add shared lifecycle serialization primitives`.

---

### Task 4: Durable Catalog, Schema Fingerprint, Manifest and Row Codec

**Files:**
- Modify/Create: `config/backup.php`
- Create: `app/Services/Backup/ApplicationTableCatalog.php`
- Create: `app/Services/Backup/ApplicationSchemaFingerprint.php`
- Create: `app/Services/Backup/BackupManifest.php`
- Create: `app/Services/Backup/DatabaseRowCodec.php`
- Create: `app/Services/Backup/BackupException.php`
- Create: `tests/Unit/Backup/ApplicationTableCatalogTest.php`
- Create: `tests/Feature/Backup/ApplicationSchemaFingerprintTest.php`
- Create: `tests/Unit/Backup/BackupManifestTest.php`
- Create: `tests/Unit/Backup/DatabaseRowCodecTest.php`

**Interfaces:**
- Catalog exposes the exact 19-table durable allowlist plus parent-first insert and child-first delete order.
- Catalog table metadata includes deterministic cursor/primary-key definition and database column-family metadata needed to restore strings safely.
- A drift test compares catalog against application-owned migrated tables and the explicit volatile/framework exclusion list; an unknown durable-looking application table fails review/tests rather than silently disappearing.
- Schema fingerprint is deterministic over table names, ordered columns/types/nullability and relevant key/relationship identity needed for exact-compat restore.
- `BackupManifest` owns `seo-backup/1`, required fields, canonical JSON encoding and compatibility validation.
- Row codec preserves database strings, null, JSON strings and binary via explicit base64 tagging without float coercion.

- [ ] RED catalog exact names/order; specifically assert `import_batches` exists and `keyword_import_batches` does not.
- [ ] RED schema fingerprint determinism and mismatch detection.
- [ ] RED row scalar/decimal/JSON/binary round-trips.
- [ ] RED manifest missing/unknown required field, duplicate logical table and incompatible version/fingerprint failures.
- [ ] Implement minimally, using DB schema introspection without reading arbitrary server tables into the backup allowlist.
- [ ] Verify focused GREEN + PHPStan.
- [ ] Commit `feat: define backup manifest and durable data contract`.

---

### Task 5: Deterministic Bundle Writer/Reader and System-state Capture

**Files:**
- Create: `app/Support/Backup/BackupBundleWriter.php`
- Create: `app/Support/Backup/BackupBundleReader.php`
- Create: `app/Services/Backup/CapturedSystemState.php`
- Create: `app/Services/Backup/CaptureSystemState.php`
- Create: `tests/Unit/Backup/BackupBundleWriterTest.php`
- Create: `tests/Unit/Backup/BackupBundleReaderTest.php`
- Create: `tests/Feature/Backup/CaptureSystemStateTest.php`

**Interfaces:**
- Writer emits exact `SEOBACKUP\n`, 32-bit big-endian manifest length and deterministic length-delimited entries.
- Reader verifies configured maximum bundle/manifest/path/entry sizes incrementally; it never preallocates an attacker-declared payload.
- Reader rejects duplicate paths, path traversal/control-byte paths, duplicate DB logical entries, unsupported schema and trailing bytes.
- Entry SHA-256 and manifest content digest are recomputed from bytes.
- Capture runs under supplied lifecycle ownership, uses repeatable-read on MySQL/MariaDB, bounded ordered DB batches, allowlisted runtime settings and allowlisted durable files; symlinks fail closed.
- Capture finishes DB transaction before final HTTP response use.
- Bundle contains APP_KEY but no database/mail/storage/external credentials.

- [ ] RED binary framing, exact deterministic bytes and digest.
- [ ] RED malformed lengths, limits, duplicate/traversal/trailing-byte rejection.
- [ ] RED capture durable-vs-volatile tables, exact decimal/JSON/Persian data, setting allowlist, file allowlist/symlink rejection.
- [ ] Implement writer/reader with streaming read/write primitives and bounded temp storage.
- [ ] Implement CaptureSystemState with cleanup on failure.
- [ ] Verify focused GREEN, backend suite and static analysis.
- [ ] Commit `feat: capture deterministic backup bundles`.

---

### Task 6: Owner-only Backup Download Coordinator

**Files:**
- Create: `app/Services/Backup/SystemBackupResult.php`
- Create: `app/Services/Backup/CreateSystemBackup.php`
- Create: `app/Http/Middleware/EnsureSystemOwner.php`
- Create: `app/Http/Controllers/Settings/SystemBackupController.php`
- Modify: `routes/settings.php`
- Create: `tests/Feature/Backup/SystemBackupDownloadTest.php`

**Interfaces:**
- Route `GET /settings/backup/download` named `settings.backup.download`.
- Middleware stack: existing auth/verified + recent password confirmation + system-owner enforcement.
- Coordinator acquires `LifecycleMutex`, enters maintenance capture window, captures the complete bundle to private rewindable storage, leaves maintenance, releases mutex, then returns a completed result for download.
- Response headers include private/no-store, pragma no-cache and nosniff; filename follows design and uses installation-id prefix + UTC timestamp.
- Temp artifact is deleted after response lifecycle; no file under public storage.
- Known failures expose only stable Persian operator messages/codes.

- [ ] RED authorization: system owner allowed; other Owner/Admin/roles/users denied; unverified/password-stale denied by middleware.
- [ ] RED response headers/filename/sensitive warning contract and cleanup.
- [ ] RED mutex/maintenance cleanup after capture exception.
- [ ] Implement minimal coordinator/middleware/controller/route.
- [ ] Verify focused GREEN + route list + backend suite.
- [ ] Commit `feat: add system owner backup download`.

---

### Task 7: Restore Upload Verification, Journal and Clean-target Gate

**Files:**
- Create: `app/Services/Backup/RestoreJournal.php`
- Create: `app/Services/Backup/RestorePreview.php`
- Create: `app/Services/Backup/CleanRestoreTarget.php`
- Create: `app/Services/Backup/VerifyRestoreBundle.php`
- Create: `app/Http/Controllers/Settings/SystemRestoreController.php`
- Modify: `routes/settings.php`
- Create: `tests/Unit/Backup/RestoreJournalTest.php`
- Create: `tests/Feature/Backup/VerifyRestoreBundleTest.php`
- Create: `tests/Feature/Backup/CleanRestoreTargetTest.php`

**Interfaces:**
- Private upload path: `storage/app/lifecycle/restore/uploads/<random>.seobak`; restrictive permissions.
- Journal path: `storage/app/lifecycle/restore/state.json`; atomic writes; no APP_KEY, credentials, password hashes or bundle payload.
- Verify endpoint persists only non-secret preview evidence: upload id, verified digest, source install id/time/version and counts.
- Verification checks full bytes/hashes/digest, backup schema, exact ProductVersion, exact schema fingerprint, table/column contract and configured bundle limits before mutation.
- Clean target permits only installer bootstrap user/workspace/Owner membership + permitted auth state and no domain/project data/unrecognized durable upload files.
- Second restore/non-clean target is rejected.

- [ ] RED journal state transitions/atomicity and secret-exclusion.
- [ ] RED wrong magic/checksum/version/schema/catalog/path/trailing bytes reject before any DB mutation.
- [ ] RED clean bootstrap accepted; domain data, unknown durable file or unresolved lifecycle state rejected.
- [ ] RED verify endpoint authorization/throttle/private-upload cleanup.
- [ ] Implement minimally.
- [ ] Verify focused GREEN + backend suite.
- [ ] Commit `feat: verify restore bundles before mutation`.

---

### Task 8: Destructive Restore, APP_KEY-safe Environment Update and Recovery

**Files:**
- Create: `app/Services/Backup/EnvironmentSettingsUpdater.php`
- Create: `app/Services/Backup/RestoreSystemBackup.php`
- Create: `app/Services/Backup/RecoverInterruptedRestore.php`
- Modify: `app/Support/InstallationState.php`
- Modify: `app/Http/Controllers/Settings/SystemRestoreController.php`
- Modify: `routes/settings.php`
- Create: `tests/Feature/Backup/RestoreSystemBackupTest.php`
- Create: `tests/Feature/Backup/RecoverInterruptedRestoreTest.php`

**Interfaces:**
- Confirm requires exact previously verified `content_digest_sha256` and reacquires/revalidates all mutable preconditions after `LifecycleMutex` acquisition.
- Before destructive mutation, create internal safety snapshot while already owning the lifecycle mutex.
- Durable tables delete child-first and insert parent-first inside a DB transaction with foreign keys enabled.
- Durable files stage outside live roots and switch only after validation-ready staging.
- Environment updater replaces only source APP_KEY + explicitly allowlisted locale/fallback/registration state while preserving destination DB/app/mail/storage/external values.
- Volatile session/cache/queue/password-reset state is cleared, never restored.
- Destination installation id/database fingerprint stay fixed; lock updates owner id, source provenance, restored_at and current local lifecycle metadata atomically.
- Post-DB-commit failure => `recovery_required`; no new lifecycle operation may start except recovery.
- Recovery restores destination clean bootstrap DB/files/key/settings/lock metadata from protected safety snapshot, clears volatile state and deletes recovery artifacts.
- Successful restore invalidates sessions and requires fresh login.

- [ ] RED exact restore round-trip on SQLite-compatible fast fixtures for durable rows/settings/files while preserving destination identity/secrets.
- [ ] RED second restore rejection and digest mismatch rejection.
- [ ] RED injected failure after DB commit produces `recovery_required`.
- [ ] RED recovery restores exact clean destination bootstrap state and removes temp/recovery artifacts.
- [ ] Implement destructive coordinator in explicit phases matching journal state machine.
- [ ] Verify focused GREEN + backend suite/static analysis.
- [ ] Commit `feat: restore and recover full system backups`.

---

### Task 9: Persian-first Backup & Restore Settings UI

**Files:**
- Create: `resources/js/pages/settings/backup-restore.tsx`
- Modify: `resources/js/layouts/settings/layout.tsx`
- Modify: `resources/js/lib/i18n/index.ts`
- Modify: `app/Http/Controllers/Settings/SystemBackupController.php`
- Create: `tests/Feature/Backup/BackupSettingsPageTest.php`
- Create/Modify focused frontend tests if the repository test harness has an established component-test location; otherwise pin props/routes with backend + TypeScript checks.

**UI contract:**
- Navigation label: `پشتیبان‌گیری و بازیابی`, visible only to system owner.
- Prominent warning that downloaded backup contains sensitive instance data and APP_KEY.
- Download action.
- Private restore upload + verified manifest preview.
- Explicit confirmation tied to exact verified digest.
- Server-derived phase/status; never fake percentage progress.
- Recovery-required state blocks ordinary actions and exposes recovery action/status.
- No APP_KEY/raw SQL/server path/credential rendered.

- [ ] RED page authorization/owner-only nav/props and recovery-state behavior.
- [ ] Implement page and settings nav using existing design-system components.
- [ ] Run `npm run check`, `npm run types:check`, `npm run build` and focused backend page test.
- [ ] Verify Persian RTL copy and error states without adding network/CDN dependency.
- [ ] Commit `feat: add Persian backup restore settings UI`.

---

### Task 10: Connected MariaDB Acceptance, Permanent CI Gate and Release Readiness

**Files:**
- Create: `tests/Feature/Acceptance/BackupRestoreMariaDbAcceptanceTest.php`
- Modify: `.github/workflows/ci.yml`
- Modify: release-tree acceptance assertions in CI as needed.
- Modify: `docs/PROJECT_STATE.md`
- Modify: `docs/HANDOFF.md`
- Modify: `docs/DELIVERY_PLAN.md` only where milestone state/next subsystem must change.
- Add operator-facing backup/recovery notes under `docs/` if no suitable existing operations document exists.

**Connected acceptance contract:**
- CI provisions source and destination databases separately on MariaDB 10.11.
- Both migrate on the same stable test ProductVersion/schema.
- Source contains real users/workspaces/projects, Persian + half-space keywords, imports/sources/metrics, saved views, change/activity, intents, review items, clusters/members, pages/targets and allowlisted upload bytes.
- Source also contains volatile sessions/cache/queue/password-reset rows that must not restore.
- Create backup through real coordinator; verify it against clean destination; restore it.
- Prove destination DB credentials, installation id and DB fingerprint preserved while source APP_KEY/durable encrypted state works.
- Prove owner/auth hashes/memberships/project isolation/Persian data/intelligence state/files match source.
- Reject second restore.
- Reset fresh destination, inject post-DB-commit interruption, assert `recovery_required`, recover, and prove original clean bootstrap DB/files/key/metadata restored exactly.
- No lifecycle temp/recovery artifact remains after success/recovery.

- [ ] Write acceptance RED first and add CI job `backup-restore-mariadb` with `--fail-on-skipped`.
- [ ] Observe RED on feature-branch CI for missing/incomplete implementation or gate wiring.
- [ ] Complete only defects exposed by acceptance, each with a focused regression test where practical.
- [ ] Verify permanent CI on PHP 8.4/8.5 + MariaDB installer + Persian Export + new Backup/Restore MariaDB gate.
- [ ] Verify production release tree contains `VERSION`, lifecycle/backup services, controllers/routes and built settings UI after runtime-only Composer install.
- [ ] Update `PROJECT_STATE.md` with exact branch SHA and CI evidence; set next subsystem to **Update + Interrupted Recovery/Rollback** only after all feature gates are green.
- [ ] Whole-branch review: no open Critical/Important finding.
- [ ] Commit `test: add backup restore release acceptance` and `docs: checkpoint verified backup restore` as appropriate.

---

## Merge Boundary

Do **not** merge merely because fast tests are green. Backup + Restore is merge-ready only when:

1. written-spec review and this implementation plan are committed;
2. every product task has observed RED then correct GREEN evidence;
3. full feature-head CI is green on PHP 8.4 and 8.5;
4. MariaDB 10.11 connected backup/restore/recovery acceptance is green;
5. runtime release-tree smoke/contract is green with stable non-dev VERSION;
6. whole-branch review has no open Critical/Important finding;
7. exact-head PR CI is green;
8. merge uses expected-head protection/guard;
9. post-merge `main` CI is green;
10. `docs/PROJECT_STATE.md` records the exact verified merged state.

Only then proceed to **Update + Interrupted Recovery/Rollback**, reusing ProductVersion, LifecycleMutex, capture/bundle, safety snapshot and recovery primitives rather than creating a parallel lifecycle system.