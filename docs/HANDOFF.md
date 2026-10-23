# SEO OS — handoff to the next implementation agent

Checkpoint: 2026-10-23. Repository evidence and current CI always override stale chat context. This handoff records the current verified boundary; it does not mean the full self-host lifecycle is complete.

## Start here

Read `AGENTS.md`, `docs/PROJECT_STATE.md`, `docs/DELIVERY_PLAN.md`, the release-readiness reports and the active subsystem spec/plan. Fetch actual `main`, feature head, PR status and latest CI before changing code.

- Repository: `arzmolaei/SEO`.
- Current stable `main` at this checkpoint: `fabf2bf02d18c4e14f4aa97b6ab819da8620b174`; CI `39913391652` success.
- Foundation + Keyword Core: merged through PR #1.
- Keyword Intelligence: merged through PR #2.
- Self-host Installer: merged through PR #4.
- Persian Export: merged through PR #5 at merge commit `a6924d77b3a9b7f74b484f7e303c8168905c520c`.
- Active subsystem: Backup + Restore on `feature/backup-restore`, PR #7.
- Verified implementation head before final docs: `35ee01a108ef5a9df31fce829378756e850e8565`.
- Permanent implementation CI: `39976140240`, success across `verify`, PHP 8.4, PHP 8.5, Installer MariaDB, Persian Export MariaDB and Backup/Restore MariaDB.
- Main reconciliation parent on feature branch: `0f857c313dfe5256764bbd55f33e562e9576db66`.

Do not restart Backup/Restore Tasks 1–10. The implementation exists and permanent connected acceptance is green. Resume from the final review/documentation/merge boundary.

## Backup + Restore completed implementation boundary

### Lifecycle primitives and identity

- Root `VERSION` is required in distributable release trees and non-`dev` production identity is enforced.
- `.installed` supports durable system-owner/provenance metadata while preserving destination-local `installation_id` and DB fingerprint.
- `SystemOwner` resolves/persists the installer-created owner and fails closed for ambiguous legacy ownership.
- `LifecycleMutex` is the shared serialization primitive for Backup/Restore and future Update/Rollback.
- `MaintenanceWindow` provides quiescence without clearing unrelated administrator maintenance state.

### Backup contract

- Bundle schema is `seo-backup/1` with application-owned deterministic framing and SHA-256 verification.
- Durable DB scope is an explicit 19-table allowlist with verified parent-first insert and reverse child-first delete order.
- Volatile framework/runtime tables are excluded.
- APP_KEY and allowlisted durable runtime settings are included because encrypted durable values must remain usable after restore.
- DB credentials, APP_URL, mail/storage/external credentials and unrelated environment values are excluded.
- Durable private/public upload roots are allowlisted; symlinks/path traversal/control paths fail closed.
- System-owner download runs through a private/no-store completed artifact and removes temporary storage after the response lifecycle.

### Restore and recovery contract

- Upload is private and bounded.
- Full bytes, paths, entry hashes, content digest, ProductVersion, schema fingerprint and durable catalog compatibility are verified before mutation.
- Restore only runs against a clean compatible installed target.
- Confirmation is tied to the exact previously verified digest.
- A protected safety snapshot is captured before destructive mutation while the lifecycle lock is already owned.
- DB durable rows restore transactionally with FK enforcement intact.
- Durable files and allowlisted settings are restored while destination DB identity/secrets and local installation identity remain preserved.
- Volatile session/cache/queue/password-reset state is cleared, not restored.
- Any failure after destructive DB commit enters `recovery_required`; no new normal lifecycle operation may proceed until recovery restores the exact pre-restore clean bootstrap state.
- Successful restore and recovery require fresh login.

### Persian-first browser UI

- Owner-only `پشتیبان‌گیری و بازیابی` settings navigation/page.
- Strong sensitive-backup warning.
- Download, private upload/verify, source preview, exact digest confirmation and recovery action.
- Status is server-derived from journal phase; no fake percent progress.
- Raw APP_KEY, credentials, server paths and restore internals are not exposed in page props.

### Permanent connected acceptance

CI `39976140240` on `35ee01a108ef5a9df31fce829378756e850e8565` is the verified implementation evidence. Dedicated MariaDB 10.11 acceptance uses separate source/destination databases and covers representative durable tables, Persian + ZWNJ data, users/password hashes/passkeys/memberships/project isolation, uploads, source APP_KEY usability, destination DB credential + installation identity preservation, volatile-state exclusion, second-restore rejection and injected post-DB-commit failure followed by exact safety recovery and artifact cleanup.

The same exact head also passed frontend formatter/lint, TypeScript, production build, Pint, PHPStan/Larastan, backend tests, dependency audits, runtime-only Composer install and release-tree smoke.

## Why `main` was reconciled into the feature branch

PR #7 was one commit behind `main`. That `main` commit intentionally removed an early Backup/Restore design draft from stable `main`; the reviewed final design belongs on the feature branch and is explicitly required by the feature merge boundary. The reconciliation commit `0f857c313dfe5256764bbd55f33e562e9576db66` makes current `main` an ancestor while retaining the final reviewed feature design.

Never resolve this by deleting the reviewed feature design or force-pushing history.

## Exact next actions at this handoff

1. Confirm final documentation/release evidence is committed on the actual PR head.
2. Perform/confirm whole-branch review with no open Critical/Important findings.
3. Require fresh permanent exact-head PR CI across all six jobs.
4. Merge PR #7 using expected-head protection only after the exact head is green.
5. Require independent post-merge `main` CI.
6. Update `docs/PROJECT_STATE.md` on `main` with the merged SHA and post-merge CI evidence.
7. Then start **Update + Interrupted Recovery/Rollback** as the next lifecycle subsystem.

## After Backup + Restore integration

Update + Interrupted Recovery/Rollback must reuse the existing lifecycle stack rather than creating parallel mechanisms:

- `ProductVersion` for compatibility;
- `LifecycleMutex` for serialization;
- automatic backup/snapshot primitives for mandatory pre-update safety;
- explicit journaled phases;
- health validation after migrations/code switch;
- fail-closed interrupted update recovery and rollback on real fixtures.

After Update/Rollback, complete final end-to-end lifecycle acceptance:

clean runtime package -> browser install -> real project/import/intelligence workflow -> Persian Export -> Backup/Restore -> Update/Recovery/Rollback -> operational health.

Do not advance into crawler/Site Intelligence, Search Console, SERP, AI or vector work before this first usable self-host lifecycle gate is deliberately complete.

## Invariants to preserve

- Core lifecycle works without Composer, Node/npm, SSH, shell commands or international APIs on the production host.
- Backup bundles are secrets: they contain durable instance state and APP_KEY.
- No backup/restore temp or recovery artifact is public-web-addressable.
- Destination DB credentials and installation identity survive restore unchanged.
- Project-scoped authorization and explicit human SEO decisions remain intact.
- Any future durable-file mutator acquires the shared lifecycle mutex.
- `.env`, credentials, private font binaries and local fixtures stay out of public Git/release artifacts.

## Engineering protocol

Use RED -> correct RED -> minimal GREEN -> meaningful focused verification -> semantic commit/push -> exact-head permanent CI. Diagnose failures before changes. Never force-push, weaken gates, remove valid regressions or infer success from an older run. If execution is interrupted, record the exact verified SHA/CI and first unfinished boundary in Git; the next session resumes from repository evidence instead of rebuilding completed work.
