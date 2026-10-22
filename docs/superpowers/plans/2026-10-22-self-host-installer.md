# Self-host Installer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the existing installer foundation into a browser-driven, resumable, offline-capable self-host bootstrap that configures production environment, migrates a real database, creates exactly one first owner/workspace, exposes queue/scheduler readiness, and writes the final install lock only after all mandatory invariants pass.

**Architecture:** Keep installer runtime file-backed and database-independent until database configuration succeeds. Add small services for filesystem serialization/state, atomic environment persistence, database verification, in-process migrations, owner bootstrap, operational probes, and finalization; the controller/wizard orchestrates these services but does not duplicate their rules. Every phase derives truth from current files/database/probe evidence, not only from a journal marker, so retry and interruption remain safe.

**Tech Stack:** Laravel 13, PHP 8.4.1+, Inertia 3, React 19, TypeScript, MySQL/MariaDB, database queue, file-backed installer runtime.

**Spec:** `docs/superpowers/specs/2026-10-22-self-host-installer-design.md`

## Global Constraints

- Target packaged self-host installation must not require Composer, Node.js, npm, SSH, shell commands, foreign APIs, or international internet access on the host.
- Runtime floor stays PHP `>= 8.4.1`; CI compatibility remains PHP 8.4 and 8.5.
- Initial supported production database path is MySQL/MariaDB; MariaDB 10.11 connected acceptance is required.
- Installer routes keep file session/cache and sync queue until `.installed` exists.
- `.installed` is written only after all mandatory invariants are revalidated.
- Secrets must not be stored in installer journal/probe state, echoed to the browser, or included in operator diagnostics.
- State-changing installer actions share one filesystem-backed exclusive mutex.
- Public registration is disabled by default after first-owner bootstrap.
- Password validation during installation must remain offline-safe; no blocking breach lookup or external HTTP dependency.
- Feature expansion outside the self-host lifecycle remains frozen.

## Review Focus

1. Atomic `.env` replacement failure must preserve the prior `.env`, runtime key, and non-secret config; pin this in Task 3.
2. Interrupted/partial migrations must resume from actual pending migrations and never trust a stale phase marker; pin this in Task 4.
3. Duplicate owner submissions or lost HTTP responses must still result in exactly one owner/workspace/membership; pin this in Task 5.
4. Changing database/environment inputs after downstream work must invalidate stale database/migration/operations evidence rather than finalize against the old target; pin this across Tasks 3, 4, and 7.
5. Queue/scheduler configuration without observed execution must never be reported as verified; warning acknowledgement may permit shared-host finalization, but demonstrable local failure must block; pin this in Task 6.

---

### Task 1: Installer State, Mutex, and Derived Phase Contract

**Files:**
- Create: `app/Support/Installer/InstallerPhase.php`
- Create: `app/Support/Installer/InstallerJournal.php`
- Create: `app/Support/Installer/InstallerMutex.php`
- Create: `app/Support/Installer/InstallerStatus.php`
- Modify: `config/installer.php`
- Modify: `app/Support/InstallationState.php`
- Test: `tests/Feature/System/InstallerStateTest.php`

**Interfaces:**
- Produces: `InstallerMutex::run(callable $callback): mixed` for all later installer mutations.
- Produces: `InstallerJournal::read(): array`, `InstallerJournal::replace(array $state): void`, `InstallerJournal::clear(): void`; accepted payload contains no secret-bearing fields.
- Produces: `InstallerStatus::snapshot(): array{phase: InstallerPhase, installed: bool, journal: array}`; later tasks extend invariant providers without changing controller callers.
- Produces: `InstallationState::details(): ?array` and upgrades `markInstalled(array $metadata = []): void` to atomically persist schema/product/install identifiers while preserving current `isInstalled()` behavior.

- [ ] **Step 1: Write failing state/mutex tests**

Cover: journal starts empty; atomic journal replacement; secret-like keys (`password`, `secret`, `token`, raw DSN) are rejected; mutex serializes nested/parallel attempts predictably; install lock metadata is valid JSON and `isInstalled()` stays compatible.

- [ ] **Step 2: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/System/InstallerStateTest.php`
Expected: FAIL because installer state services/interfaces do not exist yet.

- [ ] **Step 3: Implement minimal filesystem-backed state primitives**

Use files under `storage/app/installer/`; create parent directories with restrictive permissions; write temp files in the same directory then rename; use `flock(LOCK_EX)` for the mutex; reject journal keys whose names indicate secrets.

- [ ] **Step 4: Run focused tests and verify GREEN**

Run: `php artisan test tests/Feature/System/InstallerStateTest.php`
Expected: PASS.

- [ ] **Step 5: Run baseline system tests**

Run: `php artisan test tests/Feature/System`
Expected: PASS with existing installer behavior preserved.

- [ ] **Step 6: Commit**

Commit message: `feat: add resumable installer state primitives`

---

### Task 2: Database-independent Host Preflight and Wizard Contract

**Files:**
- Modify: `app/Support/SystemHealthChecker.php`
- Modify: `app/Contracts/SystemProbe.php`
- Modify: `app/Support/NativeSystemProbe.php`
- Create: `app/Support/Installer/InstallerPreflight.php`
- Modify: `app/Http/Controllers/InstallerController.php`
- Modify: `resources/js/pages/install/index.tsx`
- Modify: `routes/web.php`
- Test: `tests/Feature/System/InstallerPreflightTest.php`
- Test: `tests/Feature/System/InstallerFlowTest.php`

**Interfaces:**
- Produces: `InstallerPreflight::check(): array{ready: bool, checks: array<string,mixed>}` containing only host facts available before DB credentials exist.
- `SystemHealthChecker` keeps post-install checks but delegates/aligns shared host checks so installer and system diagnostics cannot drift.
- `GET /install` returns `installer.phase`, preflight report, current non-secret durable values, and actions available for that phase.

- [ ] **Step 1: Write failing preflight tests**

Cover: no DB config still allows preflight success; missing base extension/storage/assets/runtime files fail; DB reachability/queue/scheduler are absent from preflight blockers; UI props expose phase without secret fields.

- [ ] **Step 2: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/System/InstallerPreflightTest.php tests/Feature/System/InstallerFlowTest.php`
Expected: FAIL on current DB-dependent report/props.

- [ ] **Step 3: Implement preflight and phase-aware GET contract**

Keep installer middleware file/file/sync behavior unchanged. Do not add database access to page rendering before database phase.

- [ ] **Step 4: Connect Persian-first wizard shell**

Refactor `install/index.tsx` into phase sections with RTL copy, LTR values/commands, stable error display, and disabled actions for unavailable phases. No future-phase button may post to a backend capability that does not yet exist.

- [ ] **Step 5: Verify backend and frontend**

Run: `php artisan test tests/Feature/System/InstallerPreflightTest.php tests/Feature/System/InstallerFlowTest.php`
Run: `php artisan wayfinder:generate --with-form && npm run check && npm run types:check`
Expected: PASS.

- [ ] **Step 6: Commit**

Commit message: `feat: separate installer host preflight`

---

### Task 3: Atomic Environment Persistence and Database Verification

**Files:**
- Create: `app/Support/Installer/InstallerEnvironment.php`
- Create: `app/Support/Installer/EnvironmentWriter.php`
- Create: `app/Support/Installer/DatabaseVerifier.php`
- Create: `app/Support/Installer/DatabaseVerificationResult.php`
- Modify: `app/Support/RuntimeApplicationKey.php`
- Modify: `app/Http/Controllers/InstallerController.php`
- Modify: `resources/js/pages/install/index.tsx`
- Modify: `routes/web.php`
- Modify: `.env.example`
- Test: `tests/Feature/System/InstallerEnvironmentTest.php`
- Test: `tests/Feature/System/InstallerDatabaseTest.php`

**Interfaces:**
- Produces: `InstallerEnvironment::publicValues(): array` with no persisted secret values.
- Produces: `EnvironmentWriter::persist(array $values, ?string $databasePassword, bool $keepExistingPassword): void` using an allow-list and atomic replacement.
- Produces: `DatabaseVerifier::verify(array $connection): DatabaseVerificationResult` with stable error codes (`missing_driver`, `unreachable`, `authentication_failed`, `database_missing`, `insufficient_privileges`, `unknown`) and no secret-bearing message.
- Journal stores only normalized DB fingerprint/digest after successful verification.

- [ ] **Step 1: Write failing environment tests**

Cover: preserve unrelated `.env` keys; force production/app debug false; persist runtime APP_KEY; password never appears in response/journal; keep-current-secret semantics; invalid URL/host/port/name rejected; simulated temp-write/rename failure keeps old `.env` byte-for-byte.

- [ ] **Step 2: Verify environment RED**

Run: `php artisan test tests/Feature/System/InstallerEnvironmentTest.php`
Expected: FAIL because environment writer is absent.

- [ ] **Step 3: Implement allow-listed atomic environment writer**

Allowed keys initially: `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION`, `SELF_HOST_REGISTRATION`.

- [ ] **Step 4: Write database verifier tests**

Use test doubles for classified errors plus a SQLite/local connection only for fast service-contract tests. Assert write probe happens inside a rollback transaction and credentials never appear in exception payloads.

- [ ] **Step 5: Verify database RED then implement verifier**

Run: `php artisan test tests/Feature/System/InstallerDatabaseTest.php`
Expected RED, then PASS after implementation.

- [ ] **Step 6: Connect HTTP endpoints and UI**

Add phase-guarded POST endpoints for environment persistence and database verification. Changed environment/DB fingerprint must clear stale downstream journal evidence (`migrations`, `owner`, `operations` acknowledgements) before returning.

- [ ] **Step 7: Verify focused suite and static/front-end gates**

Run: `php artisan test tests/Feature/System/InstallerEnvironmentTest.php tests/Feature/System/InstallerDatabaseTest.php tests/Feature/System/InstallerFlowTest.php`
Run: `composer lint:check && composer types:check`
Run: `php artisan wayfinder:generate --with-form && npm run check && npm run types:check && npm run build`
Expected: PASS.

- [ ] **Step 8: Commit**

Commit message: `feat: configure installer environment and database`

---

### Task 4: In-process Migration Runner and Retry Semantics

**Files:**
- Create: `app/Support/Installer/InstallerMigrationRunner.php`
- Modify: `app/Http/Controllers/InstallerController.php`
- Modify: `resources/js/pages/install/index.tsx`
- Modify: `routes/web.php`
- Test: `tests/Feature/System/InstallerMigrationTest.php`

**Interfaces:**
- Produces: `InstallerMigrationRunner::status(): array{pending: list<string>, complete: bool}`.
- Produces: `InstallerMigrationRunner::runPending(): array{ran: list<string>, remaining: list<string>, complete: bool}` using Laravel migration services in-process against the accepted production connection.
- Controller mutation runs under `InstallerMutex` and rejects execution when database fingerprint is absent/stale.

- [ ] **Step 1: Write failing migration tests**

Cover: pending list derives from repository state; run does not shell out; only pending migrations execute; simulated interruption after a subset leaves status incomplete; retry completes remaining migrations; changed DB fingerprint invalidates prior completion.

- [ ] **Step 2: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/System/InstallerMigrationTest.php`
Expected: FAIL because migration runner is absent.

- [ ] **Step 3: Implement in-process migration runner**

Resolve Laravel migrator/repository services directly; initialize migration repository when absent; use configured installer DB connection explicitly; sanitize thrown diagnostics at HTTP boundary.

- [ ] **Step 4: Add migration action to wizard**

Expose pending count/list summary and retry action. Never claim complete until `status().complete === true` after the run.

- [ ] **Step 5: Verify focused suite**

Run: `php artisan test tests/Feature/System/InstallerMigrationTest.php tests/Feature/System/InstallerFlowTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

Commit message: `feat: run installer migrations in process`

---

### Task 5: Offline-safe First Owner, Workspace Bootstrap, and Registration Default

**Files:**
- Create: `app/Support/Installer/CreateInitialOwner.php`
- Create: `app/Support/Installer/InstallerPasswordRules.php`
- Modify: `config/fortify.php`
- Modify: `config/installer.php`
- Modify: `app/Models/User.php` only if a relationship/accessor is required by tests
- Modify: `app/Http/Controllers/InstallerController.php`
- Modify: `resources/js/pages/install/index.tsx`
- Modify: `routes/web.php`
- Modify: `.env.example`
- Test: `tests/Feature/System/InstallerOwnerTest.php`
- Test: `tests/Feature/Auth/RegistrationTest.php`

**Interfaces:**
- Produces: `CreateInitialOwner::create(array $input): User` under DB transaction; creates one `Workspace` and one `Membership` with `WorkspaceRole::Owner` when bootstrap is fresh.
- Produces: `InstallerPasswordRules::rules(): array` with local structural policy equivalent to production strength (min 12, mixed case, letters, numbers, symbols) but no `uncompromised()` lookup.
- `config('installer.public_registration')` / env `SELF_HOST_REGISTRATION=false` controls whether Fortify registration feature is enabled after install.

- [ ] **Step 1: Write owner bootstrap RED tests**

Cover: exactly one user/workspace/owner membership; owner `email_verified_at` set; password hashed; duplicate identical submission resolves same owner; changed duplicate input after user exists fails safely; any unrelated pre-existing user stops bootstrap; no password/journal leakage.

- [ ] **Step 2: Write registration default RED test**

Assert installed self-host default returns 404/disabled registration while explicit opt-in config enables existing registration behavior.

- [ ] **Step 3: Run RED**

Run: `php artisan test tests/Feature/System/InstallerOwnerTest.php tests/Feature/Auth/RegistrationTest.php`
Expected: FAIL on missing bootstrap/config behavior.

- [ ] **Step 4: Implement owner service and local password policy**

Normalize email consistently with Fortify login, serialize through `InstallerMutex`, and use a DB transaction with deterministic duplicate recovery.

- [ ] **Step 5: Connect owner endpoint/UI and registration flag**

Owner phase is inaccessible until migrations are complete. Password and confirmation are never re-rendered.

- [ ] **Step 6: Verify focused/auth suite**

Run: `php artisan test tests/Feature/System/InstallerOwnerTest.php tests/Feature/Auth`
Expected: PASS.

- [ ] **Step 7: Commit**

Commit message: `feat: bootstrap self-host owner account`

---

### Task 6: Scheduler Heartbeat, Queue Probe, and Shared-host Operations State

**Files:**
- Create: `app/Support/Operations/OperationalHealth.php`
- Create: `app/Support/Operations/SchedulerHeartbeat.php`
- Create: `app/Support/Operations/QueueProbe.php`
- Create: `app/Jobs/RecordQueueProbe.php`
- Modify: `routes/console.php`
- Modify: `app/Support/SystemHealthChecker.php`
- Modify: `app/Http/Controllers/InstallerController.php`
- Modify: `resources/js/pages/install/index.tsx`
- Modify: `routes/web.php`
- Test: `tests/Feature/System/OperationalHealthTest.php`
- Test: `tests/Feature/System/InstallerOperationsTest.php`

**Interfaces:**
- Produces: `SchedulerHeartbeat::record(): void` and `SchedulerHeartbeat::status(): array{state: 'verified'|'warning'|'fail', observed_at: ?string}` using local file state.
- Produces: `QueueProbe::dispatch(): string`, `QueueProbe::status(string $id): array{state: 'verified'|'warning'|'fail', completed_at: ?string}`; probe token is random/non-secret and expires.
- Produces: `OperationalHealth::snapshot(): array{scheduler: array, queue: array, instructions: array}` consumed by installer and post-install System Health.

- [ ] **Step 1: Write operational health RED tests**

Cover: config alone is warning, fresh heartbeat is verified, stale heartbeat warning, unwritable heartbeat path fail; queue dispatch without worker warning, completed probe verified, demonstrable dispatch/storage failure fail; no external network use.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/System/OperationalHealthTest.php tests/Feature/System/InstallerOperationsTest.php`
Expected: FAIL on missing services.

- [ ] **Step 3: Implement heartbeat/probe primitives and schedule**

Register heartbeat on every-minute scheduler. Queue probe records completion locally/database-safely without user data.

- [ ] **Step 4: Connect operations phase**

Show copyable scheduler/worker commands derived from PHP/artisan paths; record explicit acknowledgement only for non-blocking `warning`. `fail` cannot be acknowledged into success.

- [ ] **Step 5: Align SystemHealthChecker**

Replace configuration-only scheduler/queue success claims with the same operational semantics after install.

- [ ] **Step 6: Verify focused suite and frontend**

Run: `php artisan test tests/Feature/System/OperationalHealthTest.php tests/Feature/System/InstallerOperationsTest.php tests/Feature/System/SystemHealthCheckerTest.php`
Run: `php artisan wayfinder:generate --with-form && npm run check && npm run types:check && npm run build`
Expected: PASS.

- [ ] **Step 7: Commit**

Commit message: `feat: verify installer background operations`

---

### Task 7: Finalization, Authentication Handoff, and Route Lockdown

**Files:**
- Create: `app/Support/Installer/FinalizeInstallation.php`
- Modify: `app/Support/InstallationState.php`
- Modify: `app/Http/Middleware/EnsureApplicationInstalled.php`
- Modify: `app/Http/Controllers/InstallerController.php`
- Modify: `resources/js/pages/install/index.tsx`
- Modify: `routes/web.php`
- Test: `tests/Feature/System/InstallerFinalizationTest.php`
- Test: `tests/Feature/System/InstallerFlowTest.php`

**Interfaces:**
- Produces: `FinalizeInstallation::finalize(Request $request): User` which revalidates all mandatory invariants under `InstallerMutex`, writes `.installed` last, cleans transient artifacts, authenticates owner with web guard, and returns the owner for redirect flow.
- Final lock payload includes installer schema version, product version, installed timestamp, and random non-secret installation identifier.

- [ ] **Step 1: Write finalization RED tests**

One test per missing invariant: failed preflight, env/key mismatch, DB fingerprint mismatch, pending migrations, invalid/missing owner state, operations fail, unacknowledged warning. Assert no lock in every case.

- [ ] **Step 2: Add success/interruption RED tests**

Assert successful finalization writes lock last, logs owner in, redirects into app; simulated lock write failure does not report success; duplicate finalization redirects safely; every installer GET/POST route is inaccessible after installed.

- [ ] **Step 3: Run RED**

Run: `php artisan test tests/Feature/System/InstallerFinalizationTest.php tests/Feature/System/InstallerFlowTest.php`
Expected: FAIL until finalizer exists.

- [ ] **Step 4: Implement finalizer and route lockdown**

Re-read all current invariants rather than trusting journal completion markers. Never require replaying owner password for login handoff.

- [ ] **Step 5: Verify focused suite**

Run: `php artisan test tests/Feature/System/InstallerFinalizationTest.php tests/Feature/System/InstallerFlowTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

Commit message: `feat: finalize and lock self-host installation`

---

### Task 8: Connected Acceptance, MariaDB Gate, Release-tree Installer, and Documentation

**Files:**
- Create: `tests/Feature/System/SelfHostInstallerAcceptanceTest.php`
- Create or extend: `tests/Support` installer/MariaDB helpers only if needed
- Modify: `.github/workflows/ci.yml`
- Modify: `scripts/release/build-release.php` only if packaged installer prerequisites are missing
- Modify: `docs/release-readiness/self-host-installer.md`
- Modify: `docs/DELIVERY_PLAN.md`
- Modify: `docs/HANDOFF.md`
- Modify: `docs/PROJECT_STATE.md`

**Interfaces:**
- Acceptance flow: clean uninstalled release state -> preflight -> environment -> real DB verification -> migrations -> owner/workspace -> operations verified or explicitly acknowledged warning -> finalize -> authenticated normal app -> installer locked.
- CI connected job uses MariaDB 10.11 and the same app code/services; no SQLite-only claim counts as release acceptance.

- [ ] **Step 1: Write end-to-end acceptance test and observe RED where infrastructure/flow is incomplete**

Cover refresh/retry after every phase and a simulated interruption between migration/bootstrap/finalization actions.

- [ ] **Step 2: Add MariaDB 10.11 CI service/gate**

Run connected installer acceptance against MariaDB with production-like DB driver. Do not weaken the existing SQLite fast suite.

- [ ] **Step 3: Add release-tree installer smoke**

From the no-dev release tree, prove `/install` application boot/preflight path works with no Node/npm/Composer runtime dependency. Assert required installer classes/assets are included.

- [ ] **Step 4: Run full local/CI-equivalent verification**

Run:
- `composer validate --strict --no-check-publish`
- `composer lint:check`
- `composer types:check`
- `php artisan test`
- `php artisan wayfinder:generate --with-form`
- `npm run check`
- `npm run types:check`
- `npm run build`
- runtime npm and Composer audits
- release-tree build + smoke
Expected: all PASS.

- [ ] **Step 5: Update release-readiness and project baton docs**

Record exact test counts, MariaDB evidence, final CI run, unresolved lifecycle items. Mark Installer sub-project complete only after connected acceptance; next lifecycle sub-project becomes Persian Export, followed by Backup/Restore, then Update/Recovery.

- [ ] **Step 6: Commit**

Commit message: `test: verify self-host installer lifecycle`

- [ ] **Step 7: Whole-branch review**

Review specifically for secret leakage, project/auth boundary regressions, external-network assumptions, non-idempotent retries, shell dependencies, dead UI, and false operational-health claims. Fix findings with focused RED tests and separate commits.

- [ ] **Step 8: Final-head permanent CI**

Require a fresh permanent CI run on the exact final branch SHA. Cancelled/stale runs do not count.

- [ ] **Step 9: Merge only after exact-head CI success**

Open/update one canonical PR `feature/self-host-installer -> main`; merge with expected-head protection only after exact SHA is green and review findings are closed.
