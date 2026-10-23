# Backup + Restore release readiness

Checkpoint: 2026-10-23

## Boundary

Backup + Restore implements the browser-driven, offline-capable, full-instance lifecycle for the packaged self-host runtime. It is not a project CSV export, generic DB dump, merge-restore tool, cloud backup service or scheduled-backup system.

Design: `docs/superpowers/specs/2026-10-23-backup-restore-design.md`  
Written review: `docs/superpowers/specs/2026-10-23-backup-restore-review.md`  
Implementation plan: `docs/superpowers/plans/2026-10-23-backup-restore.md`  
Integration branch / PR: `feature/backup-restore` / PR #7

## Implemented contract

### Stable release/lifecycle identity

- Root `VERSION` is copied into production release trees and production packaging rejects blank/`dev` identity.
- Installer finalization persists `owner_user_id` and uses the shared ProductVersion source.
- System owner resolution fails closed if a legacy installation cannot be resolved unambiguously.
- Destination-local `installation_id` and database fingerprint are preserved across restore; source provenance is recorded separately.

### Serialization and maintenance

- `LifecycleMutex` is the shared exclusive filesystem lock for Backup, Restore and future Update/Rollback.
- Backup/restore never rely on maintenance mode alone for serialization.
- Maintenance cleanup does not clear unrelated administrator maintenance state.

### Durable data contract

- Bundle schema is exactly `seo-backup/1` with application-owned deterministic framing.
- Durable DB scope is an explicit 19-table catalog, using `import_batches` and rejecting catalog drift.
- Parent-first inserts and exact reverse child-first deletes preserve FK enforcement.
- Schema fingerprint is deterministic over the application-owned durable schema.
- Row codec preserves strings, null, JSON text, decimal-safe values and binary payloads without locale/float coercion.
- Volatile sessions/cache/queue/password-reset state and migration bookkeeping are never restored as durable row data.

### Bundle parser/writer

- Magic: `SEOBACKUP\n`.
- Manifest/path/entry/count/whole-bundle limits are checked before or during bounded reads.
- Reader rejects malformed lengths, unsafe/control/traversal paths, duplicate logical content, mismatched paths/sizes/checksums, unsupported manifests and trailing bytes.
- Entry SHA-256 and canonical content digest are recomputed from actual bytes.
- No PHP deserialization, Phar execution, shell command or native dump/archive dependency is used.

### Backup creation/download

- Only the installer-created system owner may perform instance backup/restore lifecycle actions.
- Download action requires authenticated + verified + recent-password confirmation + system-owner enforcement.
- Backup captures a consistent durable DB snapshot, allowlisted settings and allowlisted durable files under lifecycle ownership.
- APP_KEY is included because durable encrypted values require it.
- DB credentials, APP_URL, mail/storage/external credentials and unrelated environment secrets are excluded.
- Download is private/no-store/nosniff, staged outside public storage and cleaned after response lifecycle.

### Restore verification

- Upload lives under private lifecycle storage with restrictive permissions.
- Complete bundle integrity, schema, ProductVersion, application-schema fingerprint, durable catalog and clean-target state are verified before destructive mutation.
- Restore journal is atomic and non-secret; it does not contain APP_KEY, DB credentials, password hashes or raw bundle payload.
- UI preview exposes only bounded source metadata/counts/digest needed for explicit confirmation.
- Confirmation must match the exact previously verified SHA-256 digest.

### Destructive restore and recovery

- Restore reacquires/revalidates mutable preconditions after taking the lifecycle lock.
- Protected internal safety snapshot is completed before destructive mutation.
- Durable DB replacement runs transactionally with foreign keys enabled.
- Durable files stage privately and switch through explicit lifecycle phases.
- Environment update changes source APP_KEY and allowlisted product settings only; destination DB/app/mail/storage/external identity remains preserved.
- Volatile state is cleared.
- Post-DB-commit failure enters `recovery_required`; ordinary lifecycle actions are blocked until recovery.
- Recovery restores the exact clean destination bootstrap DB/files/key/settings/installation metadata from the safety snapshot and removes recovery artifacts.
- Successful restore/recovery invalidates the initiating session and requires fresh login.

### Persian-first UI

- Settings navigation label is `پشتیبان‌گیری و بازیابی` and is visible only to the system owner.
- UI warns that the backup contains sensitive instance data and APP_KEY.
- It supports backup download, private upload/verify, verified source preview, exact digest confirmation and recovery.
- Phase/status is derived from server journal state; no fake progress percentage is shown.
- APP_KEY, raw SQL, credentials, raw restore journal and server paths are not rendered.

## Verification evidence

### Permanent implementation gate

Exact implementation head `35ee01a108ef5a9df31fce829378756e850e8565` passed permanent CI run `39976140240`.

All six permanent jobs passed:

- `verify` — success;
- PHP 8.4 compatibility — success;
- PHP 8.5 compatibility — success;
- Self-host Installer MariaDB 10.11 — success;
- Persian Export MariaDB 10.11 — success;
- Backup/Restore MariaDB 10.11 — success.

The `verify` job covers frontend formatter/lint, TypeScript, production build, Pint, PHPStan/Larastan, normal backend suite, runtime npm/Composer audits, runtime-only Composer install and production release-tree smoke.

The final implementation commit `35ee01a...` specifically verifies that the runtime release tree contains stable VERSION plus Backup/Restore lifecycle code/controllers/routes and built frontend assets.

### Connected Backup/Restore MariaDB acceptance

The permanent gate runs with real MariaDB 10.11 source and destination databases and covers:

1. same stable ProductVersion/schema on separately migrated source and destination databases;
2. real users/workspace/memberships/projects;
3. Persian keyword import with a real ZWNJ and representative metrics/saved view/audit/intent/review/cluster/page/mapping state;
4. passkey and password-hash preservation;
5. durable private/public upload bytes;
6. full backup through the real coordinator;
7. verification and restore into a clean destination;
8. source APP_KEY restored and proven capable of decrypting source encrypted durable state;
9. destination DB credentials, installation id and DB fingerprint preserved;
10. volatile sessions/cache/queue/password-reset state excluded;
11. second restore rejected rather than merged;
12. fresh destination reset followed by injected post-DB-commit environment failure;
13. journal transitions to `recovery_required` with destructively restored state present;
14. recovery restores destination bootstrap users/passkey/password hash, empty domain tables, files, APP_KEY, settings and installation metadata exactly;
15. lifecycle upload/safety/staging artifacts removed after success/recovery.

## Whole-branch review focus and result

Review at this checkpoint checked the high-risk boundaries against the written plan and current implementation/tests:

- owner-only route/middleware boundary and recent-password requirement;
- raw secret/path/internal-state exposure through HTTP/Inertia props;
- bundle length/path/checksum/trailing-data validation;
- APP_KEY inclusion vs DB/mail/storage/external credential exclusion;
- clean-target enforcement and second-restore rejection;
- destination-local installation/DB identity preservation;
- safety snapshot before destructive mutation;
- DB transaction/recovery boundary after commit;
- exact recovery of destination bootstrap state;
- volatile-state exclusion;
- public/durable lifecycle artifact cleanup;
- release-tree presence of VERSION/lifecycle/UI;
- permanent MariaDB acceptance and PHP 8.4/8.5 compatibility.

PR #7 currently has no submitted review or unresolved inline review thread. No remaining Critical or Important correctness/security finding is known at this checkpoint. Fresh exact-head CI remains mandatory after final documentation changes.

## Main reconciliation

The feature branch initially diverged one commit behind `main`. Current `main` commit `fabf2bf...` only removed an early Backup/Restore design draft from stable `main`. The final reviewed design is required by the feature merge boundary, so feature commit `0f857c313dfe5256764bbd55f33e562e9576db66` reconciles `main` as a parent while retaining the reviewed design. This removes the ancestry divergence without deleting feature requirements or rewriting history.

## Operator safety summary

Detailed operator instructions: `docs/backup-restore-operations.md`.

Key rules:

- treat every `.seobak` as a high-value secret;
- restore only into a clean installation running the same compatible release/schema;
- never edit lifecycle journal/safety files manually;
- if the UI reports recovery required, recover the safety snapshot before attempting another backup/restore/update;
- keep destination DB credentials/local installation identity independent of the source backup;
- do not place lifecycle artifacts under public web storage.

## Merge boundary

This release-readiness file is evidence, not permission to merge a newer unverified head. PR #7 is merge-ready only when:

1. this final documentation checkpoint is committed;
2. the current exact PR head receives fresh success across all six permanent CI jobs;
3. no Critical/Important review finding is open;
4. merge uses expected-head protection;
5. the resulting `main` merge commit receives independent permanent CI success;
6. `docs/PROJECT_STATE.md` records that exact merged SHA + post-merge CI.

After integration, the next subsystem is **Update + Interrupted Recovery/Rollback**, reusing ProductVersion, LifecycleMutex, backup capture/bundle, safety-snapshot and recovery primitives rather than creating a parallel lifecycle implementation.
