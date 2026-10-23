# Backup + Restore operator notes

Checkpoint: 2026-10-23

These notes describe the supported V1 browser workflow for the packaged self-hosted runtime. They are operational guidance, not an invitation to edit lifecycle files by hand.

## Security classification

Every downloaded `.seobak` file is **highly sensitive**. It contains durable instance data, password hashes and APP_KEY so restored encrypted application data remains readable.

- Store backups only in protected storage you control.
- Do not upload backups to public drives, public object storage, email, chat, support tickets or issue trackers.
- Do not commit a backup to Git.
- Do not expose lifecycle upload/safety/staging directories through the web server.
- Deleting a backup from the application does not delete copies you made elsewhere.

The backup intentionally does **not** contain destination DB credentials, APP_URL, mail credentials, storage credentials or external API credentials.

## Who may operate Backup/Restore

V1 is system-level. Only the installer-created system owner may use it.

Lifecycle actions require:

- authenticated session;
- verified account;
- recent password confirmation;
- system-owner identity.

Ordinary workspace Owner/Admin roles are not enough if they are not the system owner.

## Creating a backup

1. Sign in as the system owner.
2. Open `تنظیمات` -> `پشتیبان‌گیری و بازیابی`.
3. Complete recent password confirmation when requested.
4. Use `دانلود فایل پشتیبان`.
5. Save the completed `.seobak` file directly to protected operator storage.
6. Keep the file name/timestamp together with your release version records.

Backup creation briefly serializes lifecycle work and captures a consistent durable state. The generated download is private/no-store and the application removes its temporary completed artifact after the response lifecycle.

If backup creation fails, do not copy files from lifecycle temp directories. Correct the reported operator issue and run a new backup.

## Supported restore target

Restore is not a merge operation. Use a **clean compatible installed target**.

The target must:

- be fully installed and current for the same compatible ProductVersion/schema;
- contain only the permitted installer bootstrap owner/workspace/membership state;
- contain no project/domain data;
- contain no unrecognized durable upload files;
- have no unresolved lifecycle operation;
- keep its own DB credentials and local installation identity.

A second restore into an already restored/non-clean installation is rejected.

## Restoring a verified backup

1. Provision/install the clean destination release normally.
2. Sign in as the destination system owner and open `پشتیبان‌گیری و بازیابی`.
3. Choose the `.seobak` file and upload it for verification.
4. Review the displayed source version/time/counts.
5. Do not continue if the source is unexpected.
6. Copy the exact verified SHA-256 digest into the destructive-confirmation field.
7. Start restore.
8. Allow the server operation to finish; do not start another Backup/Restore/Update lifecycle action concurrently.
9. After successful restore, sign in again. Session invalidation is expected.

The server re-verifies the uploaded bytes and mutable target preconditions after obtaining the lifecycle lock. Verification happens before destructive mutation.

## What restore preserves from the destination

The destination remains the local installation. Restore preserves:

- destination DB connection credentials;
- destination `installation_id`;
- destination database fingerprint;
- APP_URL;
- destination mail/storage/external credentials and unrelated environment values.

The source APP_KEY and explicitly allowlisted product settings are restored because durable encrypted application data requires them. Source provenance is recorded separately.

## Recovery-required state

If a failure occurs after destructive database progress has committed, the system fails closed into `recovery_required`.

This is intentional. Do **not**:

- start another restore;
- start an update/rollback lifecycle action;
- delete or edit `state.json`;
- delete safety-snapshot files;
- manually swap staged upload directories;
- manually replace APP_KEY hoping to continue the restore.

Instead:

1. return to the Backup/Restore settings page as the system owner;
2. complete password confirmation when the application requests it;
3. use the recovery action shown by the UI;
4. allow recovery to restore the protected pre-restore clean snapshot;
5. sign in again after recovery;
6. verify the target is back to clean bootstrap state before retrying restore with a known-good backup.

Recovery is designed to restore destination bootstrap DB rows, durable files, APP_KEY/settings and installation metadata exactly, then remove lifecycle recovery artifacts.

## If recovery cannot be initiated from the browser

Treat the target as an interrupted lifecycle installation, not as a partially successful restore.

- Keep the application/storage files intact for diagnosis.
- Do not expose or send the backup, APP_KEY, `.env`, journal or safety snapshot through public support channels.
- Confirm PHP/runtime/filesystem/DB availability and ownership/permissions on the host.
- Use a protected administrative support channel if escalation is required.
- Prefer reprovisioning a new clean compatible destination and restoring the original protected backup over improvising manual DB/file merges.

V1 intentionally does not provide a shell recovery recipe because production operation must remain browser-driven and host-portable. Manual mutation of lifecycle internals is outside the supported contract.

## Backup retention and rotation

V1 does not schedule or rotate backups automatically. Operators should define retention outside the application based on their own recovery policy.

Recommended operational principles:

- keep more than one recent known-good backup;
- keep at least one copy outside the application host while still in protected storage;
- record the ProductVersion associated with each backup;
- remove obsolete copies securely according to your retention policy;
- take/verify a fresh backup before future Update/Rollback operations once that subsystem is integrated.

## Troubleshooting boundaries

Expected safe failures include incompatible version/schema, invalid checksum, unsafe path, non-clean restore target, wrong digest confirmation, lifecycle lock contention and recovery-required state.

Do not work around these checks by changing code/configuration on the production host. They exist to prevent partial/cross-version/merged restoration.

For engineering verification and acceptance details, see:

- `docs/release-readiness/backup-restore.md`
- `docs/superpowers/specs/2026-10-23-backup-restore-design.md`
- `docs/superpowers/plans/2026-10-23-backup-restore.md`
