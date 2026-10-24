# Update + Recovery/Rollback operator notes

Checkpoint: 2026-10-24

These notes describe the supported V1 browser workflow for updating a packaged self-hosted SEO OS runtime. They are operational guidance, not an invitation to edit lifecycle files by hand. Release-readiness evidence and known gaps are in `docs/release-readiness/update-recovery-rollback.md`.

## Trust model — read first

A `.seoupdate` package contains **executable PHP code** that will run on your server with full application privileges.

- The SHA-256 shown by the application proves the file arrived intact. It does **not** prove who built it. V1 has no publisher signature check.
- Install only packages you built yourself or received from a source you trust through a channel you trust.
- A `beta` or `development` channel package is shown with an extra warning. Compatibility checks are identical; only the risk is higher.
- Keep an independent backup (`پشتیبان‌گیری و بازیابی`) before updating. The update creates its own safety snapshot, but that snapshot lives on the same host.

## Who may operate updates

Only the installer-created system owner. Each lifecycle action also needs an authenticated session, a verified account and a recent password confirmation. Ordinary workspace Owner/Admin roles are not enough.

## Requirements

- A package built for **exactly** your current ProductVersion. Packages declare one allowed source version and its exact schema fingerprint. Ranges, same-version and downgrade packages are rejected.
- The host PHP version and extensions listed in the preview must be satisfied.
- No unresolved Backup/Restore or Update operation.
- Building packages is done on a build host; see `docs/update-package-build.md`. The production host needs no Composer, Node/npm, SSH or shell.

## Updating

1. Sign in as the system owner.
2. Open `تنظیمات` -> `به‌روزرسانی و بازیابی نسخه`.
3. Complete the password confirmation when requested.
4. Choose the `.seoupdate` file and use `بارگذاری و بررسی بسته`.
5. Review the preview: target version, release channel, minimum PHP, migration count and the package SHA-256.
6. Compare the SHA-256 with the value published by whoever built the package, through a separate trusted channel.
7. Type the SHA-256 exactly into the confirmation field and choose `شروع به‌روزرسانی`.
8. The server enters persistent maintenance, waits for running jobs to finish, captures the safety snapshot, stages the new release privately and switches the active release. The page then reloads **on the new release**; this separate request is deliberate.
9. Choose `ادامه به‌روزرسانی`. The new release applies its migrations, validates health and records the new version. Success is shown as `نسخه جدید ثبت شد و بررسی سلامت را گذراند.`

The status shown is read from the server journal. There is no fake progress percentage.

If the status shows a waiting phase (for example waiting for running jobs) use `بررسی دوباره وضعیت`; do not start a second operation.

## If an update fails

The application decides the safe action. You only continue it.

- **Failure before migrations started** (the new release cannot even boot): the previous release is restored automatically without any database change.
- **Migration or health failure**: the status becomes `نیازمند بازگردانی نسخه قبلی`. Choose `ادامه بازگردانی ایمن`. The server reverses exactly the migrations the update applied, restores the previous release and then, in a fresh request on the previous release, restores the pre-update snapshot. Choose `ادامه بازگردانی ایمن` again if the page asks you to continue data recovery. Afterwards you must sign in again.
- **Browser or server interrupted mid-way**: reopen the same page as the system owner. The journal resumes from the last safe phase; never restart from scratch and never re-upload to "fix" a stuck phase.

Changes made after the safety snapshot was captured (data, uploads) are not kept by a snapshot restore. The application is in maintenance during the update to keep that window closed.

## When automatic recovery is blocked (`recovery_required`)

The page then says `بازیابی خودکار قابل اثبات نیست` and offers no automatic action. This happens when the application cannot prove a safe result, for example partial or unlogged schema changes, migration history that does not match what the update recorded, a missing or corrupt safety snapshot, or changed runtime secrets.

Do:

- stop; do not run further updates or restores;
- preserve everything on disk: the safety snapshot, the update journal/state and the staged release slots under the installation's `storage/app/lifecycle/update/`;
- keep a copy of the database as it is now;
- involve a technical administrator with the package and these artifacts.

Do not:

- delete or edit lifecycle files, the active-release pointer or migration tables;
- restore an older backup over a half-updated instance;
- retry by uploading the package again.

## What an update never changes

- destination database credentials, `APP_KEY`, `.env`, APP_URL, mail/storage credentials;
- the local installation id and database fingerprint;
- your durable data and uploads, except through a snapshot restore after a failed update.

On success only the installed product version is updated.

## Storage and exposure

Lifecycle uploads, safety snapshots, staged releases and the active-release pointer are private and must never be web-addressable. Safety snapshots contain highly sensitive instance data and `APP_KEY`; treat them like backups.

After a successful update the uploaded package file is deleted and the update journal is cleared. Previous release files, source public assets and the safety snapshot are retained; the application does not prune them in V1, so plan disk space and retire them deliberately only after you have verified the new release and taken a fresh backup.
