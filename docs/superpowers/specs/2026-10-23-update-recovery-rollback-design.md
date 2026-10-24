# Update + Interrupted Recovery/Rollback Design

Date: 2026-10-23
Status: Approved-by-delegation; user explicitly delegated routine product/technical decisions and asked the agent to continue autonomously.
Base: `main@ea49d3158a0df76cb1e944d609397596278a4572`

## 1. Purpose

Update + Interrupted Recovery/Rollback completes the first self-host lifecycle by letting the system owner apply a pre-built SEO OS release package through the browser, without Composer, Node/npm, SSH, shell commands, symlink support, remote package services, or international internet access on the production host.

A trustworthy update must not turn an interruption into a mixed-code installation or silently strand the database on a schema the previous release cannot use. The updater therefore treats code switch, migrations, durable state, health validation and rollback as one journaled lifecycle operation built on the already integrated `ProductVersion`, `LifecycleMutex`, Backup/Restore bundle/snapshot primitives and installation metadata.

## 2. Goals

V1 must:

- accept a manually uploaded, pre-built, versioned update package in the browser;
- verify the complete package and source/target compatibility before mutation;
- require the installer-created system owner, verified authentication and recent password confirmation;
- remain usable on ordinary shared hosting with PHP + filesystem + SQL only;
- never require Composer, Node/npm, SSH, shell commands, symlink creation, `tar`, ZIP/Phar or a remote update API at runtime;
- create a verified pre-update system-state safety snapshot before destructive work;
- serialize Backup, Restore and Update/Rollback through the shared `LifecycleMutex`;
- stage target code into an immutable private release slot rather than overwrite the running code tree;
- switch the active application release through one atomic pointer update;
- keep stable HTTP/CLI launchers outside versioned release slots so a broken target release cannot destroy the recovery entrypoint;
- run target migrations under the target release code/vendor tree;
- validate target application/database health before declaring success;
- support deterministic automatic rollback for official update fixtures whose migration path is proven reversible;
- fail closed as `recovery_required` rather than guess when rollback safety cannot be proven;
- preserve local `.env`, APP_KEY, DB credentials, installation id/database fingerprint and unrelated local secrets;
- preserve sessions only when safe, while blocking ordinary traffic/background lifecycle mutation during the active transition;
- expose Persian-first server-derived phases/status without fake progress percentages;
- add permanent connected MariaDB acceptance covering successful update, hard interruption boundaries, migration failure, health failure and rollback;
- verify update behavior from a runtime-only packaged release tree.

## 3. Explicit Non-goals

V1 does not:

- download releases from GitHub, a SaaS endpoint or another international service;
- auto-discover whether an update exists;
- patch Composer/npm dependencies on the production host;
- build frontend assets on the production host;
- depend on symlink switching;
- support arbitrary third-party PHP packages as untrusted input;
- guarantee rollback for a release whose migrations are intentionally irreversible;
- attempt automatic recovery after unexpected schema drift that cannot be reconciled with the recorded migration suffix;
- modify DB credentials, APP_URL, mail/storage/external secrets or local installation identity;
- replace the stable launcher ABI automatically in V1;
- clean every old content-addressed frontend asset immediately after success;
- implement remote release signing infrastructure without an actual long-lived signing-key operational process.

Manual update packages are trusted operator artifacts in V1. Integrity is verified cryptographically by SHA-256, but vendor authenticity/signature enforcement is **NEEDS VERIFICATION before public/commercial distribution** because the repository currently has no durable release-signing private-key workflow. Remote/automatic update delivery must not ship without signature verification.

## 4. Current-state Audit

The current integrated system already provides:

- `ProductVersion` backed by root `VERSION` (`0.1.0-alpha.1` at design time);
- `ReleasePackageBuilder` and `ReleasePackageContract`, producing a runtime-only tree with application code, migrations, vendor, built assets and no `.env`/dev tree;
- `InstallationState`, with mutable `product_version` while preserving local installation id/database fingerprint;
- `LifecycleMutex` shared by Backup/Restore and reserved for Update/Rollback;
- `MaintenanceWindow` for short synchronous lifecycle quiescence;
- `CaptureSystemState` and deterministic `seo-backup/1` safety bundles;
- clean restore and interrupted-restore recovery logic;
- `InstallerMigrationRunner`, proving Laravel `Migrator` can run in-process without shell access;
- permanent PHP 8.4/8.5, Installer MariaDB, Persian Export MariaDB and Backup/Restore MariaDB CI gates.

There is no existing updater, release switcher, persistent update journal or release-slot launcher. Product code must therefore add those boundaries rather than repurpose an unrelated subsystem.

`AGENTS.md` and the old master architecture prose still mention PHP 8.3+, while Composer/current CI require PHP `>=8.4.1`; current Git/CI/product state wins. The engineering-rule drift should be corrected during this subsystem.

## 5. Approaches Considered

### A. In-place file overwrite — rejected

Upload the new release and overwrite `app/`, `vendor/`, `routes/`, `bootstrap/` and public assets directly.

Advantages:

- simple mental model;
- no launcher/pointer layer.

Rejected because interruption can leave mixed old/new PHP files, autoloader state and assets. Recovery code itself may be overwritten halfway through the operation, making a browser-only fail-safe impossible.

### B. Symlink/current-directory release switch — rejected for V1

Stage releases as sibling directories and atomically switch a `current` symlink or hosting document root.

Advantages:

- common deployment model;
- clean code rollback.

Rejected because shared hosting may forbid symlink creation/change, the application cannot assume control of the web-server document root, and shell tooling is explicitly unavailable.

### C. Stable launchers + private immutable release slots + atomic pointer — selected

Keep tiny stable root launchers (`public/index.php`, `artisan`, one dependency-free runtime locator) in the installation root. Stage target runtime trees under private lifecycle storage. The launchers resolve the active release from a restrictive atomic pointer file and boot that release's `vendor/autoload.php` + `bootstrap/app.php`.

The root installation remains the implicit initial release when no pointer exists. Future releases live in private slots. This works without symlinks or shell commands, isolates code versions by absolute path (which also avoids opcache path collisions), and keeps a recovery entrypoint even if the target application cannot boot.

Trade-off: target Laravel code runs from a release-slot base path while persistent `.env` and `storage/` remain rooted at the stable installation. Bootstrap must therefore explicitly bind the stable installation environment/storage paths when launched from a slot.

## 6. Stable Launcher ABI

Launcher schema: `seo-launcher/1`.

Stable files:

- `public/index.php`;
- root `artisan`;
- dependency-free helper under `bootstrap/runtime-release.php` (exact name may be adjusted during plan review, but it remains stable-root code with no Composer dependency).

Responsibilities:

1. determine stable installation root;
2. read `storage/app/lifecycle/update/active-release.json` if present;
3. validate pointer schema, release id/path/version/digest and containment under the private release root;
4. reject symlink/path traversal/control-byte release roots;
5. define the stable installation-root context for the target bootstrap;
6. load active release `vendor/autoload.php` and `bootstrap/app.php`;
7. if the pointer targets a release that cannot bootstrap before migrations start, atomically revert to the recorded source release when journal evidence proves that rollback is safe;
8. for CLI, block normal scheduler/queue lifecycle entry while a destructive update phase is active, except explicitly allowlisted updater/recovery commands if such commands are added later.

When no active-release pointer exists, launchers boot the stable installation root exactly as today. This is the compatibility path for the first updater-capable release.

V1 update packages must declare `launcher_schema: seo-launcher/1`. A package requiring a different launcher schema is rejected before mutation. V1 does not auto-replace the stable launcher ABI.

## 7. Stable vs Versioned Paths

Stable installation root owns:

- `.env`;
- `.installed`;
- `storage/**` durable/runtime data;
- stable launchers;
- public content-addressed build assets needed by currently retained releases;
- lifecycle pointer/journals/safety artifacts.

Versioned release slot owns:

- `VERSION`;
- `app/**`;
- `bootstrap/**` except that stable launcher helper remains the entry resolver;
- `config/**`;
- `database/migrations/**`;
- `routes/**`;
- `resources/views/**`;
- `vendor/**`;
- release-owned built manifest and code assets;
- other files explicitly accepted by `ReleasePackageContract`.

Default private release root:

`storage/app/lifecycle/update/releases/<release-id>/`

`release-id` is derived from the target version plus a digest prefix, not user-controlled path input.

Release slots are immutable after verification/staging. Update code never edits an active slot in place.

## 8. Stable Environment and Storage Binding

A release launched from a private slot has a different Laravel base path, but it must keep the installation's existing environment and durable storage.

The stable launcher defines the stable installation-root context before requiring target code. Target `bootstrap/app.php` then:

- keeps `basePath` equal to the active release slot so `app/`, config, routes, vendor and migrations come from the target release;
- sets environment path to the stable installation root before environment bootstrap;
- sets storage path to `<stable-root>/storage`;
- keeps local secrets and persistent state outside the replaceable release tree.

When active root equals stable root, behavior remains equivalent to the existing bootstrap.

Tests must prove both direct-root and release-slot boot paths.

## 9. Public Assets

Target Vite manifests remain inside each release slot so target PHP code reads the matching manifest.

Before pointer switch, updater copies target content-addressed `public/build/assets/**` files into stable web-root `public/build/assets/**` without deleting existing hashes. Old and new releases can therefore reference their own hashed files during rollback windows.

V1 treats stable non-hashed public launcher/font/bootstrap files as launcher-ABI assets. Update verification requires them to remain compatible/equal unless the update design explicitly supports a safe stable-file transition. This prevents a target package from silently replacing the only recovery entrypoint before code switch.

Old hashed assets may be garbage-collected only after no retained release slot/rollback target references them. Immediate cleanup is not a success requirement.

## 10. Update Package Format

Package schema: `seo-update/1`.
Magic: `SEOUPDATE\n`.
Recommended extension: `.seoupdate`.

Use the same bounded application-owned framing pattern proven by Backup/Restore, but keep update types/errors/path allowlists separate so backup semantics are not destabilized.

Binary layout:

1. magic;
2. 32-bit big-endian manifest length;
3. canonical UTF-8 JSON manifest;
4. deterministic length-delimited release entries.

Manifest includes at minimum:

- `schema`: `seo-update/1`;
- `created_at_utc`;
- `target_product_version`;
- exact `allowed_source_product_versions` list for V1;
- `channel`: `stable|beta|development`;
- `launcher_schema`: `seo-launcher/1`;
- `minimum_php_version`;
- required PHP extensions/capabilities;
- source application schema fingerprint expected before migration;
- target application schema fingerprint expected after migration;
- ordered release entries `{path,size,sha256,media_type}`;
- migration metadata sufficient to bind the package to the target migration tree;
- package content digest.

Unknown schema/required fields fail closed.

The uploaded package is fully streamed/verified with configured maximum manifest, path, entry, file-count and total-package limits before a release slot is activated.

## 11. Release Package Trust Model

System owner upload is treated as explicit trusted-code installation. The updater cannot sandbox arbitrary PHP code.

V1 therefore guarantees:

- package integrity and deterministic digest verification;
- strict path containment/no symlink traversal;
- release-tree contract validation;
- exact source-version/schema compatibility;
- target runtime requirement validation;
- operator preview before confirmation.

It does not claim vendor authenticity without a signing-key lifecycle. Release-signature support remains a launch/security gate marked **NEEDS VERIFICATION** rather than a fake cryptographic checkbox.

## 12. Authorization and UX

Only the persisted system owner may access Update/Rollback.

Routes require:

- authenticated;
- verified;
- recent password confirmation;
- system-owner middleware;
- dedicated update throttle for uploads/confirm/recovery.

Persian-first settings navigation label: `به‌روزرسانی و بازیابی نسخه`.

UI shows:

- currently installed ProductVersion;
- update channel;
- upload action;
- verified target version/channel/runtime requirements;
- package digest;
- migration count/compatibility summary without raw SQL/server paths;
- explicit warning that update enters maintenance and creates a safety backup;
- exact digest-bound confirmation;
- server-derived lifecycle phase;
- recovery/rollback-required state and action/status;
- final version after success.

No fake percentage progress.

No `.env`, APP_KEY, DB credentials, raw migration SQL, absolute release path or lifecycle owner token is rendered.

## 13. Update Journal

Default path:

`storage/app/lifecycle/update/state.json`

Atomic restrictive writes, no secrets.

Representative phases:

- `uploaded`;
- `verified`;
- `preparing`;
- `snapshot_ready`;
- `quiescing`;
- `staged`;
- `switch_pending`;
- `target_active`;
- `migrating`;
- `validating`;
- `complete`;
- `rollback_required`;
- `migration_rollback`;
- `source_switch_pending`;
- `snapshot_restore_required`;
- `recovery_required`.

Journal evidence includes only non-secret data needed to prove state:

- source/target versions;
- package digest/upload id;
- source/target release ids;
- source/target schema fingerprints;
- pre-update migration repository fingerprint/list hash;
- migrations observed as newly applied;
- safety snapshot ids/relative paths;
- timestamps and stable error code;
- whether active pointer switch has occurred.

The journal never contains APP_KEY, credentials, backup payload, raw SQL or arbitrary absolute paths.

## 14. Persistent Update Maintenance Gate

`MaintenanceWindow::run()` is intentionally synchronous and auto-cleans in one request. Update needs a transition spanning requests because the target release must boot in a new PHP request after pointer switch.

V1 therefore adds an update-specific persistent maintenance gate driven by UpdateJournal phases while preserving `MaintenanceWindow` for short critical sections.

Behavior:

- before destructive phases, ordinary web requests return a stable maintenance/recovery response;
- only allowlisted Update status/continue/recovery endpoints remain reachable;
- authorization still applies to those endpoints;
- stable `artisan` refuses normal scheduler/queue work while destructive update phases are active;
- synchronous backup/staging/migration/recovery sections may additionally use `MaintenanceWindow` where it remains useful.

This avoids embedding a maintenance-bypass secret in URLs/history and keeps the update UI reachable across release switch.

## 15. Quiescence and Background Work

Before code switch:

1. activate persistent update maintenance gate;
2. signal existing queue workers to restart/exit after current work using Laravel's supported queue restart mechanism;
3. stable launcher blocks new scheduler/queue worker starts while destructive update phases are active;
4. inspect database-queue reserved work; if active jobs cannot prove quiescence, remain non-destructive and surface a retryable operator state;
5. queued-but-not-running jobs remain durable and may execute after successful update/recovery.

V1 does not kill PHP processes or require process-management commands.

## 16. Pre-update Safety Snapshot

After package verification and mutable-precondition revalidation, Update acquires `LifecycleMutex('update')` and captures a pre-update system-state bundle using the existing capture primitive while lock ownership is already held.

Snapshot includes the exact current durable DB/settings/files state and APP_KEY under source ProductVersion/schema. Destination-local installation metadata snapshot is stored separately exactly as Backup/Restore recovery does.

The snapshot is private, permission-restricted and retained until update success/rollback cleanup policy allows deletion.

No destructive release/migration operation starts unless safety capture completes successfully.

## 17. Staging and Verification

Target release is extracted only into a new private release slot.

Before activation:

- all package entry hashes/sizes/digest pass;
- path allowlist and release-tree contract pass;
- target `VERSION` equals manifest target version and is non-`dev`;
- current ProductVersion is explicitly in `allowed_source_product_versions`;
- target version is newer by PHP `version_compare` semantics;
- current source schema fingerprint equals manifest source fingerprint;
- current launcher schema satisfies package requirement;
- current PHP version/extensions satisfy target requirements;
- target release slot contains no `.env`, tests, source frontend toolchain, private credentials or symlinks;
- target package migration tree digest matches manifest;
- stable launcher files are compatible with launcher schema;
- target public hashed assets are copied safely/additively to stable public build storage before pointer switch.

Verification is rerun after `LifecycleMutex` acquisition where the underlying state could have changed.

## 18. Active Release Pointer

Default:

`storage/app/lifecycle/update/active-release.json`

Schema: `seo-release-pointer/1`.

Pointer contains non-secret validated fields such as:

- active release id;
- active ProductVersion;
- package digest;
- launcher schema.

It never stores arbitrary user paths. The release id is resolved under the configured private release root.

Write is temp-file + restrictive permission + atomic rename.

No pointer means stable installation root is active (legacy/initial slot).

Switching pointer is the only operation that changes which PHP release a new request boots.

## 19. Successful Update State Machine

### Phase A — Upload/verify

- save bounded upload privately;
- fully verify `seo-update/1` package;
- compute digest and preview;
- no mutation.

### Phase B — Confirm/prepare

- require exact verified digest;
- acquire `LifecycleMutex('update')`;
- revalidate current ProductVersion/schema/installation state/package bytes;
- capture pre-update safety snapshot;
- stage immutable target release slot;
- validate release contract and runtime requirements;
- copy additive content-addressed public assets;
- persist source/target release evidence;
- activate persistent update maintenance gate/quiescence;
- release mutex after a safe non-mixed prepared state is durable.

### Phase C — Switch target

- prove quiescence;
- reacquire lifecycle mutex;
- set journal `switch_pending`;
- atomically write active pointer to target;
- set durable evidence that pointer changed;
- return from source request without attempting to execute target classes in the same PHP process.

### Phase D — Target continuation

Next allowed update request boots target release through stable launcher.

Target coordinator:

- validates journal/package/active pointer/source evidence;
- acquires lifecycle mutex;
- records migration repository precondition;
- runs pending target migrations in-process using target vendor/code;
- records newly applied migration suffix durably;
- validates target schema fingerprint;
- clears/rebuilds only safe runtime caches needed by target code;
- runs target health validation;
- atomically updates `.installed.product_version` to target while preserving local installation id/database fingerprint/secrets;
- marks complete;
- disables persistent update maintenance gate;
- releases mutex;
- invalidates/refreshes sessions only if target changes require it; V1 normally preserves APP_KEY/session compatibility.

## 20. Migration Contract

Official V1 update packages must have a tested reversible migration path from every declared source version.

Runtime rules:

- source version is exact/explicit, not guessed through a broad range;
- current migration repository state is fingerprinted before target migration;
- only migrations present in the target release tree are considered;
- migrations newly applied by this update are recorded/derived as an exact suffix relative to pre-update repository state;
- no unrelated migration drift may appear during update;
- foreign-key behavior follows each migration's explicit schema logic; updater does not globally disable integrity;
- a target release is not marked complete until target schema fingerprint matches manifest.

CI acceptance must prove both forward migration and rollback on real MariaDB data for representative update fixtures.

If migration history does not match the recorded suffix, automatic rollback stops as `recovery_required` rather than calling a broad `migrate:rollback` guess.

## 21. Health Validation

Target update health must be deterministic/local and not depend on external internet.

Minimum target checks:

- target ProductVersion matches expected manifest version;
- active release pointer resolves the expected immutable slot;
- Laravel target app booted successfully through stable launcher;
- DB connection works;
- target application schema fingerprint matches manifest;
- installation metadata still contains same installation id/database fingerprint/owner;
- APP_KEY can perform an encryption/decryption round-trip without changing it;
- stable storage/runtime writable paths remain writable;
- release assets/manifest required by target exist;
- durable catalog has no silent drift;
- update/restore journals are mutually consistent and no conflicting lifecycle operation is active.

Queue/scheduler external execution freshness may be reported as warning unless a demonstrable local failure makes target operation unsafe. Existing installer operational evidence remains authoritative for shared-host cron setup.

## 22. Rollback and Recovery

### 22.1 Before active pointer switch

Failure is non-destructive:

- remove target staging/upload as appropriate;
- keep source release active;
- clean update journal/snapshot according to failure type;
- exit maintenance gate;
- no DB rollback needed.

### 22.2 Target cannot bootstrap before migrations

Stable launcher sees `switch_pending/target_active` evidence and a target bootstrap failure before migration state indicates mutation.

It may atomically revert pointer to source, mark a sanitized recovery code and let source release present recovery/status. No schema rollback is needed because migrations never started.

### 22.3 Migration/health failure after target mutation

Target release remains active while rollback work requiring target migration definitions is performed.

Recovery sequence:

1. mark `rollback_required`;
2. verify migration repository equals the recorded pre-update state plus the exact newly applied suffix;
3. roll back exactly those update migrations in reverse using target release migration code;
4. verify source schema fingerprint is restored;
5. mark `source_switch_pending`;
6. atomically point launcher back to source release;
7. mark `snapshot_restore_required`;
8. return/end current target request;
9. next recovery request boots source release;
10. source release restores the pre-update system-state safety snapshot and installation metadata through a shared recovery kernel extracted from the existing Backup/Restore safety-restore path;
11. clear volatile runtime state as required;
12. verify source ProductVersion/schema/installation metadata/data/files;
13. clear update journal/maintenance gate and leave source active.

The pointer switch is intentionally split from snapshot application so the source-version recovery code validates and applies the source-version backup under the exact source schema.

### 22.4 Hard interruption during rollback

Journal phase determines which release must boot next:

- before source pointer switch: boot target and resume migration rollback;
- after source pointer switch: boot source and resume source snapshot restore.

Stable launcher never chooses a release by inference from filesystem timestamps.

### 22.5 Unprovable rollback

If target code cannot verify migration suffix, migration down fails, source schema fingerprint cannot be restored, safety snapshot is missing/corrupt, or source release slot is unavailable:

- remain in maintenance;
- mark `recovery_required` with sanitized operator code;
- block ordinary lifecycle operations;
- do not delete source/target release slots or safety snapshot;
- expose recovery diagnostics that contain no secrets/raw SQL/absolute paths.

No automatic destructive fallback is attempted.

## 23. Shared Recovery Kernel

`RecoverInterruptedRestore` currently owns private logic for applying a verified safety bundle back to DB/files/runtime settings/installation metadata.

Update must not duplicate that destructive restore algorithm.

Plan should extract the minimum reusable internal service (for example `ApplySystemStateSnapshot`) with tests proving existing interrupted-restore behavior is unchanged. Both Restore recovery and Update source-version recovery call the same kernel.

The extraction is a behavior-preserving refactor guarded by existing Backup/Restore acceptance before Update relies on it.

## 24. Installation Metadata

On successful update:

- `product_version` changes to target version;
- `installation_id` stays unchanged;
- `database_fingerprint` stays unchanged;
- system owner stays unchanged;
- restore provenance stays unchanged unless another feature explicitly modifies it;
- local environment secrets stay unchanged.

Optional non-secret update provenance fields may be added only if needed by the implementation plan, e.g. `updated_at`, `previous_product_version`.

On rollback, source installation metadata is restored exactly from the pre-update snapshot.

## 25. Release Retention

Never delete the current source release until target update is fully complete and the safety retention policy allows it.

V1 keeps at least:

- active release;
- immediate rollback source release;
- safety snapshot while update/recovery state exists.

The stable initial root release remains available as the implicit source for the first update. Later private release slots may be pruned only when no active pointer/journal/retention reference exists.

## 26. Error Semantics

Stable codes/messages include at least:

- `update_not_authorized`;
- `update_invalid_package`;
- `update_checksum_mismatch`;
- `update_incompatible_source`;
- `update_incompatible_runtime`;
- `update_schema_mismatch`;
- `update_launcher_incompatible`;
- `update_target_staging_failed`;
- `update_snapshot_failed`;
- `update_quiescence_required`;
- `update_switch_failed`;
- `update_migration_failed`;
- `update_health_failed`;
- `update_rollback_failed`;
- `update_recovery_required`.

Known browser responses use stable Persian operator text. Raw exception text, SQL, stack paths and secrets are not returned.

## 27. Security

- system-owner + verified + recent-password enforcement;
- upload/package size limits before/while parsing;
- strict release path allowlist and no absolute/traversal/control-byte paths;
- no PHP deserialization, shell execution, Phar execution or dynamic archive metadata;
- release slots/journals/snapshots private and non-web-addressable;
- package never contains `.env` or local credentials;
- stable pointer accepts generated release ids only;
- launchers reject symlinked/out-of-root release targets;
- lifecycle journals contain no secrets;
- update package is executable trusted code and UI states that only trusted release packages should be installed;
- vendor signing remains **NEEDS VERIFICATION** before public distribution rather than claiming authenticity without key operations.

## 28. Connected Acceptance

Permanent MariaDB 10.11 update acceptance must exercise a source release and a distinct target release fixture.

Minimum success case:

1. boot/install source runtime release;
2. create real owner/workspace/project plus Persian + ZWNJ keyword/intelligence data and durable uploads;
3. create volatile sessions/cache/queue state;
4. build/upload/verify a target `seo-update/1` package;
5. confirm exact digest;
6. prove pre-update safety bundle exists before pointer switch/migrations;
7. stage target release and content-addressed public assets;
8. switch active pointer;
9. boot target release through stable launcher;
10. run target migration adding/changing representative schema in a rollback-safe fixture;
11. pass target health checks;
12. prove `.installed.product_version` updated while installation id/database fingerprint/APP_KEY/DB credentials remain source-local;
13. prove domain data/files/auth remain correct and target code is active;
14. prove normal lifecycle maintenance/journal artifacts are cleaned/retained according to policy.

Minimum rollback cases:

- target bootstrap failure before migration => source pointer restored without DB mutation;
- injected migration failure after at least one migration => exact applied suffix rolled back, source pointer restored, source safety snapshot applied, source health/data verified;
- injected post-migration health failure => migrations rolled back, source pointer restored, safety snapshot applied;
- hard interruption after migration application but before rollback => next request resumes target recovery from journal;
- hard interruption after source pointer switch but before snapshot restore => next request boots source and resumes snapshot restore;
- unexpected migration repository drift => fail closed `recovery_required` without broad rollback guess.

Acceptance must use `--fail-on-skipped` and become a permanent merge gate alongside Installer, Persian Export and Backup/Restore MariaDB gates.

## 29. Release-tree Acceptance

Runtime package acceptance must prove:

- stable launcher files exist and direct-root boot still works with no pointer;
- release-slot boot works with stable `.env`/storage binding;
- update UI/routes/services survive `composer install --no-dev` packaging;
- target release package can be parsed/staged without ZIP/Phar/shell tooling;
- production host path has no Node/npm/Composer requirement after package build;
- normal `/up`, authentication and a representative app route work after target switch in test fixture;
- recovery can return to source release after injected failure.

## 30. UI/Operator Flow

Persian-first flow:

1. open `تنظیمات -> به‌روزرسانی و بازیابی نسخه`;
2. view current version/channel;
3. upload trusted `.seoupdate` package;
4. review target version/channel/requirements/digest/migration summary;
5. confirm exact verified package;
6. application enters update maintenance;
7. UI/status polling follows server phases across source -> target release switch;
8. on success, show target version and normal-app return action;
9. on rollback, show that source version was restored and require a fresh status/login check where needed;
10. on `recovery_required`, block ordinary update/backup/restore and show explicit recovery/operator guidance.

## 31. Implementation Boundaries

Expected new/changed areas:

- stable runtime release locator/launcher;
- update package manifest/reader/writer or builder tooling;
- update config limits/paths;
- `UpdateJournal`;
- active release pointer service;
- update package verifier/stager;
- quiescence guard;
- target migration runner/rollback tracker;
- update health validator;
- update coordinator + recovery coordinator;
- minimal shared system-state recovery kernel extraction from Restore recovery;
- owner-only settings controller/routes/UI;
- permanent connected Update/Rollback MariaDB CI job;
- release-tree acceptance additions;
- state/handoff/release-readiness/operator docs.

Avoid broad refactors of proven Backup/Restore framing or destructive restore behavior unless a focused shared kernel is required and existing regression/acceptance tests remain green.

## 32. Review Focus

Written-spec/plan review must challenge:

1. stable launcher cannot be replaced halfway through V1 update;
2. target code never executes in the same request/process that loaded source application classes;
3. target release uses stable `.env`/storage while versioned app/config/routes/vendor come from target slot;
4. public target hashed assets are available before pointer switch without deleting source assets;
5. source snapshot is captured before any destructive migration/code activation;
6. active pointer is path-safe, generated and atomically written;
7. background queue/scheduler work cannot mutate durable state across transition without quiescence evidence;
8. migration rollback is exact-suffix based, never a broad guessed rollback;
9. source safety snapshot is applied only after source schema/pointer are restored;
10. hard interruption around both pointer switches resumes the correct release deterministically;
11. local APP_KEY/DB credentials/installation identity survive successful update and rollback;
12. no update package trust/authenticity claim exceeds actual signing infrastructure;
13. release-slot and direct-root runtime smoke both remain green;
14. Backup/Restore regression gates remain permanent.

## 33. Merge Boundary

Update + Interrupted Recovery/Rollback is merge-ready only when:

1. this design and written review are committed;
2. implementation plan is reviewed and committed;
3. every product task has observed RED -> correct GREEN evidence;
4. existing Backup/Restore acceptance remains green after any shared-recovery extraction;
5. permanent PHP 8.4/8.5 and existing connected gates remain green;
6. new Update/Rollback MariaDB connected acceptance is green with `--fail-on-skipped`;
7. runtime release-tree direct-root + release-slot update/recovery smoke is green;
8. whole-branch review has no open Critical/Important finding;
9. exact-head PR CI is green;
10. merge uses expected-head protection;
11. post-merge `main` CI is independently green;
12. `PROJECT_STATE.md` records the exact merged/verified state.

Only then proceed to **Final end-to-end lifecycle acceptance**.