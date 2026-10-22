# Self-host installer — resumable host bootstrap

## Goal

Turn the existing installer foundation into a real browser-driven self-host installation flow for a prebuilt SEO OS release. A user who can upload the release archive to a PHP host must be able to complete first installation without Composer, Node.js, npm, SSH, or shell commands.

The installer is complete only when the host is compatible, application configuration is durably written, the database is reachable and migrated, exactly one initial owner exists, the application can operate with its configured queue/scheduler mode, and the installer is permanently locked from normal web access.

This is the first sub-project in the self-host lifecycle feature freeze. It does not implement exports, backups, restore, updates, rollback, crawler, SERP APIs, AI, or new SEO features.

## Current boundary and why it changes

The current installer already provides useful foundations:

- global redirect to `/install` while the installation lock is absent;
- a runtime application key that can exist before `.env` is ready;
- file-backed installer session/cache and sync queue so installer requests do not depend on application database tables;
- host health reporting;
- a final installation lock that prevents reopening the installer after completion.

However, it currently assumes the database and runtime configuration already exist and `POST /install/complete` only writes the final lock. That is not a complete self-host install experience. Database configuration, durable environment configuration, migrations, first-owner creation, and operational activation must move inside a staged and retry-safe installer.

## Design principles

1. **Finalize last.** `.installed` is never written before every mandatory phase has succeeded.
2. **Retry is normal.** Every phase is safe to submit again after timeout, browser refresh, process restart, or duplicate request.
3. **Secrets are not installer state.** Database passwords and other secrets are never written to the installer journal, activity logs, exception context, or browser response after submission.
4. **Installer runtime stays database-independent until the database phase succeeds.** Session, cache, and queue remain file/file/sync for installer routes.
5. **No shell dependency.** Migrations and bootstrap operations use application APIs/services, never `exec`, shelling out to Artisan, Composer, Node, npm, or cron commands.
6. **Host-first compatibility.** Preflight can succeed before any database configuration exists.
7. **One installation at a time.** State-changing installer operations are serialized with a filesystem lock that does not require the database.
8. **No silent success.** Queue/scheduler readiness is reported as verified, warning, or unresolved based on observable evidence; configuration text alone is not treated as proof that background execution is alive.
9. **Secure-by-default self-hosting.** Public registration is disabled by default after the initial owner is created unless the administrator deliberately enables it later.
10. **Core remains local/offline-capable.** No foreign API or internet dependency is introduced into installation.

## Installation state machine

The installer exposes a single wizard whose current phase is derived from durable facts plus a small non-secret journal. The journal is stored under `storage/app/installer/` and contains only phase metadata, timestamps, non-secret fingerprints, and recoverable diagnostic state.

Canonical phases:

1. `preflight`
2. `environment`
3. `database`
4. `migrations`
5. `owner`
6. `operations`
7. `finalize`
8. `installed`

A phase is considered complete only when its underlying invariant is true; the journal is not trusted as the sole source of truth. For example, `migrations` is complete because Laravel reports no pending application migrations against the configured database, not merely because the journal says it once ran.

If a later invariant becomes false before finalization, the wizard moves back to the earliest invalid mandatory phase. A refresh never skips a required phase because of stale client state.

The final `.installed` file remains the authoritative public-web lock. Its payload is upgraded to include installer schema version, product version, installed timestamp, and a non-secret installation identifier suitable for future backup/update compatibility checks.

## Phase 1 — host preflight

Preflight checks only facts that can be evaluated before database credentials exist:

- PHP `>= 8.4.1`;
- required non-database PHP extensions;
- writable `storage` and `bootstrap/cache` paths;
- at least 128 MiB free disk space when measurable;
- production frontend assets are present;
- a runtime application key can be loaded or created;
- required application files exist and are readable.

Database driver extensions are checked after the user selects a database driver. Database reachability, queue persistence, and scheduler heartbeat do not make preflight fail because they are later phases.

Warnings remain visible but do not block progression unless the check is explicitly mandatory. Error messages are Persian-first and name the actionable host requirement without exposing filesystem secrets.

## Phase 2 — durable environment configuration

The environment phase collects only settings required to establish the installation safely:

- application URL;
- environment mode fixed to production for packaged self-host releases;
- database driver, initially `mysql`/`mariadb` only for the supported hosting target;
- database host;
- database port;
- database name;
- database username;
- database password;
- queue mode, default `database`;
- cache/session defaults suitable for production after migrations exist.

The database password is accepted once per submission and kept only in request memory until environment persistence finishes. It is never returned to the browser and never stored in the installer journal.

A dedicated environment writer updates a constrained allow-list of keys rather than accepting arbitrary environment names. The writer:

1. reads the existing `.env` when present, otherwise starts from `.env.example` defaults;
2. preserves unrelated existing keys;
3. writes the runtime application key into `APP_KEY` so encrypted application data remains stable after installation;
4. writes to a temporary file in the same directory;
5. flushes and closes the temporary file;
6. creates a private backup of the previous `.env` when replacing one;
7. atomically renames the temporary file over `.env`;
8. applies the most restrictive file permissions reasonably available;
9. clears/reloads runtime configuration without shell commands.

A write failure leaves the old `.env` untouched. A retry with the same values is idempotent.

The installer never displays the persisted database password. Returning to the database form shows non-secret fields and an empty password input; keeping the existing password requires an explicit "keep current secret" path rather than echoing it.

## Phase 3 — database verification

Before migrations, the installer builds a temporary database connection from the submitted/persisted values and verifies:

- the selected PDO driver extension is loaded;
- TCP/socket connection succeeds;
- authentication succeeds;
- the target database exists and is selectable;
- basic read/write transaction capability is available;
- the configured connection points to the same database fingerprint on retry.

The database fingerprint is non-secret and consists of normalized driver, host, port, and database name plus a digest. Username/password are not included in journal text.

The installer does not create MySQL users or databases. Shared hosting users are expected to create those through their hosting control panel. Errors distinguish unreachable host, authentication failure, missing database, missing PDO extension, and insufficient database privileges where the driver exposes enough information to do so safely.

## Phase 4 — migrations

Migrations execute through Laravel's migration services in-process. The installer does not invoke `php artisan migrate` or any shell command.

Requirements:

- use the configured production database connection explicitly;
- acquire the installer filesystem mutex before running;
- inspect pending migrations before execution;
- execute only pending migrations;
- release the lock on success or exception;
- retry after interruption by re-inspecting migration state;
- never mark the phase complete until no application migrations remain pending.

Laravel migrations are treated as forward operations. This installer phase does not attempt schema rollback after a partially completed migration batch; future update rollback belongs to the updater sub-project. The installer instead resumes remaining pending migrations. A migration that is itself non-idempotent and fails after applying an untracked partial schema change is considered a release defect and must fail acceptance before shipping.

Migration exceptions are shown as sanitized operator diagnostics with a correlation identifier. SQL containing credentials or secret values is never exposed in the browser.

## Phase 5 — initial owner

After schema readiness, the installer creates the first administrative user through a dedicated service rather than public registration routes.

Collected fields:

- display name;
- email;
- password;
- password confirmation.

Validation reuses the application's canonical profile/password rules wherever possible. The service runs under the installer mutex and database transaction.

Rules:

- if no users exist, create exactly one owner;
- the initial owner is treated as verified for local first-install bootstrap so the user is not blocked on outbound email availability;
- if a matching installer-created owner already exists because the request completed but the response was lost, retry resolves to that same user rather than creating a duplicate;
- if unrelated users already exist before owner creation, the installer stops and requires explicit recovery rather than guessing which account owns the installation;
- owner creation also creates or assigns the minimum workspace/membership state required for the application's normal post-login flow, following the existing workspace domain rules;
- public Fortify registration is disabled by default for installed self-host instances through an explicit application setting/environment flag, not by deleting registration code.

The password is never stored in installer state or logs.

## Phase 6 — operational activation

The application can technically render without background workers, but a production SEO product must make queue/scheduler health understandable rather than silently pretending configuration equals operation.

The operations phase presents host-specific copyable setup instructions for the supported shared-host baseline:

- cron entry that runs Laravel scheduler once per minute using the host's PHP CLI path;
- database queue worker strategy supported by the product's release model, including a safe periodic worker invocation for shared hosting where persistent process managers are unavailable;
- storage paths and permissions needed by those processes.

The installer distinguishes three states for each subsystem:

- `verified`: direct evidence was observed recently;
- `warning`: configuration is valid but external execution has not yet been observed;
- `fail`: application-side prerequisites are invalid.

Scheduler verification uses a local heartbeat record/file written by an installer-safe scheduled task. Queue verification dispatches a local probe job carrying a random non-secret token and waits/polls for its completion record. Both are entirely local and do not call external services.

For shared hosting, missing recent external execution is a warning rather than a hard blocker when the host cannot execute the cron during the same browser session. The user must explicitly acknowledge the warning before finalization. Invalid queue configuration, unwritable runtime paths, or a probe that demonstrably fails are blockers.

The final System Health screen after installation reuses the same heartbeat/probe semantics so installer claims and production diagnostics cannot disagree.

## Phase 7 — finalization

Finalization revalidates all mandatory invariants under the installer mutex:

- host preflight still passes;
- `.env` is durable and the runtime key matches the persisted key;
- database connection matches the accepted database fingerprint;
- no application migrations are pending;
- exactly one valid initial owner/bootstrap ownership state exists;
- operations phase has no blocking failure and any unverified warnings were explicitly acknowledged.

Only then does it write `.installed` atomically.

After the lock is durable:

- installer journal secrets are already absent by design and temporary probe artifacts are cleaned up;
- the first owner is authenticated using the normal web guard without requiring a password replay;
- the user is redirected into the normal application onboarding/dashboard flow;
- all `/install` routes redirect away on subsequent requests;
- installer mutation endpoints remain inaccessible once installed even if their URLs are posted directly.

If lock writing fails, installation remains resumable and the user is not told installation completed.

## Concurrency, duplicate requests, and interruption

Every state-changing installer endpoint uses the same filesystem-backed exclusive mutex. The mutex lives outside the database and works before database setup.

Duplicate submissions follow these rules:

- environment persistence: same desired values become a no-op; changed values revalidate downstream phases;
- database verification: repeatable read-only verification plus bounded write/rollback capability probe;
- migrations: run only pending migrations;
- owner creation: resolve the previously created bootstrap owner or fail safely on unexpected existing users;
- operations probes: tokenized and independently expiring;
- finalization: if already installed, redirect to the application; otherwise revalidate and attempt lock once.

A request interruption never advances a phase solely because the request started. The next request derives truth again from files/database/probe evidence.

## Security model

- Installer state contains no passwords, session secrets, raw `.env` contents, private keys, or database DSNs containing credentials.
- Sensitive request fields are excluded from application exception context and request logging.
- CSRF protection remains active.
- Installer POST endpoints are rate-limited conservatively without making normal retries painful.
- Installer is unreachable after final lock.
- `.env` backup files live outside the public web root, receive restrictive permissions, and use bounded retention for installer-time backups.
- Environment writes use an allow-list; browser input cannot add arbitrary PHP/Laravel environment keys.
- Application URL is validated as `http` or `https`; production UI warns when not HTTPS but does not invent TLS configuration the host does not control.
- Database host/port/name/user lengths and formats are bounded before connection attempts.
- Database exceptions are classified and sanitized before reaching Inertia props.
- Initial owner email is normalized consistently with Fortify login behavior.

## UI and Persian-first experience

The installer stays a single Persian-first RTL wizard with visible phase navigation and state, not seven unrelated pages.

Each phase shows:

- what is being configured;
- why it is required;
- current verified state;
- actionable failure text;
- a primary next action;
- safe retry when applicable.

Passwords are never re-rendered. Database secrets use password inputs with explicit "keep current password" behavior after persistence. Long host values and commands render LTR where appropriate.

The operations phase provides copy buttons for cron/worker commands and explains which parts may differ by hosting provider. No UI action is presented as complete until its backend capability exists.

## Application/runtime configuration after install

Packaged production defaults after successful installation:

- `APP_ENV=production`;
- `APP_DEBUG=false`;
- persisted `APP_KEY` equals the installer runtime key;
- `DB_CONNECTION=mysql` for the first supported production path;
- `SESSION_DRIVER=database` after migrations;
- `CACHE_STORE=database` after migrations;
- `QUEUE_CONNECTION=database` by default;
- public registration disabled by default;
- Persian locale retained;
- core feature behavior does not require external internet access.

The installer-specific middleware continues forcing file/file/sync only while the application is not installed and the request is inside the installer.

## Failure recovery surfaced to the user

The installer must never collapse failures into a generic 500 page for expected setup errors.

Expected recoverable classes include:

- unwritable application paths;
- invalid application URL;
- missing PDO extension;
- database unreachable/authentication/database-not-found/insufficient privileges;
- `.env` write/rename failure;
- migration failure;
- owner validation failure;
- unexpected pre-existing users;
- queue probe failure;
- scheduler not yet observed;
- final lock write failure.

Each recoverable class returns the user to the owning phase with a stable error code and Persian operator message. Detailed server exceptions stay in logs without secret values.

## Test strategy

Implementation follows TDD. The acceptance boundary is broader than controller unit behavior.

Required automated coverage includes:

- preflight succeeds without a configured database and fails on true host blockers;
- environment writer preserves unrelated keys, never echoes secrets, writes atomically, and leaves the prior file intact on simulated failure;
- runtime key is persisted and stable across simulated process restarts;
- database verification classifies common failures without leaking password values;
- migrations run in-process, only pending migrations execute, and retry after simulated interruption completes correctly;
- duplicate owner submission creates one user only;
- unexpected pre-existing users stop bootstrap;
- registration is disabled by default after install;
- scheduler heartbeat and queue probe produce verified/warning/fail states as designed;
- stale/missing external heartbeat can be explicitly acknowledged but demonstrable application-side queue failure blocks finalization;
- finalization refuses every missing invariant and writes the lock only after all pass;
- after finalization every installer route is inaccessible/redirected;
- a full feature acceptance test performs fresh uninstalled state -> environment -> database -> migrations -> owner -> operations acknowledgment/verification -> finalize -> authenticated application entry;
- MariaDB 10.11 connected acceptance covers real migrations and installer-owned bootstrap data, not SQLite alone;
- release-tree acceptance proves the installer runs from the no-dev prebuilt package without Composer/Node/npm present.

## Release acceptance for this sub-project

This sub-project is done only when all of the following are demonstrated on the final branch head:

1. a clean prebuilt release tree can open `/install` without a prior `.env` or database-backed session/cache;
2. a real MariaDB/MySQL database can be configured from the browser and migrated without shell commands;
3. a first owner can be created once and can enter the normal app after finalization;
4. refresh/retry at every phase does not duplicate migrations/users or lose durable configuration;
5. intentionally interrupted installation resumes from truth rather than stale phase markers;
6. the installer cannot be reopened after the lock is written;
7. queue/scheduler setup and health are visible and never represented as verified without evidence;
8. production registration is closed by default;
9. PHP 8.4 and 8.5 CI, frontend checks, static analysis, backend suite, audits, and release smoke remain green;
10. no Composer, Node.js, npm, SSH, foreign API, or internet access is required on the target host for the installation flow.

## Out of scope

The following explicitly wait for later lifecycle sub-projects:

- Persian project/keyword export;
- database/uploads/settings backup archives;
- restore into a clean installation;
- version manifests and updater UI;
- pre-update backup;
- interrupted/failed update recovery and rollback;
- release download/discovery from the internet;
- crawler, technical SEO, Search Console, SERP APIs, AI, vector search, CMS integrations.

The installer may create interfaces/fingerprints future backup/updater work can consume, but it must not prematurely implement those systems.
