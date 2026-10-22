# Self-host Installer Release Readiness

Last checkpoint: 2026-10-22

## Current status

The browser-driven self-host installer has completed implementation Tasks 1–7 on
`feature/self-host-installer`. Task 8 connected release acceptance remains open and
must pass before the installer sub-project is called complete or merged.

Implemented boundaries now include:

- filesystem-backed installer journal and exclusive mutation mutex;
- database-independent host preflight;
- atomic `.env` persistence with non-secret browser state;
- MySQL/MariaDB verification with sanitized failure codes and database fingerprint;
- resumable in-process Laravel migrations without shell execution;
- exactly-once initial owner/workspace bootstrap with offline-safe password policy;
- public registration disabled by default for installed self-host deployments;
- scheduler heartbeat and real database-queue probe evidence with shared-host cron instructions;
- explicit warning acknowledgement without allowing blocking operational failures to pass;
- final revalidation of host, persistent APP_KEY, database identity, migrations,
  owner membership and operational evidence before the install lock is written;
- `.installed` written only as the final mandatory state transition, followed by
  best-effort transient installer cleanup;
- persistent-session owner login handoff and installer route lockdown after success;
- a real Persian-first finalization UI rather than a dead completion placeholder.

## Latest focused evidence

Task 7 RED was observed on `6437ead448d65195cad75d0b93c0523196e302eb`:
297 existing tests passed while 13 new finalization/owner assertions failed only
because finalization and owner-onboarding behavior did not yet exist.

The finalization HTTP wiring was then verified with Pint plus the focused Owner and
Finalization feature suites before commit `91b44a14a5bdc12dab8600ee895847930d694dbd`.
The finalization UI was separately verified with Wayfinder generation, formatter,
frontend check and TypeScript before bot commit
`d9e7c4617528d669c2888ba3b3319381b822ae46`.

A fresh permanent CI run on a human/connector-authored exact head is still required
because GitHub marked the PR workflow for the bot-authored UI commit as
`action_required`. That state is not treated as success or failure evidence.

## Remaining Task 8 gates

- connected end-to-end installer acceptance with refresh/retry/interruption coverage;
- MariaDB 10.11 CI service and connected installer gate;
- runtime-only release-tree installer boot/preflight smoke with no Composer/Node/npm requirement on host;
- full formatter/static-analysis/backend/frontend/audit/release-tree permanent CI;
- whole-branch review for secret leakage, retry/idempotency, route lockdown, external-network assumptions and dead UI;
- exact final branch SHA permanent CI success before PR #4 can merge.

After this installer gate is complete, the lifecycle roadmap remains Persian Export,
then Backup/Restore, then Update + interrupted recovery/rollback. Site crawler,
Search Console, AI and other feature expansion remain frozen until that lifecycle
release gate is trustworthy.
