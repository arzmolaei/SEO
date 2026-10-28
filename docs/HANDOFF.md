# SEO OS — handoff to the next implementation agent

Checkpoint: 2026-10-24. Repository evidence and current CI always override stale chat context. This handoff records the current verified boundary; it does not mean the full self-host lifecycle is complete.

## CURRENT STATE — 2026-10-28 (read this first; supersedes older lines below)

Import UI follow-up (2026-10-28): the existing WIP scaffold is now connected to
scoped/authorized CSV-or-paste upload, bounded preview, exact column mapping,
database-queued staging and commit, paginated invalid rows, and attributable
discard. Persian/English UI, home/overview/keyword CTAs and sidebar entry are
implemented. The original JSON staging endpoint remains supported. Source bytes
stay private and unchanged; derived previews tolerate malformed row encoding,
filesystem ancestors must be canonical, and internal exception/path text is not
sent in props. Database queue insertion and request markers share one transaction;
the reservation interval is at least 660 seconds for 600-second import jobs.
Caught staging errors are terminal and recover by discard/reupload; killed-worker
retries resume checkpoints.

The existing seven flow tests are unchanged in intent and GREEN, with additional
tenant/provenance/queue-failure/encoding/reservation regressions. Independent
focused review findings (exact-header trimming, invalid-byte JSON rendering and
discard error display) were corrected. Local full suite: 645 tests, 629 passed,
16 skipped, 4,822 assertions; Pint/PHPStan/frontend check/TypeScript/build pass.
No MariaDB/browser acceptance pass is claimed locally. The original CI
40643318600 failure cause could not be independently inspected because API
access remains denied; the owner-supplied expected RED description is recorded
as such, not confirmed from logs.

First unverified boundary: exact-head CI for PR #21 security head
`085442a5f863581de671d52640b4ae2a86130521` and the new stacked import head.
Merge #21 only with exact-head protection after all three workflows pass, then
verify main. Validate the import head before integration; preserve its existing
branch history (no force-push). Next implementation slice is browser lifecycle
acceptance: replace service-level seed with the real import UI, then extend
intelligence → Persian export download validation → backup → second-instance
restore → update → operational health. That browser slice is not implemented
by this checkpoint. No crawler work should begin yet.

Security review follow-up (2026-10-28): the bounded independent Update/Rollback
review reproduced P1 missing-pointer root fallback and P2 retained-slot payload
reuse. Corrections and RED/GREEN/process coverage are recorded in
`docs/release-readiness/update-recovery-rollback.md`: explicit root pointers,
fail-closed missing/corrupt/symlinked activation state, installed-version guard,
fresh private slots on every stage attempt, and exclusive upload verification.
Launcher contract is now `seo-launcher/2`; V1 hosts require a trusted manual
launcher upgrade, not an automatic V1/V2 package transition. Local backend/PHP
gates passed; connected MariaDB tests skipped locally. **New-head CI and merge
remain unverified** because GitHub API access is denied. The owner will verify
the pushed head and merge PR #21 with expected-head protection if all three
workflows pass. Then verify main and validate the stacked import implementation;
do not recreate its completed home/project work.

Session paused by the owner; work is pushed. Verify with Git/CI before trusting this text.

**Branches (none merged to `main` yet; `main` is still `43342c6`):**

1. `feature/update-connected-acceptance` — ready for review/PR into `main`. Head CI: `CI` + `Update Rollback MariaDB` green; new permanent `Lifecycle E2E` workflow green at `042d5b3` (run 40641665038) and running on the latest head. Contains (each with RED regression test):
    - browser install HTTP 500 before DB exists (rate limiter bound to DB cache) — fixed;
    - fresh runtime defaulted to en/LTR — default locale `fa`;
    - installer step 6 refresh of cron/worker evidence;
    - `mariadb` driver unsupported by update fingerprint + export/backup REPEATABLE READ — fixed;
    - slot launcher redeclare fatal after release switch — fixed;
    - pre-switch failure left permanent maintenance — owner cancel (`CancelPendingUpdate`, `POST /settings/update/cancel`);
    - target migration failure/interruption with no DDL/history residue now rolls back (design §28); residue still `recovery_required`;
    - queue polling took the exclusive lifecycle lock (random 503s) — shared lease;
    - Persian host-upload-limit message on the update page;
    - `tests/Acceptance/Lifecycle/` dependency-free Chrome driver + `.github/workflows/lifecycle-e2e.yml`;
    - PR #18 checkpoint commits cherry-picked; PR #18 is superseded by this branch.
2. `feature/workspace-entry-keyword-import` — stacked on branch 1. Design: `docs/superpowers/specs/2026-10-28-workspace-entry-keyword-import-design.md`.
    - DONE + tested: Persian workspace home at `/dashboard` (`HomeController`, `resources/js/pages/home.tsx`), form-based project creation.
    - Implemented follow-up: upload/mapping/queued processing/commit/discard controller and routes, Persian/English import page, project navigation/CTAs, privacy/isolation/failure checks. Local gates GREEN; exact-head CI and browser import acceptance remain unverified (see current follow-up above).
    - NEXT: verify both new heads and main integration, then replace lifecycle service seeding with the real import UI and extend browser acceptance through intelligence/export/backup/restore/update/health.

**Open gaps:** new-head CI/merge for the recorded Update/Rollback review fixes; release signing (integrity only, not claimed); chunked upload for packages over host limits; no `lang/fa` (framework/auth strings English); launcher 503 page is bare English text.

**Lessons for local verification:** `api.github.com` zipballs may be blocked — build a local dist cache via shallow `git fetch` + `git archive`; set `COMPOSER_ALLOW_SUPERUSER=1`; serve with `php -S … -t public` (no router script); package uploads need `upload_max_filesize`/`post_max_size` ≥ package size.

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
