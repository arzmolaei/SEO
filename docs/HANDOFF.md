# SEO OS — handoff to the next implementation agent

Checkpoint: 2026-10-28. Repository evidence and current CI always override stale chat context. This handoff records the current verified boundary; it does not mean the full self-host lifecycle is complete.

## CURRENT STATE — 2026-10-28 (read this first; supersedes older lines below)

**Owner requested a clean pause and handoff. All implementation is committed and
pushed. Do not restart or re-audit completed slices. Fetch actual Git/CI first.**

### Integrated main: PR #21 is merged and verified

- Main merge commit: `f11f3bcb742d2a8cf105e75837aca08c55a006d7`.
- PR #21 final feature head: `9b60cf1bce86a36b21cbece0e97dd9cf51b23045`.
- Exact-head PR runs succeeded: CI `40653417461`, Update Rollback MariaDB
  `40653417463`, Lifecycle E2E `40653417465` (push runs also green).
- Merged through the GitHub API with the exact expected-head SHA parameter.
- Independent post-merge main runs all succeeded: CI `40654060956`,
  Update Rollback MariaDB `40654060905`, Lifecycle E2E `40654060872`.
- GitHub API access now works; the old 403 blocker is resolved.

Security work is integrated: missing/corrupt/symlinked activation state fails
closed, legitimate root activation/rollback writes an explicit pointer, and every
staging attempt extracts verified bytes into a fresh private slot. Launcher ABI is
`seo-launcher/2`; V1 installations require a trusted manual launcher upgrade.
Do not weaken the P1 guarantee or silently mix V1/V2 packages.

The connected failure at the older `085442a5` was reproduced on real MariaDB
10.11: the fixture created a retained target before source snapshot capture but
never activated root. Product activation was already correct. Commit `9b60cf1`
corrects that invalid fixture ordering and proves pointer existence/identity plus
fail-closed deletion after completed and rolled-back updates. All existing data,
schema, environment, identity and queue assertions remain.

### PR #22: import slice implemented; deliberately UNMERGED

- PR: https://github.com/arzmolaei/SEO/pull/22
- Branch: `feature/workspace-entry-keyword-import`, based on current main.
- Rebased implementation head: `988f5136b7a85086470c9aee63205922e2be7f6e`.
  Rebase push used an exact lease against `4a003de4d37e26595d6b868a0272899571d53244`.
  The rebased file tree was identical to the tested pre-rebase tree.
- Only four import/home commits remain above main; security/update commits are
  no longer duplicated in the PR diff.
- At the last API check on `988f513`: CI `40654733362` and rollback
  `40654733348` succeeded; Lifecycle E2E `40654733476` was still running.
- A documentation-only pause checkpoint is being added after that implementation
  head. **Verify CI on the actual final branch HEAD; do not substitute these
  older run results. Keep PR #22 unmerged unless the owner authorizes merging.**

Implemented and locally verified: Persian workspace home/project creation;
private CSV or pasted originals; bounded preview; exact-column mapping; queued
staging and commit; server-truth status; paginated invalid rows; attributable
discard; project-scoped binding/policies; Persian/English import page; home,
overview, keyword-page and sidebar entry points. Legacy JSON staging remains
compatible. Original bytes/whitespace remain separate from derived values;
preview rendering tolerates malformed row bytes; storage ancestors must be
canonical; raw paths/exception messages never enter page props.

Commit markers and the default database queue insert share a transaction.
Database reservations are at least 660 seconds for the existing 600-second jobs.
Caught staging errors are terminal with discard/reupload recovery; hard-killed
workers resume checkpoints. Focused review findings about header trimming,
malformed UTF-8 JSON and discard error rendering were corrected.

### Fresh local validation, including real connected tests

All requested connected suites ran against **real MariaDB 10.11** with
`--fail-on-skipped`, on both the update and import branches, with no skips:

- Rollback + fresh-runtime process recovery: 2 passed, 62 assertions.
- Backup/restore + update migrations + MariaDB-driver snapshot isolation:
  11 passed, 198 assertions.
- Self-host Installer: 1 passed, 125 assertions.
- Persian Export + large export: 2 passed, 110 assertions.

Normal update suite: 625 tests, 609 passed, 16 environment-gated skips,
4,633 assertions. Normal import suite: 645 tests, 629 passed, 16 gated skips,
4,826 assertions. Those ordinary-run skips are distinct from the connected
runs above; never present skipped connected tests as passed. Pint, PHPStan,
frontend check, TypeScript and production build passed on both branches.

The existing browser lifecycle driver also passed locally with Chrome + MariaDB
against a no-dev/classmap-authoritative packaged import runtime. **It still used
the old service seed; real-import UI end-to-end coverage is NOT implemented.**

### Exact first unfinished task: browser user-workflow acceptance

Continue from the import branch after checking its final exact-head CI. Keep #22
import-only/unmerged. Use a separate stacked feature branch for acceptance work.
A local `feature/lifecycle-user-workflow` name was briefly created at `988f513`,
but no browser code, design, or plan changes were written or pushed there.

Extend `tests/Acceptance/Lifecycle/run.mjs` and its existing driver/helpers:

1. Replace `harness.php seed` with browser project creation, CSV upload,
   mapping, real database-worker processing and commit.
2. Exercise intelligence through the UI (review/cluster/page mapping).
3. Download the real Persian CSV from the UI link and validate its content.
4. Download a sensitive backup, install a second clean instance through the
   browser, restore there, and verify data, source APP_KEY usability and retained
   destination database/installation identity.
5. Run the existing update/recovery scenarios on the restored instance and
   prove operational health after successful update, while retaining deliberate
   drift/fail-closed failure scenarios.
6. Review, run the real packaged Chrome/MariaDB acceptance, normal gates and
   exact-head CI; update these existing checkpoint files at the verified boundary.

Do not invent passing browser coverage, weaken assertions, merge temporary
branches, start crawler work, publish the environment, or redo integrated work.
Remaining broader release decisions (framework Persian strings, bilingual 503,
host upload limits/chunking, offline password checking and integrity vs signing)
remain as previously recorded; this session did not implement them.

### Runtime notes for a continuing agent

Use the existing isolated checkout, not a new worktree unless requested.
This cloud snapshot uses `export PATH=/workspace/.php-runtime/bin:$PATH`.
Real MariaDB containers were `seo-connected-source` (localhost:3306) and
`seo-connected-destination` (localhost:3307), image `mariadb:10.11`.
Users/databases and disposable credentials came from the workflow env blocks,
not production. Recreate them if processes/containers did not survive.
Local helper `/workspace/run-connected.py` runs groups rollback/backup/installer/
export and setup from the workflow values; it is outside Git and may not exist
in another task. The authoritative commands and env names are in `ci.yml` and
`update-rollback-mariadb.yml`. Never dump environment or credential values.
Local packaged tree `/workspace/lifecycle-runtime` and
`/workspace/lifecycle-baseline.log` are generated evidence, not Git sources.

## Start here

Read `AGENTS.md`, `docs/PROJECT_STATE.md`, `docs/DELIVERY_PLAN.md`, release-readiness reports and the active subsystem spec/plan. Fetch actual `main`, any active feature branch/PR and latest CI before changing code.

- Repository: `arzmolaei/SEO`.
- Foundation + Keyword Core: merged through PR #1.
- Keyword Intelligence: merged through PR #2.
- Self-host Installer: merged through PR #4.
- Persian Export: merged through PR #5 at `a6924d77b3a9b7f74b484f7e303c8168905c520c`.
- Backup + Restore: merged through PR #7 at `761aede4927dd21ba6384b4d714cc80f2292019e`.
- Backup + Restore post-merge `main` CI: `39978951778`, success across all six permanent jobs.
- Update + Interrupted Recovery/Rollback: merged through PR #8 at `43342c667859d68a2bfe62b1fb2258aafedb6a5c` (2026-10-24); post-merge `CI` run `40074320817` and `Update Rollback MariaDB` run `40074320854` both success.
- Next slice: **Final end-to-end lifecycle acceptance** (plus the open Update/Rollback items listed in `docs/release-readiness/update-recovery-rollback.md`).

Do not restart Backup/Restore Tasks 1–10. They are integrated and independently verified on `main`.

## Backup + Restore integrated boundary

### Lifecycle primitives and identity

- Root `VERSION` is required in distributable release trees and non-`dev` production identity is enforced.
- `.installed` carries durable system-owner/provenance metadata while preserving destination-local installation id and DB fingerprint.
- `SystemOwner` resolves/persists the installer-created owner and fails closed for ambiguous legacy ownership.
- `LifecycleMutex` is the shared serialization primitive for Backup/Restore and future Update/Rollback.
- `MaintenanceWindow` provides lifecycle quiescence without clearing unrelated administrator maintenance state.

### Backup contract

- Bundle schema is `seo-backup/1` with deterministic application-owned framing and SHA-256 verification.
- Durable DB scope is an explicit 19-table allowlist with verified parent-first insert and reverse child-first delete order.
- Volatile framework/runtime tables are excluded.
- APP_KEY and allowlisted durable runtime settings are included; DB credentials, APP_URL, mail/storage/external credentials and unrelated environment values are excluded.
- Durable upload roots are allowlisted; symlinks/path traversal/control paths fail closed.
- System-owner download uses private/no-store response handling and removes temporary storage after response lifecycle.

### Restore and recovery contract

- Upload is private and bounded.
- Full bytes, paths, entry hashes, content digest, ProductVersion, schema fingerprint and durable catalog compatibility are verified before mutation.
- Restore runs only against a clean compatible installed target.
- Confirmation is tied to the exact verified digest.
- A protected safety snapshot is captured before destructive mutation while the lifecycle lock is owned.
- Durable DB state restores transactionally with FK enforcement intact.
- Durable files and allowlisted settings restore while destination DB secrets and local installation identity remain preserved.
- Volatile session/cache/queue/password-reset state is cleared, never restored.
- Any failure after destructive DB commit enters `recovery_required`; normal lifecycle work remains blocked until recovery restores the exact pre-restore clean bootstrap state.
- Successful restore/recovery requires fresh login.

### Browser UI and acceptance

- Owner-only Persian-first `پشتیبان‌گیری و بازیابی` settings experience.
- Strong sensitive-backup warning, download, private upload/verify, source preview, exact digest confirmation and recovery action.
- Status is server-derived; no fake progress percentage or secret/internal props.
- Dedicated MariaDB 10.11 acceptance proves separate source/destination databases, Persian + ZWNJ domain data, auth/membership/project isolation, uploads, source APP_KEY usability, destination DB credential + installation identity preservation, volatile-state exclusion, second-restore rejection, injected post-commit failure, `recovery_required`, exact recovery and artifact cleanup.

Final evidence:

- feature final head: `4c7b8e313902f3524b738634208a105b37b291ce`;
- exact-head PR CI `39978231749`: success;
- merge commit `761aede4927dd21ba6384b4d714cc80f2292019e`;
- post-merge main CI `39978951778`: success.

## Update + Interrupted Recovery/Rollback — integrated boundary

Do not restart Update/Rollback Tasks 1–13. Code, design, plan and the Persian UI are on `main`. Evidence, coverage map and **known gaps** are in `docs/release-readiness/update-recovery-rollback.md`; operator behavior is in `docs/update-recovery-operations.md`.

Open items before Update/Rollback can be called fully release-ready:

1. connected-MariaDB acceptance of the full successful update path;
2. connected-MariaDB acceptance for bootstrap failure, post-migration health failure, hard interruption between target migration and rollback, and drift -> `recovery_required` at whole-update level;
3. a recorded whole-branch review (launcher fail-open, secret leakage, migration guesses, maintenance bypass, stale safety snapshot);
4. release-signing remains out of V1 scope and **NEEDS VERIFICATION before public/commercial automatic distribution**.

Reuse `LifecycleMutex`, `MaintenanceWindow`, the shared snapshot recovery kernel and `ProductVersion`; never add a parallel lifecycle stack.

## Next: Final end-to-end lifecycle acceptance

Complete final end-to-end lifecycle acceptance:

clean runtime package -> browser install -> real project/import/intelligence workflow -> Persian Export -> Backup/Restore -> Update/Recovery/Rollback -> operational health.

Do not advance into crawler/Site Intelligence, Search Console, SERP, AI or vector work before this first usable self-host lifecycle gate is deliberately complete.

## Invariants to preserve

- Core lifecycle works without Composer, Node/npm, SSH, shell commands or international APIs on the production host.
- Backup/safety artifacts are secrets and never public-web-addressable.
- Destination DB credentials and local installation identity survive restore/update recovery unchanged unless an explicit future design says otherwise.
- Project-scoped authorization and explicit human SEO decisions remain intact.
- Any durable-file mutator acquires the shared lifecycle mutex.
- `.env`, credentials, private font binaries and local fixtures stay out of public Git/release artifacts.

## Engineering protocol

Use RED -> correct RED -> minimal GREEN -> focused verification -> semantic commit/push -> exact-head permanent CI. Diagnose failures before changes. Never force-push, weaken gates, remove valid regression tests or infer success from older runs. If execution is interrupted, record the exact verified SHA/CI and first unfinished boundary in Git; the next session resumes from repository evidence instead of rebuilding completed work.
