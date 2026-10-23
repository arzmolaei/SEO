# SEO OS — handoff to the next implementation agent

Checkpoint: 2026-10-23. Repository evidence and current CI always override stale chat context. This handoff records the current verified boundary; it does not mean the full self-host lifecycle is complete.

## Start here

Read `AGENTS.md`, `docs/PROJECT_STATE.md`, `docs/DELIVERY_PLAN.md`, release-readiness reports and the active subsystem spec/plan. Fetch actual `main`, any active feature branch/PR and latest CI before changing code.

- Repository: `arzmolaei/SEO`.
- Foundation + Keyword Core: merged through PR #1.
- Keyword Intelligence: merged through PR #2.
- Self-host Installer: merged through PR #4.
- Persian Export: merged through PR #5 at `a6924d77b3a9b7f74b484f7e303c8168905c520c`.
- Backup + Restore: merged through PR #7 at `761aede4927dd21ba6384b4d714cc80f2292019e`.
- Backup + Restore post-merge `main` CI: `39978951778`, success across all six permanent jobs.
- Next subsystem: **Update + Interrupted Recovery/Rollback**.

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

## Exact next actions — Update + Interrupted Recovery/Rollback

1. Audit existing lifecycle/release/package/migration/health primitives on current `main`; do not create parallel abstractions.
2. Run mandatory brainstorming/architecture for Update/Rollback using the integrated Backup/Restore safety model.
3. Write and review an Update/Rollback design spec before product code.
4. Write and review an implementation plan with explicit RED -> GREEN tasks.
5. Implement on one feature branch with permanent PHP 8.4/8.5, connected MariaDB and runtime release-tree gates.
6. Require exact-head PR CI, expected-head merge and independent post-merge `main` CI before calling Update/Rollback integrated.

The design must cover at minimum:

- source/target ProductVersion compatibility and release metadata;
- mandatory verified pre-update backup/snapshot;
- global lifecycle serialization;
- safe release staging/switching without runtime Composer/Node/npm/shell assumptions;
- migration ordering and compatibility;
- durable update journal phases;
- interruption semantics before and after destructive boundaries;
- health validation after code/schema transition;
- rollback of code/data/settings where safe;
- exact behavior when automatic rollback cannot prove a safe result;
- session/cache/queue effects;
- operator-visible Persian-first status/recovery actions;
- connected MariaDB acceptance and packaged-runtime acceptance.

## After Update/Rollback

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
