# Task 7 — dependency-free bootstrap recovery

Base: active feature head `1debca14082f5a3bfad2d3167c35784de11ab42d`. Existing source-to-target switch service and its tests are reused; they are not reimplemented. The three existing `TargetBootstrapFailureTest` cases were still RED. This branch completes the launcher side of Task 7.

## RED evidence

- `841bf13ee869e466961f48269abfd801a5f5b213`, CI `40020489638`: root-source, slot-source, post-migration, fatal-OOM shutdown and mismatched target journal tests failed as expected (5 failed, 467 passed).
- `ba45a1b17e9ca174ccc90884da353a352d4566a9`, CI `40020758677`: additional actual-lock fingerprint assertions failed on the missing handle API/journal evidence; 7 failed, 467 passed.

## Rulings

- Source classes are never loaded after the pointer changes. A failed target boot returns a sanitized maintenance response; source boot occurs on a later request.
- Laravel HTTP kernel bootstrap is inside the guarded region, with the captured request bound in the same order as normal kernel handling. Request handling/migrations occur after the guard closes, so a later request failure never silently reverts code independently of schema.
- A shutdown handler and emergency buffer cover observable fatal bootstrap failures. It discards bootstrap output and suppresses bootstrap exception/fatal text from the response. Normal error settings are restored after successful bootstrap.
- Dependency-free recovery must acquire the same actual `LifecycleMutex` file. `LifecycleMutexHandle::pathFingerprint()` proves the canonical held path; preparation records only its SHA-256, never an absolute path or owner token.
- Automatic launcher recovery supports the stable default lock only when that recorded fingerprint matches and the existing private lock can be acquired. A custom/missing/unprovable lock or another active owner retains maintenance/journal/pointer without guessing. The application recovery workflow remains responsible for such manual/recovery-required cases. No dotenv parser or parallel lock abstraction is introduced.
- Exact booted-target identity must still match the active pointer under the lock. Reversion additionally requires matching journal target id/version/digest, a provably pre-migration phase and a valid exact recorded source. After migration may have started, the target pointer stays active and the journal enters `recovery_required`.
- Missing/corrupt source evidence keeps the failed target and safety artifacts for recovery; no root/newest-slot fallback.

## Status

Implementation is under exact-head CI and requires independent review before integration. The owner-only, recent-password confirm endpoint now prepares verified packages or resumes staged ones, then returns an explicit reload instruction; it never runs target code in the source request. A conflicting Restore journal is rechecked under the shared switch lock. Confirm/auth/stale-digest/restore-conflict regressions observed RED on `494451f4`, CI `40021566898` (4 failed, 474 passed). Target continuation/migrations, UI and connected acceptance remain Tasks 8–13. Package tooling is independently implemented in PR #10; its no-dev source -> target slot -> source runtime smoke passed all six gates on `8fd35c7`, CI `40019999023`.


## Verified intermediate boundary and authentication correction

Implementation head `662cac538c1e7d4470be6f9ee18453286163fcc7`, CI `40021758794`: all six permanent jobs passed, including 478 backend tests on both PHP versions, connected MariaDB regressions and the ordinary no-dev release-tree boot.

Actual-router recovery coverage then exposed a pre-existing maintenance allowlist bug: Fortify's registered password confirmation is `/user/confirm-password`, not only `/confirm-password`. RED on `d73e582e`, CI `40021902513`: one failure, 478 passing. The narrow allowlist now includes the real password-confirmation and two-factor challenge paths. Registration, password changes and two-factor configuration stay blocked. This is required so the persisted system owner can authenticate/reconfirm during recovery.

Final exact-head CI and integration remain pending; no main/subsystem completion claim is made.


Final reviewed Task 7 head `a90e1c08214fa1fcd2def6cd26513d91b6f6433c` passed all six permanent jobs in CI `40022118065`; PR #11 merged with expected-head protection into the active feature branch at `f699d8363de91a419bb6d5de7e508fc8c6f8bec1`. Final count: 479 backend tests, connected-only cases in dedicated MariaDB jobs. Independent review found no unresolved findings. Combined runtime package smoke is the next gate through PR #10, followed by Task 8. This is not integration into main.
