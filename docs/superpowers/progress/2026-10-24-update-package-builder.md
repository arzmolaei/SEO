# Update package tooling execution checkpoint

## Scope and delegated decisions

Task 12 of `docs/superpowers/plans/2026-10-23-update-recovery-rollback.md`, built against the reviewed Update/Rollback design and its normative review rulings.

The user explicitly delegated all routine decisions and asked not to be interrupted. Another execution is actively implementing Task 7 on `feature/update-recovery-rollback` (observed `5cd71e6`). This branch handles independent build-host package tooling and runtime smoke without changing its switch/migration coordinators. No claim is made that the full subsystem or lifecycle release is complete.

Baseline: `f38d10468f5c90f36da760eb023adec83dc5ad3f`, containing PR #9's independently reviewed unknown-phase fail-closed regression fix. Its feature head `e75bd2597b8262c0083b7e9e2689d8b418b897fc` passed all six CI jobs in run `40017579922` (462 backend tests, 3551 assertions on both PHP versions; connected-only tests run in dedicated MariaDB jobs).

## Interface ledger

- Tasks 2/12: package writer currently holds caller-owned stream resources for all entries. Opening an entire real vendor tree simultaneously would exceed ordinary file-descriptor budgets. Ruling: add backward-compatible lazy stream input plus a `writeFiles` adapter. Only one file input is opened at a time; caller-owned resources remain open. The `seo-update/1` byte format is unchanged.
- Tasks 1/12: stable launchers/public files and runtime storage must not be copied into update payloads. Package paths derive from `UpdatePackageFormat`'s allowlist; source is an already-built release validated by `ReleasePackageContract`.
- Tasks 2/8/12: migration tree identity is SHA-256 of canonical metadata for sorted `database/migrations/**` files (`path`, `size`, `sha256`, `media_type`). The exact definition is documented and pinned by a behavioral test so the later migration runner can reproduce it.
- Build determinism requires an explicit UTC creation time. Build API accepts this as metadata; CLI honors `SOURCE_DATE_EPOCH`, defaulting to build time. Source compatibility is always explicit and never inferred from Git or the build-host database.

## Current status

Tests first; implementation and permanent runtime smoke verification remain pending. Local PHP/Composer are unavailable; observed RED/GREEN must come from exact-head CI.
