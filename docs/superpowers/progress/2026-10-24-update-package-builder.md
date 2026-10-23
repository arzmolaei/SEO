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

RED observed on `02f444173dd23bccab19bef66105a80e318c8844`, CI `40018469864`: missing builder and unsupported lazy streams failed as intended. The merge test tree additionally contained the other execution's active switch regression; it was not modified here.

Builder, explicit-metadata CLI, lazy/file writer inputs and canonical path families are implemented. On `c5cb3a8808a77f468c2a7a8f783eee7b4bab86eb`, CI `40019053065` passed this branch's builder/CLI/writer tests and static analysis. Its merged PR test tree still had three active Task 7 `TargetBootstrapFailureTest` failures; these belong to the other execution and must turn green before integration. No gate was disabled.

Real-process runtime-only smoke is being added to the permanent verify gate: build a complete update package from the no-dev runtime tree, stage it with the actual stager, boot source -> target slot -> source across separate HTTP requests, verify stable env/storage and both content-addressed assets. This does not claim migration/rollback-coordinator acceptance (Task 13).

Local PHP/Composer are unavailable; final exact-head full CI and independent review remain required.


## Independent review and correction

Review found one Important issue: generated build-host bootstrap configuration could enter an otherwise valid runtime package and override the destination `.env`. Regression `test_rejects_build_host_config_route_and_event_caches_before_publishing` observed RED on `35a3764d0ec893c724abc2872321645b4f52ef2b`, CI `40019592094`: one new failure, 468 passing tests. Correction: the shared release contract rejects generated bootstrap state except Composer provider-discovery metadata (`packages.php`, `services.php`) and empty-directory markers. This protects both ordinary runtime releases and update builds; no cache contents are emitted in errors.

CI isolation ruling: PR #10 is temporarily based on `verification/update-package-baseline@f38d104` so active Task 7 RED tests from the concurrent execution cannot prevent this branch's actual runtime smoke from executing. Do not merge into the verification branch. Retarget to the active feature branch after this branch's gates pass, re-run merged CI, then integrate with expected-head protection.


Final package-tooling head `8fd35c701c4f36e6690be4bb65568b67eee08e27`, CI `40019999023`: all six jobs passed, 469 backend tests (3603 assertions), production-only real HTTP runtime package/slot/source smoke passed. Independent review's Important cached-configuration issue was fixed with observed RED/GREEN; no unresolved findings remain. PR #10 is now back on the active feature branch; this merge imports Task 7's verified head without replacing either workstream. Final combined-tree CI is required before expected-head integration into that branch. Next unfinished implementation task is Task 8; full Update/Rollback remains incomplete.
