# Task 10 — interrupted update recovery coordinator

Base: active Update/Rollback PR #8 at a4a99589; main remains ea49d315. Work is isolated in PR #17 (`feature/update-recovery-continuation`), not integrated or release-ready.

## Evidence and review

- Existing PR #16 history-proof correction at 08b9b4a passed CI40060380878 and independent focused review in this session. Its code/tests are included before recovery depends on it; the original PR is not yet merged.
- First coordinator RED: d1c3c6f2 / CI40061689363. All ten new recovery tests failed because RecoverInterruptedUpdate did not exist; 547 existing backend tests passed on PHP8.4.
- Initial implementation da35f26a / CI40061838905: all 557 backend tests passed on PHP8.4 (4127 assertions). This is not a complete GREEN: verify stopped at two Pint style issues before static analysis.
- Fresh independent review found two Important gaps: terminalization of retryable checkpoint/post-pointer publication failures; and insufficient critical-source-runtime availability checks before downs. Regression tests were added before corrections in d4d95af0.

## Decisions within the approved plan

- Ruling: recovery returns snapshot_restore_required after switching to source, then rolled_back on a separately source-booted request. The plan's two-status return annotation omitted the mandatory inter-request boundary; returning success before source data restoration would be misleading.
- Ruling: update source snapshot application opts into preserving pending queue work AND exact existing runtime settings after verifying snapshot settings match the booted source config. Update must not rewrite local environment bytes or destroy legacy key configuration. Ordinary Backup/Restore keeps its existing default behavior.
- Ruling: retain protected source/target releases and immediate safety evidence rather than prune during recovery. This mirrors Task9 retention and avoids deleting evidence needed after interrupted cleanup; bounded retention/pruning remains later operations work.

## Verified service boundary

- Review correction RED d4d95af0 / CI40061952309 reproduced missing source availability, missing source-package digest and transient recovery failures; one down injection initially hit Laravel's cached migration object and was corrected in the test fixture before implementation.
- Corrected interruption/bootstrap RED deb86ed4 / CI40062039435: six expected failures, 557 existing tests passing. The injector runs from the original migration fixture, after real down DDL and before checkpoint publication.
- Corrected code 1488f5dd passed all 563 backend tests on PHP8.4/8.5; only two Pint issues remained.
- Final service code head 36cde2b55f84d69b595a488d92aafbd329b31e48 / CI40062215857 passed all six permanent jobs: frontend/TypeScript/build, Pint/PHPStan, complete backend suite, PHP8.4/8.5, connected Installer/Persian Export/Backup Restore and migration regressions, dependency audits and actual runtime-only release-slot smoke.
- Proven source-only pre-migration bootstrap recovery has a narrow journal entry, requiring no-migration evidence plus exact active source/schema/identity/snapshot validation. Ambiguous post-migration recovery_required remains terminal.
- Critical source runtime files must exist/read canonically before destructive downs. Slot-source package digest is now captured during prepare.
- Completed-down checkpoint and post-source-pointer publication failures retain retryable durable phases. Retryable snapshot staging failures preserve the source phase and snapshot. Unknown migration/history/schema failures remain fail-closed.

## Remaining work

Task10 service behavior is verified; connect owner-only continuation/recovery routes and Persian UI in Task11. Task13 must still prove real separately booted source/target update recovery on connected MariaDB and packaged runtime. These fixture-level base-path tests do not substitute for that gate. Full Update/Rollback and final lifecycle acceptance remain incomplete; do not merge PR8 to main yet.
