# Update + Interrupted Recovery/Rollback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a browser-driven, offline-capable self-host update lifecycle that stages immutable release slots, atomically switches releases, applies reversible migrations, validates health, and deterministically rolls back/recoveries after interruption without runtime Composer/Node/npm/SSH/shell dependencies.

**Architecture:** Keep stable dependency-free root launchers and persistent installation state outside versioned code. Upload/verify `seo-update/1`, quiesce the instance, capture a pre-update `seo-backup/1` safety snapshot, stage a private immutable target release, switch a single atomic active-release pointer, then continue on a new request booted entirely from the target release. Migration/health failure rolls back the exact verified target migration suffix, switches back to the exact source release, and applies the source-version safety snapshot through the shared Backup/Restore recovery kernel.

**Tech Stack:** PHP 8.4.1+, Laravel 13, Inertia 3, React 19, TypeScript, MariaDB 10.11, MySQL 8+, PHPUnit 12.5, Laravel Migrator, application-owned streaming filesystem primitives.

**Spec:** `docs/superpowers/specs/2026-10-23-update-recovery-rollback-design.md`

**Written review:** `docs/superpowers/specs/2026-10-23-update-recovery-rollback-review.md`

## Global Constraints

- Current verified base at planning time is `main@ea49d3158a0df76cb1e944d609397596278a4572`; its permanent CI run `39979433875` is green across all six existing jobs.
- Runtime floor is PHP `>= 8.4.1`; permanent compatibility gates remain PHP 8.4 and 8.5.
- Production update runtime must not require Composer, Node/npm, SSH, shell commands, symlink creation, ZIP/Phar, native archivers, remote APIs or international network access.
- V1 update package schema is `seo-update/1`; magic is exactly `SEOUPDATE\n`; stable launcher schema is exactly `seo-launcher/1`.
- V1 manual package upload is trusted-code installation. SHA-256 proves integrity, not publisher authenticity. Release signing remains **NEEDS VERIFICATION before public/commercial automatic distribution**.
- V1 package declares exactly one allowed source ProductVersion plus its exact source schema fingerprint. Broad ranges and downgrade packages are rejected.
- Only the persisted installer-created system owner may update/recover; requests require authenticated + verified + recent-password confirmation.
- Absence of active-release pointer means the stable root release is active. A present-but-invalid pointer fails closed; it never silently falls back to root.
- Source and target Laravel application class graphs never run in the same PHP request. Pointer switch ends the current request; continuation boots the selected release from process start.
- Stable `.env`, APP_KEY, DB credentials, installation id/database fingerprint, durable storage and lifecycle state remain outside versioned release slots.
- Target release slots are private, immutable after verification and never web-addressable.
- Target content-addressed public assets are copied additively before activation; source hashes remain until rollback retention is no longer needed.
- `LifecycleMutex` serializes Backup, Restore and Update/Rollback. No parallel lifecycle stack is allowed.
- **Review correction to design Phase B ordering:** activate persistent update maintenance, request worker restart and prove active jobs drained **before** capturing the pre-update safety snapshot. The safety snapshot must represent the quiesced source state.
- A safety snapshot is applied only after code pointer and schema are restored to the exact source release/version.
- Automatic migration rollback uses an exact verified suffix beyond recorded pre-update migration history. Never use “latest batch” or a broad guessed rollback.
- Ambiguous partial/unlogged MariaDB DDL, migration-history drift, corrupt/missing safety snapshot or unprovable source schema becomes `recovery_required`; do not guess through it.
- Existing Backup/Restore acceptance remains a permanent regression gate after extracting a shared system-state recovery kernel.
- Existing Installer/Persian Export/Backup Restore MariaDB jobs remain permanent. A new Update/Rollback MariaDB 10.11 job with `--fail-on-skipped` becomes an additional merge gate.
- Runtime release-tree acceptance must cover both direct-root boot and release-slot boot/recovery.
- `AGENTS.md` runtime floor was corrected to PHP 8.4.1+ on this feature branch; do not revert it to historical 8.3 prose.

## Review Focus

1. **Corrupt or attacker-controlled active pointer:** launcher must fail closed; test corrupt JSON, traversal-style id, symlink slot, missing slot, mismatched slot marker and absent pointer separately in Task 1.
2. **Target bootstrap failure around migration boundary:** auto-revert is allowed only before migration mutation; test pre-migration target bootstrap recovery and post-migration fail-closed behavior in Tasks 7 and 10.
3. **Unexpected migration repository/schema drift:** never broad rollback; test exact suffix, missing log entry/partial schema drift and extra concurrent migration in Tasks 8 and 10.
4. **Background mutation after safety capture:** maintenance + queue drain must precede snapshot; test a reserved database queue job blocks snapshot/switch and that snapshot happens only after drain in Task 6.
5. **Source/target asset mismatch:** target asset must exist before pointer activation and source asset must survive rollback; test both in Tasks 6, 10 and connected acceptance.

---

### Task 1: Stable Runtime Launcher, Active Release Resolution and Stable Root Binding

**Files:**
- Create: `bootstrap/runtime-release.php`
- Modify: `public/index.php`
- Modify: `artisan`
- Modify: `bootstrap/app.php`
- Modify: `app/Support/ReleasePackageContract.php`
- Create: `tests/Unit/System/RuntimeReleaseLocatorTest.php`
- Create: `tests/Feature/System/ReleaseSlotBootstrapTest.php`
- Modify: `tests/Unit/System/ReleasePackageContractTest.php`

**Interfaces:**
- `bootstrap/runtime-release.php` is dependency-free PHP and defines global final class `SeoOsRuntimeReleaseLocator`.
- `SeoOsRuntimeReleaseLocator::LAUNCHER_SCHEMA` is exactly `seo-launcher/1`.
- `SeoOsRuntimeReleaseLocator::__construct(string $installationRoot)` stores a normalized stable root and does not load Composer/Laravel.
- `SeoOsRuntimeReleaseLocator::resolve(): array{kind:'root'|'slot', release_root:string, release_id:?string, product_version:string, package_digest_sha256:?string}`.
- Pointer default: `<installation-root>/storage/app/lifecycle/update/active-release.json`.
- Private release root default: `<installation-root>/storage/app/lifecycle/update/releases/`.
- Slot marker: `<release-root>/release.json`, schema `seo-release-slot/1`, containing exact generated `release_id`, `product_version`, `package_digest_sha256`, `launcher_schema`.
- No pointer -> root release using `<installation-root>/VERSION`.
- Present invalid pointer -> controlled `RuntimeException`; never root fallback.
- `public/index.php` and `artisan` define `SEO_OS_INSTALLATION_ROOT` before requiring the selected release autoloader/bootstrap.
- `bootstrap/app.php` uses its own release directory as Laravel `basePath`; when `SEO_OS_INSTALLATION_ROOT` is defined, call Laravel environment/storage path APIs so `.env` and `storage` resolve from the stable installation root.
- `ReleasePackageContract` requires `bootstrap/runtime-release.php` and treats stable launcher schema files as release contract inputs.

- [ ] **Step 1: Write locator RED tests** for root/no-pointer resolution, valid slot+marker resolution, corrupt JSON, unsupported schema, invalid release id, traversal/control bytes, symlinked release slot, missing slot, marker digest/version mismatch and invalid pointer permissions/path shape.
- [ ] **Step 2: Write bootstrap RED tests** proving a release-slot app receives slot `base_path()` while `storage_path()` and environment path point at the stable installation root; direct-root boot remains unchanged.
- [ ] **Step 3: Write release-contract RED assertion** that a runtime release missing `bootstrap/runtime-release.php` is invalid.
- [ ] **Step 4: Observe RED.** Run `php artisan test tests/Unit/System/RuntimeReleaseLocatorTest.php tests/Feature/System/ReleaseSlotBootstrapTest.php tests/Unit/System/ReleasePackageContractTest.php`. In a harness without local dependencies, push the RED test commit to the `feature/**` branch and use exact-head GitHub Actions as the observed runner.
- [ ] **Step 5: Implement the dependency-free locator + launcher/bootstrap changes minimally.** Do not add update journal or auto-revert yet.
- [ ] **Step 6: Verify focused GREEN** with the same tests, then `php artisan test tests/Unit/System/ReleasePackageBuilderTest.php tests/Unit/System/ReleasePackageContractTest.php` and `composer types:check`.
- [ ] **Step 7: Verify feature-head permanent CI is green** before continuing because launcher changes affect every runtime request/CLI command.
- [ ] **Step 8: Commit** `feat: add stable release slot launcher`.

---

### Task 2: `seo-update/1` Manifest, Streaming Package Writer/Reader and Release Path Contract

**Files:**
- Create: `config/update.php`
- Create: `app/Services/Update/UpdateException.php`
- Create: `app/Services/Update/UpdateManifest.php`
- Create: `app/Support/Update/UpdatePackageFormat.php`
- Create: `app/Support/Update/UpdatePackageWriter.php`
- Create: `app/Support/Update/UpdatePackageReader.php`
- Create: `tests/Unit/Update/UpdateManifestTest.php`
- Create: `tests/Unit/Update/UpdatePackageWriterTest.php`
- Create: `tests/Unit/Update/UpdatePackageReaderTest.php`

**Interfaces:**
- Package magic `SEOUPDATE\n`; schema `seo-update/1`.
- `UpdateManifest::fromArray(array $data): self`, `toArray(): array`, `canonicalJson(): string`.
- Required manifest fields: `schema`, `created_at_utc`, `target_product_version`, `allowed_source_product_versions`, `channel`, `launcher_schema`, `minimum_php_version`, `required_php_extensions`, `source_schema_fingerprint`, `target_schema_fingerprint`, `migration_tree_sha256`, `entries`, `content_digest_sha256`.
- V1 requires `allowed_source_product_versions` to contain exactly one stable non-`dev` version.
- `channel` is one of `stable|beta|development` and never relaxes compatibility.
- `UpdatePackageWriter::write($output, array $manifestFields, array $entries): UpdateManifest` follows the proven Backup writer contract: seekable output, bounded streams, canonical entry metadata + payload content digest.
- `UpdatePackageReader::read($stream, ?callable $onChunk = null): UpdateManifest` verifies all lengths, paths, duplicate entries, hashes, digest and trailing data incrementally without attacker-sized preallocation.
- `UpdatePackageFormat::assertPackagePath(string $path): void` accepts only runtime-release paths from the release contract families: root runtime files; `app/**`, `bootstrap/**`, `config/**`, `database/migrations/**`, `resources/views/**`, `routes/**`, `vendor/**`, `public/build/**`, `public/fonts/open/**`; rejects `.env`, storage payload, tests, source frontend toolchain, `.github`, absolute/backslash/traversal/control paths and symlinks at staging time.
- Default configured limits remain bounded independently from Backup limits.

- [ ] **Step 1: Write manifest RED tests** for exact schema, single source version, stable non-dev versions, channel enum, launcher schema, 64-hex schema/migration/digest fields, ordered unique entries and unknown/missing required field rejection.
- [ ] **Step 2: Write writer/reader RED tests** for deterministic exact bytes, Persian metadata, duplicate/traversal/control/backslash paths, malformed uint lengths, entry/manifest/package limits, checksum mismatch and trailing bytes.
- [ ] **Step 3: Write path-contract RED tests** proving `.env`, `storage/**`, `tests/**`, `resources/js/**`, arbitrary top-level files and unsafe paths are rejected while the runtime release families are accepted.
- [ ] **Step 4: Observe RED** with the three focused test files.
- [ ] **Step 5: Implement format/manifest/writer/reader minimally.** Keep Backup bundle classes unchanged.
- [ ] **Step 6: Verify focused GREEN**, then `composer types:check` and the existing Backup bundle/manifest tests to prove no framing regression.
- [ ] **Step 7: Commit** `feat: define versioned update package contract`.

---

### Task 3: Durable Update Journal, Atomic Pointer Writer and Persistent Maintenance Gate

**Files:**
- Create: `app/Services/Update/UpdateJournal.php`
- Create: `app/Support/Update/ActiveReleasePointer.php`
- Modify: `bootstrap/runtime-release.php`
- Modify: `public/index.php`
- Modify: `artisan`
- Create: `tests/Unit/Update/UpdateJournalTest.php`
- Create: `tests/Unit/Update/ActiveReleasePointerTest.php`
- Create: `tests/Feature/Update/UpdateMaintenanceGateTest.php`

**Interfaces:**
- Journal default: `storage/app/lifecycle/update/state.json`; atomic temp+rename, directory 0700/file 0600, secret-exclusion equivalent to or stricter than `RestoreJournal`.
- `UpdateJournal::state(): ?array`.
- `UpdateJournal::startUpload(string $uploadId): array`.
- `UpdateJournal::transition(string $expectedPhase, string $nextPhase, array $evidence = []): array` with explicit transition graph from design/review.
- `UpdateJournal::clear(): void`.
- `UpdateJournal::isMaintenancePhase(?string $phase): bool` and `maintenancePhases(): array` expose the app-side canonical persistent-maintenance set.
- `ActiveReleasePointer::__construct(?string $installationRoot = null, ?string $pointerPath = null, ?string $releaseRoot = null)` allows isolated tests while production defaults match launcher ABI paths.
- `ActiveReleasePointer::activateSlot(string $releaseId, string $version, string $packageDigest): void` atomically writes `seo-release-pointer/1` after validating the slot marker.
- `ActiveReleasePointer::activateRoot(string $version): void` atomically returns to explicit root-source state by removing the pointer only after validating root VERSION; no directory scanning.
- `ActiveReleasePointer::current(): array` mirrors `SeoOsRuntimeReleaseLocator::resolve()` semantics.
- Stable launcher reads journal without Composer. During maintenance phases `public/index.php` returns dependency-free 503 for ordinary paths, but allows exact update/auth continuation paths needed for owner recovery (`/settings/update`, `/settings/update/*`, `/login`, `/logout`, `/confirm-password` and framework-required CSRF/auth paths pinned by feature tests).
- Stable `artisan` exits with a sanitized nonzero maintenance message for ordinary commands during destructive update phases. No shell/worker-kill behavior.
- Tests assert launcher maintenance-phase set equals `UpdateJournal::maintenancePhases()` to prevent ABI drift.

- [ ] **Step 1: Write journal RED tests** for all valid/invalid transitions, atomicity, restrictive permissions, restart persistence and secret/object/resource rejection.
- [ ] **Step 2: Write pointer RED tests** for slot activation, root activation, stale/mismatched slot marker rejection, atomic replacement and compatibility with dependency-free locator output.
- [ ] **Step 3: Write persistent maintenance RED tests** proving ordinary HTTP is 503, update/auth recovery paths reach Laravel, ordinary CLI is blocked, and no journal means normal behavior.
- [ ] **Step 4: Observe RED** with the three focused test files.
- [ ] **Step 5: Implement journal/pointer/launcher gate minimally.** Do not add update coordinators yet.
- [ ] **Step 6: Verify focused GREEN** plus Task 1 launcher/bootstrap tests and release smoke tests.
- [ ] **Step 7: Commit** `feat: add durable update lifecycle state`.

---

### Task 4: Extract Shared Verified System-State Snapshot Recovery Kernel

**Files:**
- Create: `app/Services/Backup/ApplySystemStateSnapshot.php`
- Modify: `app/Services/Backup/RecoverInterruptedRestore.php`
- Modify only if necessary: `app/Services/Backup/RestoreSystemBackup.php`
- Create: `tests/Feature/Backup/ApplySystemStateSnapshotTest.php`
- Modify: `tests/Feature/Backup/RecoverInterruptedRestoreTest.php`

**Interfaces:**
- `ApplySystemStateSnapshot::handle(string $bundlePath, string $installationMetadataPath, string $stagingRoot, LifecycleMutexHandle $ownership): void`.
- Caller must already own active lifecycle lock; service never recursively acquires `LifecycleMutex` or enters maintenance itself.
- It verifies/stages the safety bundle with the current release `ProductVersion` + current schema fingerprint, restores durable DB rows/files/runtime settings, restores exact installation metadata snapshot and clears volatile tables.
- It does not own journal transitions or final artifact retention/cleanup; Restore recovery and future Update recovery remain responsible for their own state machine/artifacts.
- Existing `RecoverInterruptedRestore` behavior, Persian errors and cleanup semantics remain unchanged from the caller perspective.

- [ ] **Step 1: Write RED kernel test** from existing recovery fixtures proving exact DB/files/APP_KEY/settings/install-metadata restoration under an already-owned lifecycle handle, including rejection under mismatched ProductVersion/schema.
- [ ] **Step 2: Add/retain regression assertions** that interrupted Restore recovery still clears its journal/artifacts and restores exact bootstrap state.
- [ ] **Step 3: Observe RED** for missing shared kernel before modifying recovery production behavior.
- [ ] **Step 4: Extract the minimal destructive apply logic** from `RecoverInterruptedRestore` into `ApplySystemStateSnapshot`; do not redesign Backup bundle/catalog semantics.
- [ ] **Step 5: Verify focused GREEN** with `ApplySystemStateSnapshotTest`, `RecoverInterruptedRestoreTest`, `RestoreSystemBackupTest`, then full Backup feature tests/static analysis.
- [ ] **Step 6: Require permanent `backup-restore-mariadb` CI GREEN on the extraction head** before Update depends on the kernel.
- [ ] **Step 7: Commit** `refactor: share lifecycle snapshot recovery kernel`.

---

### Task 5: Owner-only Update Upload Verification and Safe Preview

**Files:**
- Create: `app/Services/Update/UpdatePreview.php`
- Create: `app/Services/Update/VerifyUpdatePackage.php`
- Create: `app/Http/Middleware/EnsureNoConflictingLifecycleRecovery.php`
- Create: `app/Http/Controllers/Settings/SystemUpdateController.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/settings.php`
- Create: `tests/Feature/Update/VerifyUpdatePackageTest.php`
- Create: `tests/Feature/Update/UpdateAuthorizationTest.php`

**Interfaces:**
- Private upload default: `storage/app/lifecycle/update/uploads/<32-hex>.seoupdate`, permissions 0600.
- `UpdatePreview` exposes only: upload id, package digest, source version, target version, channel, minimum PHP, required extensions, migration count/tree digest and non-secret compatibility summary.
- `VerifyUpdatePackage::handle(UploadedFile $upload): UpdatePreview` fully streams/verifies package bytes and manifest, exact current ProductVersion, current application schema fingerprint, launcher schema, target `version_compare(...) > 0`, PHP floor/extensions and package limits **without activating/staging a release slot**.
- It rejects any active Restore `recovery_required` or active Update state other than a clean new upload; conflicting lifecycle state is fail-closed.
- On any verify failure, private upload/journal partial state is cleaned where safe; no public artifact.
- Controller page/verify routes use auth+verified+system-owner; upload/confirm/recovery routes also use `RequirePassword` and named throttle `system-update` (3/min per authenticated owner identity).
- Known errors return stable Persian operator code/message and private/no-store/nosniff headers.

- [ ] **Step 1: Write authorization RED tests** for system owner allowed and other Owner/Admin/roles/users, unverified and password-stale denied.
- [ ] **Step 2: Write verification RED tests** for valid preview and invalid magic/checksum/version downgrade/same version/source mismatch/schema mismatch/launcher mismatch/PHP/extension/path/trailing bytes/oversize/conflicting restore recovery.
- [ ] **Step 3: Assert no secret/internal leakage** in preview/JSON and private upload cleanup on failure.
- [ ] **Step 4: Observe RED.** Expected: routes/services absent.
- [ ] **Step 5: Implement verifier/controller/throttle/routes minimally.** Leave destructive confirm/continue methods unavailable until their tasks.
- [ ] **Step 6: Verify focused GREEN**, route list, static analysis and existing Backup settings/authorization tests.
- [ ] **Step 7: Commit** `feat: verify trusted update packages before mutation`.

---

### Task 6: Quiescence, Pre-update Safety Snapshot, Immutable Release Staging and Public Asset Preparation

**Files:**
- Create: `app/Services/Update/UpdateQuiescence.php`
- Create: `app/Services/Update/UpdateReleaseStager.php`
- Create: `app/Services/Update/PreparedSystemUpdate.php`
- Create: `app/Services/Update/PrepareSystemUpdate.php`
- Create: `tests/Feature/Update/UpdateQuiescenceTest.php`
- Create: `tests/Feature/Update/PrepareSystemUpdateTest.php`

**Interfaces:**
- `UpdateQuiescence::requestWorkerRestart(): void` writes the Laravel queue-restart timestamp through the configured cache repository; no shell/Artisan subprocess.
- `UpdateQuiescence::assertReady(): void` supports `database` queue by rejecting any `jobs.reserved_at IS NOT NULL`; `sync` is trivially quiescent for tests; unsupported production queue drivers fail closed with `update_quiescence_required`/runtime-compatible code rather than guessing.
- `UpdateReleaseStager::stage(string $verifiedUploadPath, UpdateManifest $manifest): array{release_id:string, release_root:string}` streams verified entries into a new private slot, rejects symlinks/existing mismatched slots, creates required empty runtime dirs such as `bootstrap/cache`, validates `ReleasePackageContract`, writes immutable `seo-release-slot/1` marker and never mutates an active slot.
- Release id is generated from a sanitized target-version component plus digest prefix; original upload filename never becomes a filesystem path.
- `UpdateReleaseStager::preparePublicAssets(string $releaseRoot, UpdateManifest $manifest): void` copies only verified `public/build/assets/**` content-addressed files additively into stable `public/build/assets`; existing same-path bytes must hash-match or fail; it never deletes source assets.
- `PrepareSystemUpdate::handle(string $expectedPackageDigest): PreparedSystemUpdate`:
  1. validates `verified` journal/digest;
  2. acquires `LifecycleMutex('update')`;
  3. recomputes upload SHA-256 and mutable source version/schema/runtime preconditions;
  4. transitions persistent update maintenance/quiescing state;
  5. requests worker restart and proves reserved jobs drained;
  6. **only after quiescence**, captures `CaptureSystemState` under the already-owned handle and writes protected safety bundle + exact installation metadata snapshot;
  7. stages/validates target release and public assets;
  8. records source release kind/id/version/schema, target slot/version/schema, safety relative ids, pre-update migration history hash/list and package digest;
  9. returns prepared state without changing active-release pointer.
- If quiescence/snapshot/staging fails before pointer switch, source remains active; maintenance/journal/artifact cleanup behavior is explicit and retry-safe.

- [ ] **Step 1: Write quiescence RED tests** for database reserved job blocks, queued-unreserved job allowed, restart signal written, sync test driver accepted and unsupported driver rejected.
- [ ] **Step 2: Write preparation RED tests** proving TOCTOU digest recheck after mutex, source version/schema recheck, maintenance precedes quiescence, reserved job prevents snapshot, snapshot occurs after drain, snapshot contains exact source data, slot is private/immutable, release contract enforced and public assets copied before activation.
- [ ] **Step 3: Add root-source and existing-slot-source fixtures** so preparation records source kind deterministically without directory scanning.
- [ ] **Step 4: Observe RED** on the two focused files.
- [ ] **Step 5: Implement quiescence/stager/preparation minimally.** Reuse `CaptureSystemState`; do not duplicate Backup capture logic.
- [ ] **Step 6: Verify focused GREEN** plus Backup capture/mutex tests and release package contract tests.
- [ ] **Step 7: Commit** `feat: prepare quiesced safe system updates`.

---

### Task 7: Atomic Source-to-Target Switch and Pre-migration Bootstrap Failure Recovery

**Files:**
- Create: `app/Services/Update/SwitchSystemUpdateRelease.php`
- Modify: `bootstrap/runtime-release.php`
- Modify: `public/index.php`
- Modify: `app/Http/Controllers/Settings/SystemUpdateController.php`
- Modify: `routes/settings.php`
- Create: `tests/Feature/Update/SwitchSystemUpdateReleaseTest.php`
- Create: `tests/Feature/Update/TargetBootstrapFailureTest.php`

**Interfaces:**
- `SwitchSystemUpdateRelease::handle(string $expectedDigest): array{status:'target_active', target_version:string}` requires prepared/staged state, reacquires `LifecycleMutex('update')`, re-proves quiescence/source pointer/package evidence, transitions `switch_pending`, atomically activates exact target pointer and transitions `target_active` with `migration_started=false`.
- Controller confirm/switch response is produced by source release **after pointer write but before any target class/migration use**; browser continuation is a new request.
- Dependency-free launcher registers `Throwable` handling + shutdown protection around selected release boot.
- `SeoOsRuntimeReleaseLocator::recoverPreMigrationBootstrapFailure(...)` (exact pure-PHP signature chosen in implementation but pinned by tests) may revert only when journal has exact source evidence, target pointer matches journal and `migration_started` is false / phase is pre-migration target bootstrap.
- Root source rollback explicitly removes pointer through an atomic/root-source contract; slot source rollback writes exact prior slot pointer.
- If journal says migration may have started, launcher never auto-reverts pointer/schema; it serves fail-closed recovery maintenance state.
- Sanitized fallback response contains no raw exception/paths/secrets.

- [ ] **Step 1: Write switch RED tests** for prepared source -> exact target pointer, stale digest/quiescence/pointer rejection and no target coordinator call in same request.
- [ ] **Step 2: Write runtime RED tests** using a deliberately broken target bootstrap/autoload fixture: pre-migration failure returns source pointer/root deterministically and records sanitized recovery state.
- [ ] **Step 3: Write fail-closed RED test** where the same broken target occurs after journal indicates migration started; pointer must not silently return to source.
- [ ] **Step 4: Observe RED.** Use actual PHP include/subprocess/runtime fixture where necessary; implementation-mirroring mocks are insufficient for bootstrap failure.
- [ ] **Step 5: Implement switch + pure-PHP pre-migration recovery minimally.** Do not implement target migrations yet.
- [ ] **Step 6: Verify focused GREEN** plus Task 1/3 launcher tests and production release-tree smoke.
- [ ] **Step 7: Commit** `feat: atomically switch update release slots`.

---

### Task 8: Exact-history Target Migration Runner and Verified Reverse Suffix

**Files:**
- Create: `app/Services/Update/UpdateMigrationState.php`
- Create: `app/Services/Update/UpdateMigrationRunner.php`
- Create: `tests/Feature/Update/UpdateMigrationRunnerTest.php`
- Add test-only update migration fixtures under an existing test fixture tree, not production migrations.

**Interfaces:**
- `UpdateMigrationRunner` uses Laravel `Migrator` in-process under the target release's migration path/connection; no shell command.
- `snapshot(): UpdateMigrationState` captures exact ordered `ran` list + deterministic hash before mutation.
- `runPending(UpdateMigrationState $before, callable $afterEachCompleted): UpdateMigrationState` runs target pending migrations deterministically, with evidence persisted after each migration completion where framework hooks permit; journal also re-derives current repository state after any thrown exception.
- `rollbackExactSuffix(UpdateMigrationState $before, array $expectedAppliedSuffix): void` proceeds only if current migration repository is exactly `before.ran + expectedAppliedSuffix`; rolls those migrations down in exact reverse order using target migration definitions; verifies repository equals `before.ran` afterward.
- No use of “latest batch” as proof.
- Source/target application schema fingerprints are checked by coordinator around runner, not guessed by migration batches.
- An unlogged partial-DDL fixture/state that makes schema/history disagree is surfaced as `update_recovery_required`; runner does not call an unrelated `down()`.

- [ ] **Step 1: Write RED forward tests** for two reversible fixture migrations, exact ordered suffix and after-each completion evidence.
- [ ] **Step 2: Write RED rollback tests** for exact suffix success and refusal on extra/missing/reordered repository entries.
- [ ] **Step 3: Write ambiguous-DDL RED test** simulating schema drift not represented by migration repository; assert controlled recovery-required signal and no broad rollback.
- [ ] **Step 4: Observe RED.** Prefer MariaDB for DDL semantics when the fixture requires it; fast SQLite-compatible tests may cover pure repository logic.
- [ ] **Step 5: Implement runner minimally** around Laravel Migrator/repository APIs.
- [ ] **Step 6: Verify focused GREEN**, existing InstallerMigrationRunner tests and static analysis.
- [ ] **Step 7: Commit** `feat: track exact update migration history`.

---

### Task 9: Target Health Validation and Successful Update Finalization

**Files:**
- Create: `app/Services/Update/UpdateHealthReport.php`
- Create: `app/Services/Update/ValidateSystemUpdateHealth.php`
- Create: `app/Services/Update/ContinueSystemUpdate.php`
- Modify: `app/Support/InstallationState.php`
- Modify: `app/Http/Controllers/Settings/SystemUpdateController.php`
- Modify: `routes/settings.php`
- Create: `tests/Feature/Update/ValidateSystemUpdateHealthTest.php`
- Create: `tests/Feature/Update/ContinueSystemUpdateTest.php`
- Modify: `tests/Feature/System/BackupInstallationStateTest.php` or create focused update metadata test.

**Interfaces:**
- `ValidateSystemUpdateHealth::handle(expected target evidence): UpdateHealthReport` checks only deterministic local health: target ProductVersion; active pointer/slot marker; DB connectivity; exact target application schema fingerprint; unchanged installation id/database fingerprint/owner; APP_KEY encryption/decryption round-trip without rotation; stable storage/runtime writability; target Vite manifest/assets; durable table catalog consistency; no conflicting Restore/Update recovery state.
- Queue/scheduler freshness remains warning unless a demonstrable local failure makes target unsafe; no external network health dependency.
- `ContinueSystemUpdate::handle(): array{status:'complete', product_version:string}` runs only when current request is target release and journal says `target_active|migrating|validating` with exact target pointer.
- Before first migration attempt it marks `migration_started=true`, snapshots/verifies pre-update migration history from journal, runs `UpdateMigrationRunner`, records exact applied suffix, verifies target schema, runs health validator, atomically updates `.installed.product_version` (and only reviewed optional non-secret update provenance), marks complete and clears persistent maintenance/update transient artifacts according to retention policy.
- `installation_id`, `database_fingerprint`, owner, APP_KEY, DB/mail/storage/external secrets and restore provenance remain unchanged.
- Successful update normally preserves sessions because APP_KEY does not change; controller response can require page reload but not forced logout unless a future package contract explicitly changes auth compatibility.

- [ ] **Step 1: Write health RED tests** for each required local invariant and sanitized failure report.
- [ ] **Step 2: Write continuation RED success test** from target-active state through two fixture migrations, target schema fingerprint, health, metadata version change, maintenance clear and durable domain data preservation.
- [ ] **Step 3: Write identity/secret preservation RED tests** around installation metadata and `.env`/config values.
- [ ] **Step 4: Observe RED.** Expected missing target coordinator/health validator.
- [ ] **Step 5: Implement health/finalization minimally.** On migration/health failure transition to rollback-required; do not implement rollback body until Task 10.
- [ ] **Step 6: Verify focused GREEN** plus installation-state, Backup/Restore and release contract tests.
- [ ] **Step 7: Commit** `feat: finalize healthy target updates`.

---

### Task 10: Interrupted Update Rollback and Source-version Snapshot Recovery

**Files:**
- Create: `app/Services/Update/RecoverInterruptedUpdate.php`
- Modify: `bootstrap/runtime-release.php` only if state-routing support is required.
- Modify: `app/Http/Controllers/Settings/SystemUpdateController.php`
- Modify: `routes/settings.php`
- Create: `tests/Feature/Update/RecoverInterruptedUpdateTest.php`
- Create/Modify focused Backup shared-kernel regression tests as needed.

**Interfaces:**
- `RecoverInterruptedUpdate::handle(): array{status:'rolled_back'|'recovery_required', product_version:string}` resumes strictly from durable journal phase/current active release evidence.
- While target release is active and `rollback_required|migration_rollback`:
  1. validate target pointer/journal;
  2. acquire lifecycle mutex;
  3. verify current migration repository equals recorded pre-state + exact applied suffix;
  4. rollback exact suffix with Task 8 runner;
  5. verify source schema fingerprint;
  6. transition `source_switch_pending`;
  7. atomically activate exact source slot/root;
  8. transition `snapshot_restore_required` and end target request.
- A later source-booted request in `snapshot_restore_required`:
  1. validates exact source pointer/root and source ProductVersion/schema;
  2. acquires lifecycle mutex;
  3. calls `ApplySystemStateSnapshot` with protected pre-update snapshot + installation metadata;
  4. verifies source schema/ProductVersion/install identity/data/files;
  5. clears volatile state via shared kernel behavior, cleans target transient upload/staging/snapshot only after proven rollback, clears maintenance/journal and leaves source active.
- Hard interruption is idempotent/retry-safe at every journal phase.
- If migration history/schema/snapshot/source release evidence is missing or ambiguous, mark/retain `recovery_required`, keep maintenance active and retain both release slots/snapshot; no automatic destructive fallback.

- [ ] **Step 1: Write RED root-source rollback test**: root -> target slot -> target migration/health failure -> exact down -> root pointer -> source snapshot -> source health/data/assets.
- [ ] **Step 2: Write RED slot-source rollback test**: slot A -> slot B -> slot A with exact source release id.
- [ ] **Step 3: Write hard-interruption RED matrix** at: after one completed migration, after all migrations before health, after source pointer switch before snapshot, during source snapshot staging/retry.
- [ ] **Step 4: Write fail-closed RED tests** for extra migration history, unlogged schema drift, corrupt/missing snapshot, missing source slot and invalid pointer; assert artifacts retained and no broad rollback.
- [ ] **Step 5: Write asset RED assertion** that target asset exists on target and source asset still serves after rollback.
- [ ] **Step 6: Observe RED** on focused recovery suite.
- [ ] **Step 7: Implement recovery coordinator as explicit phase handlers**; each handler verifies current release/journal before mutation.
- [ ] **Step 8: Verify focused GREEN** plus full Backup/Restore recovery suite and static analysis.
- [ ] **Step 9: Commit** `feat: recover and roll back interrupted updates`.

---

### Task 11: Persian-first Update/Rollback Settings UI

**Files:**
- Create: `resources/js/pages/settings/update-recovery.tsx`
- Modify: `resources/js/layouts/settings/layout.tsx`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `app/Http/Controllers/Settings/SystemUpdateController.php`
- Modify: `routes/settings.php`
- Create: `tests/Feature/Update/UpdateSettingsPageTest.php`
- Add focused frontend component tests only if the repository has an established harness; otherwise pin props/routes with backend + TypeScript/build.

**UI contract:**
- Navigation label `به‌روزرسانی و بازیابی نسخه`, visible only to system owner.
- Current ProductVersion + package channel.
- Trusted-package warning; signature/authenticity wording must not imply vendor verification.
- Upload `.seoupdate`, verified preview, target version/channel/runtime requirement/migration summary and digest.
- Beta/development channel shows stronger explicit warning; compatibility gates remain identical.
- Confirm action is tied to exact verified digest.
- After source pointer switch, client makes a new navigation/request so target continuation never runs inside the source process.
- Server-derived phase/status only; no fake percentage progress.
- `rollback_required`, `snapshot_restore_required` and `recovery_required` block normal update actions and expose the appropriate continue/recovery status/action.
- No `.env`, APP_KEY, DB credential, raw SQL, absolute release path, lifecycle token or exception trace in props/DOM.

- [ ] **Step 1: Write page RED tests** for owner-only nav/page, sanitized props, current/target version, channel warning and recovery-state behavior.
- [ ] **Step 2: Write request-flow RED tests** proving confirm returns target-active/reload instruction and continuation is a separate request; recovery-required blocks upload/confirm.
- [ ] **Step 3: Observe RED.** Expected missing page/nav/props.
- [ ] **Step 4: Implement page with existing design-system components and Persian-first copy.** No CDN/network dependency.
- [ ] **Step 5: Verify `npm run check`, `npm run types:check`, `npm run build`, focused backend page tests and RTL/error states.
- [ ] **Step 6: Commit** `feat: add Persian system update recovery UI`.

---

### Task 12: Update Package Build Tooling and Runtime Release-slot Smoke

**Files:**
- Create: `app/Services/Update/BuildSystemUpdatePackage.php`
- Create: `scripts/release/build-update-package.php`
- Modify: `scripts/release/build-release.php` only if stable launcher/update metadata must be wired into release output.
- Modify: `app/Support/ReleasePackageBuilder.php` / `ReleasePackageContract.php` only for launcher/update runtime contract additions already proven by tests.
- Create: `tests/Feature/Update/BuildSystemUpdatePackageTest.php`
- Create: `tests/Feature/Update/ReleaseSlotRuntimeSmokeTest.php` or equivalent runtime-process acceptance test.

**Interfaces:**
- Build tooling is release/development-side; production updater still requires no Composer/Node/shell tooling.
- `BuildSystemUpdatePackage` consumes an already-built target runtime release tree plus explicit exact source version/source schema fingerprint/target schema fingerprint/channel/runtime requirements; it does not infer source compatibility from Git history.
- It computes deterministic migration-tree digest, streams every allowed release file through `UpdatePackageWriter`, emits one `.seoupdate` package and validates it back through `UpdatePackageReader`.
- `scripts/release/build-update-package.php <target-release-root> <output-file> <source-version> <source-schema-fingerprint> <target-schema-fingerprint> [channel]` is a build-host convenience only; validate args strictly and never read `.env` into package.
- Runtime smoke constructs source stable root + target private slot, boots root with no pointer, activates target pointer, boots target with stable `.env`/storage, serves target Vite asset, then reverts pointer and boots source again.

- [ ] **Step 1: Write RED build-tool test** proving package deterministic/readable, contains runtime release but not `.env`/tests/frontend sources/local credentials, exact source/target metadata and migration digest.
- [ ] **Step 2: Write real-process RED runtime smoke** for direct-root -> target slot -> source root/slot, stable env/storage binding and target/source hashed assets.
- [ ] **Step 3: Observe RED.** Missing package builder/runtime update behavior.
- [ ] **Step 4: Implement build tooling and any minimal release-contract changes.** Do not make production host build its own package.
- [ ] **Step 5: Verify focused GREEN** and existing production release builder/contract tests.
- [ ] **Step 6: Extend permanent `verify` release-tree smoke** only after the focused runtime smoke is green.
- [ ] **Step 7: Commit** `feat: build runtime update packages` and `ci: smoke test release slot updates` as separate commits if workflow wiring is nontrivial.

---

### Task 13: Connected MariaDB Update/Rollback Acceptance, Permanent Gate and Release Readiness

**Files:**
- Create: `tests/Feature/Acceptance/UpdateRollbackMariaDbAcceptanceTest.php`
- Modify: `.github/workflows/ci.yml`
- Create: `docs/release-readiness/update-recovery-rollback.md`
- Create: `docs/update-recovery-operations.md`
- Modify: `docs/PROJECT_STATE.md`
- Modify: `docs/HANDOFF.md`
- Modify: `docs/DELIVERY_PLAN.md`
- Keep `AGENTS.md` PHP 8.4.1+ correction.

**Connected acceptance contract:**
- CI provisions MariaDB 10.11 and source/target runtime release fixtures with distinct stable ProductVersions (for example source `0.1.0-alpha.1`, target test release `0.1.0-alpha.2`) without changing marketing release naming on `main`.
- Source fixture includes real owner/workspace/projects, Persian + ZWNJ keyword/import/intelligence/mapping/review state, durable uploads, APP_KEY/local `.env` secrets, installation id/database fingerprint and queue rows.
- Target fixture includes at least two reversible updater-safe migrations so exact suffix and reverse order are observable.
- Success path proves: trusted package verify -> persistent maintenance -> worker drain -> safety snapshot -> private target slot -> public target assets -> pointer switch -> fresh target boot -> migrations -> target health -> `.installed.product_version` update; DB credentials/APP_KEY/installation id/database fingerprint/domain state/files remain correct.
- Bootstrap failure before migration proves dependency-free source pointer restoration without DB mutation.
- Migration failure after one completed migration proves exact suffix rollback, source pointer restore and source snapshot application.
- Post-migration health failure proves the same rollback path.
- Hard interruption after target migration but before rollback resumes on target release.
- Hard interruption after source pointer switch but before snapshot restore resumes on source release.
- Reserved database queue job blocks safety capture/switch until drained.
- Unexpected migration repository/schema drift proves `recovery_required` with artifacts retained and no broad rollback.
- Root-source first update and slot-source later update/rollback both execute.
- Existing Installer, Persian Export and Backup/Restore connected gates remain green.

- [ ] **Step 1: Write connected acceptance RED first** and add CI job `update-rollback-mariadb` with `--fail-on-skipped`; initial branch CI must fail for missing/incomplete acceptance behavior rather than skip.
- [ ] **Step 2: Complete only defects exposed by connected acceptance**, each with a focused regression test where practical; do not weaken acceptance.
- [ ] **Step 3: Verify all permanent feature-head jobs:** `verify`, PHP 8.4, PHP 8.5, Installer MariaDB, Persian Export MariaDB, Backup/Restore MariaDB, Update/Rollback MariaDB.
- [ ] **Step 4: Verify runtime-only release tree** contains stable launcher/update services/routes/built UI and direct-root + release-slot smoke passes after `composer install --no-dev` packaging.
- [ ] **Step 5: Whole-branch review** against design review rulings: no open Critical/Important finding; pay special attention to launcher fail-open, secret leakage, migration guesses, maintenance bypass and stale safety snapshot.
- [ ] **Step 6: Write release-readiness/operator docs** with exact feature-head SHA/CI, package trust warning, rollback semantics and manual `recovery_required` operator boundary.
- [ ] **Step 7: Update durable state** to say Update/Rollback is at final exact-head PR/merge/post-merge boundary; set next subsystem to **Final end-to-end lifecycle acceptance** only after feature gates are green.
- [ ] **Step 8: Require fresh exact-head PR CI** after final docs/review changes.
- [ ] **Step 9: Merge with expected-head protection**, then require independent post-merge `main` CI across all seven permanent jobs.
- [ ] **Step 10: Update `PROJECT_STATE.md` on `main`** with exact merged SHA/post-merge CI before calling Update/Rollback integrated.
- [ ] **Step 11: Commit** `test: add connected update rollback acceptance` and `docs: checkpoint verified update recovery` as appropriate.

---

## Plan Self-review

### Spec coverage

- Stable launcher/pointer/root-slot model: Tasks 1, 3, 7, 12.
- Stable env/storage and public assets: Tasks 1, 6, 12, 13.
- Package framing/trust/compatibility: Tasks 2, 5, 12.
- Owner auth/preview/UI: Tasks 5, 11.
- Journal/persistent maintenance/quiescence: Tasks 3, 6.
- Pre-update safety snapshot and Backup reuse: Tasks 4, 6.
- No source/target class mixing: Task 7 + Task 11 request-flow test.
- Migration exact history and ambiguous DDL: Tasks 8, 10, 13.
- Health/finalization/metadata identity: Task 9.
- Rollback/hard interruption/fail-closed recovery: Task 10 + Task 13.
- Release package generation/runtime smoke: Task 12.
- Permanent MariaDB/release/docs/merge gates: Task 13.
- Publisher signing authenticity: intentionally non-goal marked `NEEDS VERIFICATION`; UI trust wording pinned in Task 11.

No design requirement is intentionally left without a task. The only deferred product capability is real vendor release-signature infrastructure/remote update discovery, which the design explicitly marks outside V1.

### Type/interface consistency

- `SeoOsRuntimeReleaseLocator` is the one dependency-free read/boot contract; `ActiveReleasePointer` writes data that its tests must prove the locator can read.
- `UpdateJournal` owns phase transitions; launcher mirrors only maintenance/bootstrap-recovery subsets and has drift tests.
- `ApplySystemStateSnapshot` never owns lifecycle lock/journal; Restore and Update coordinators own state machines.
- `UpdateMigrationRunner` owns exact repository suffix mechanics; `ContinueSystemUpdate` and `RecoverInterruptedUpdate` own schema/version phase policy.
- `PrepareSystemUpdate` stops before pointer activation; `SwitchSystemUpdateRelease` only switches; `ContinueSystemUpdate` runs from fresh target request.
- Source snapshot application occurs only through `RecoverInterruptedUpdate` after source pointer/schema restoration.

### Review Focus coverage

All five high-risk review-focus cases have explicit RED tests in their owning tasks and connected acceptance repeats the highest-impact real MariaDB/runtime cases.

### Execution boundary

Product code must not begin until this plan is committed. Execution should use the existing agentic method already delegated by the user: implement natively task-by-task on `feature/update-recovery-rollback`, inspect RED/GREEN evidence after each task, request/perform review at meaningful boundaries, and never start the next subsystem before exact-head + post-merge gates are green.