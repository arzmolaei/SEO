# SEO OS — handoff to the next implementation agent

Checkpoint: 2026-10-22. Repository evidence and current CI always override stale
chat context. This handoff records the current verified implementation boundary;
it does not mean the full self-host lifecycle is complete.

## Start here

Read `AGENTS.md`, `docs/PROJECT_STATE.md`, the release-readiness reports,
`docs/DELIVERY_PLAN.md`, the master product spec and the active subsystem
spec/plan. Fetch origin and inspect actual `main`, active branch heads and latest CI
before changing code.

- Repository: `arzmolaei/SEO`.
- Foundation + Keyword Core: completed and merged through PR #1.
- Keyword Intelligence Tasks 1–11: completed and merged through PR #2.
- Current stable `main` after Keyword Intelligence:
  `95bb5a014834590c30ff2562615b050b71c997f0`.
- Self-host Installer Tasks 1–8: implementation, connected acceptance and review
  completed on `feature/self-host-installer` through PR #4.
- Installer implementation evidence before final documentation:
  `75772ff85cc1eda7da8330af43dc7d89161c8954`, CI `39859189369`, success.
- PR #4 still requires a fresh permanent CI success on the final
  documentation-complete head before merge.

Do not create a duplicate installer PR or restart completed installer tasks.

## Completed self-host Installer boundary

The installer is now a browser-driven, resumable and offline-capable host bootstrap,
not the old health-check-plus-lock placeholder.

### Preflight and environment

- Preflight is database-independent and checks the actual host/runtime floor.
- Runtime floor is PHP 8.4.1+.
- Required local extensions, writable paths, disk, assets, APP_KEY and runtime files
  are checked before DB setup.
- A missing APP_KEY gets a protected installer runtime key.
- Browser-managed environment persistence writes production settings atomically and
  carries that key into durable `.env`.
- Unknown environment values are preserved; DB passwords are never returned to the
  browser or installer journal.

### Database and migrations

- Initial production DB support is MySQL/MariaDB.
- Database verification performs a real capability probe and returns stable,
  sanitized error codes rather than raw credential-bearing exceptions.
- Verified identity is represented by a non-secret fingerprint.
- Migrations use Laravel services in-process, not a shell command.
- Pending state is derived from the real migration repository; interruption and
  retry run only remaining work.

### First owner and authentication

- Installer creates exactly one initial owner, workspace and owner membership.
- The owner is verified and onboarded during bootstrap so successful finalization
  enters the real dashboard rather than a placeholder onboarding page.
- Lost-response identical retries are idempotent; changed duplicate identity is
  rejected safely.
- Installer password rules are strong but local/offline-safe.
- Public registration is disabled by default for installed self-host deployments.

### Queue and scheduler activation

- Scheduler health uses an observed local heartbeat.
- Queue health uses a real database queue probe job.
- Persian UI shows exact shared-host scheduler and bounded queue worker cron
  commands.
- Missing external execution evidence is a warning that requires explicit operator
  acknowledgement; a demonstrable failure is blocking and cannot be acknowledged
  away.
- The installer no longer claims queue/scheduler health from configuration alone.

### Finalization and lock

- Finalization revalidates current preflight, persistent APP_KEY, database identity,
  migrations, owner membership and operational evidence.
- `.installed` is written last and contains only versioned non-secret metadata.
- Failed lock write leaves the installer recoverable and does not authenticate the
  owner.
- Successful finalization uses a persistent database session, logs the owner in,
  clears transient installer state and redirects to the dashboard.
- After the install lock exists, every installer route is inaccessible.

### Connected release acceptance

Permanent CI run `39859189369` on
`75772ff85cc1eda7da8330af43dc7d89161c8954` passed:

- MariaDB 10.11 connected installer acceptance;
- PHP 8.4 and 8.5 compatibility;
- frontend formatter/lint, TypeScript and production build;
- Pint and PHPStan/Larastan;
- normal backend suite: **310 passed, 1 connected-only skip, 1853 assertions**;
- runtime npm and Composer audits;
- runtime-only Composer install;
- release-tree build and actual HTTP `/install` smoke with no `.env`, Node/npm,
  tests or development Composer packages on the runtime tree.

The connected flow covers clean install, refresh/retry, real DB verification,
real migrations, owner retry after simulated response loss, operational warning
acknowledgement, finalization, login, dashboard entry, lock metadata and installer
lockdown.

A first MariaDB diagnostic found one genuine bug: all installer POSTs shared a
single `6/min` bucket and a legitimate install could hit HTTP 429. The final design
uses a named limiter scoped by IP plus endpoint path; connected acceptance passed
after that fix. The temporary diagnostic workflow was removed.

Detailed evidence: `docs/release-readiness/self-host-installer.md`.

## Critical invariants that carry forward

- Preserve original keyword text, provenance/metric history and explicit human
  intent/cluster/mapping decisions.
- Every project resource query/mutation remains project-scoped and authorized.
- Automation never recalculates through explicit locks/manual overrides.
- Mapping warnings are reviewable signals, never silent target mutations.
- Ready is derived from current evidence, not a permanent badge.
- Installer journal/lock metadata stays non-secret; secrets belong only in required
  durable configuration such as protected `.env`.
- Finalization never trusts a journal marker in place of current truth.
- Queue/scheduler verification requires observed evidence; true failures block.
- Core self-host workflows remain independent of international API/AI/SERP/CDN
  availability.
- Commercial font binaries, `.env`, secrets and local fixtures stay ignored.

## Exact next lifecycle work after PR #4

After PR #4 receives final-head CI success and is merged, continue the first usable
self-host lifecycle gate in this order:

1. **Persian Export** — real project data, UTF-8/Persian correctness, bounded
   streaming and strict access isolation.
2. **Backup + Restore** — database, uploads and durable settings with a versioned
   checksum manifest; clean restore must verify users, authorization and SEO data.
3. **Update + interrupted recovery/rollback** — compatibility, mandatory backup,
   migrations, health, failure recovery and rollback on real fixtures.
4. **Final lifecycle acceptance** — clean package install -> real project/import/
   intelligence workflow -> export -> backup/restore -> update/recovery ->
   operational health.

Do not move to Site Intelligence/crawler, technical crawling, Search Console,
SERP, AI or vector features until that lifecycle gate is trustworthy.

Persian Export is a genuinely new bounded subsystem. Follow the required
architecture/design/spec review process before implementation rather than silently
skipping those artifact gates.

## Engineering protocol

Use RED -> correct RED -> minimal GREEN -> refactor -> meaningful focused
verification -> permanent final-head CI -> semantic commit/push. Diagnose failures
before changing code. Never force-push, overwrite unexpected history, weaken gates,
remove a valid regression test or infer success from a cancelled/older run. Continue
task-by-task without routine user confirmation.

If execution is externally interrupted, record the last verified SHA/CI and exact
first unfinished behavior in Git. Resume from repository evidence instead of
rebuilding completed work.
