# Self-host Installer Release Readiness

Last checkpoint: 2026-10-22

## Current status

The browser-driven self-host installer has completed implementation Tasks 1–8 on
`feature/self-host-installer`. The implementation and connected acceptance boundary
is complete; PR #4 still requires one fresh permanent CI run on the final
documentation-complete branch head before merge.

Implemented boundaries include:

- filesystem-backed installer journal and exclusive mutation mutex;
- database-independent host preflight;
- atomic `.env` persistence with non-secret browser state;
- MySQL/MariaDB verification with sanitized failure codes and database fingerprint;
- resumable in-process Laravel migrations without shell execution;
- exactly-once initial owner/workspace bootstrap with an offline-safe password policy;
- public registration disabled by default for installed self-host deployments;
- scheduler heartbeat and real database-queue probe evidence with shared-host cron instructions;
- explicit warning acknowledgement without allowing blocking operational failures to pass;
- final revalidation of host, persistent APP_KEY, database identity, migrations,
  owner membership and operational evidence before the install lock is written;
- `.installed` written only as the final mandatory state transition, followed by
  best-effort transient installer cleanup;
- persistent-session owner login handoff and installer route lockdown after success;
- Persian-first environment, migration, owner, operations and finalization UI;
- endpoint-scoped installer throttling so normal multi-step installation cannot
  exhaust one shared request bucket while state-changing endpoints remain rate-limited;
- permanent MariaDB 10.11 connected acceptance in CI;
- runtime-only release-tree HTTP smoke that opens `/install` without Node, npm,
  Composer, tests, dev Composer packages or a pre-existing `.env` on the host.

## Connected acceptance evidence

Permanent CI run `39859189369` passed on exact implementation head
`75772ff85cc1eda7da8330af43dc7d89161c8954` after the temporary diagnostic workflow
was removed.

Evidence from that run:

- `self-host-installer-mariadb`: **success** against MariaDB 10.11;
- PHP 8.4 compatibility: **success**;
- PHP 8.5 compatibility: **success**;
- frontend formatter/lint: **success** — 98 files formatted, 82 linted;
- TypeScript: **success**;
- production frontend build: **success**;
- Pint: **success** — 254 files;
- PHPStan/Larastan: **success** — 186 analyzed files, zero errors;
- normal backend suite: **310 passed, 1 connected-only skip, 1853 assertions**;
- runtime npm audit: **0 vulnerabilities**;
- runtime Composer audit: **no security vulnerability advisories**;
- runtime-only Composer install: **success**;
- release package build: **success**;
- release-tree HTTP installer smoke: **success** — `/install` opened from the
  runtime-only package with no `.env`, Node/npm, test tree or development Composer packages.

The connected acceptance test itself covers clean uninstalled state, browser
refresh/retry, repeated environment persistence, real database verification,
real migrations, repeated owner submission after simulated response loss,
shared-host operational warning acknowledgement, finalization, authenticated
normal application entry, installation lock metadata, installer lockdown and
safe duplicate-finalize behavior.

## Defects found by connected acceptance

The first MariaDB diagnostic run (`39857672583`) found a real installer UX/safety
bug: every installer POST shared the same generic `6/min` throttle bucket, so a
normal installation could reach HTTP 429 before finalization. The permanent fix
uses a named installer limiter scoped by client IP and endpoint path. This keeps
state-changing routes rate-limited while preventing one legitimate phase from
consuming another phase's quota. The connected MariaDB gate passed after the fix.

## Whole-branch review

The installer branch was reviewed for secret leakage, project/auth boundary
regressions, external-network assumptions, retry/idempotency, stale evidence,
route lockdown, shell/runtime dependencies, dead UI and false operational-health
claims. No additional Critical or Important finding remained after the throttle
regression was fixed.

Relevant invariants remain pinned by tests:

- database passwords are persisted only where required in `.env`; journal and lock
  state are non-secret and allow-listed;
- database errors returned to the browser are classified and sanitized;
- owner bootstrap is exactly-once for the supported response-loss retry shape;
- migration completion is derived from the real migration repository;
- scheduler/queue are never labelled verified without observed evidence;
- blocking operational failures cannot be acknowledged away;
- finalization revalidates current truth and writes the install lock last;
- all installer routes are inaccessible after the installed lock exists;
- installer owner password validation does not require an external breach API;
- production registration remains closed unless the operator explicitly enables it.

## Remaining merge gate

The only remaining Installer sub-project gate is a fresh permanent CI run on the
final documentation-complete branch SHA. Older successful runs are evidence, not a
substitute for exact-head verification. PR #4 may merge only after all permanent
jobs on that exact SHA succeed.

After merge, the self-host lifecycle roadmap continues with Persian Export, then
Backup/Restore, then Update + interrupted recovery/rollback. Site crawler, Search
Console, AI and other feature expansion remain frozen until that lifecycle release
gate is trustworthy.
