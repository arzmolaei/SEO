# Update + Interrupted Recovery/Rollback — Written Design Review

Date: 2026-10-23
Reviewed design: `docs/superpowers/specs/2026-10-23-update-recovery-rollback-design.md`
Design commit reviewed: `21ec112c244e1f04cb3f761bbaa53b81de52fc2a`
Status: **Approved with the rulings below.** These rulings are normative and must be carried into the implementation plan/tests.

## Review method

The design was reviewed against the current integrated runtime rather than against chat assumptions: release package builder/contract, `ProductVersion`, stable installation metadata, `LifecycleMutex`, `MaintenanceWindow`, in-process Laravel migrations, Backup/Restore capture/recovery, current Laravel launchers, runtime PHP floor and permanent CI gates.

The review focused on states where a shared-host update can strand the installation: launcher failure, corrupted pointers, partially applied MariaDB DDL, queue/scheduler concurrency, source/target code mixing, stale package verification and rollback after a hard interruption.

## Result

The selected architecture — **stable launchers + private immutable release slots + atomic active-release pointer** — remains the best fit for the product constraints. In-place overwrite cannot provide a trustworthy interruption boundary, and symlink/document-root switching is not a safe shared-host assumption.

No unresolved Critical architecture finding remains after the rulings below. The implementation plan must treat these as explicit acceptance requirements rather than optional refinements.

---

## Ruling 1 — Missing pointer and invalid pointer are different states

**Severity before ruling:** Critical.

A missing active-release pointer is the deliberate backwards-compatible state for the initial/root release. A present-but-invalid pointer (invalid JSON/schema, traversal, symlinked target, digest/version mismatch, missing slot) must **never** silently fall back to the root release.

Required behavior:

- pointer absent -> boot stable root release;
- pointer valid -> boot exactly the recorded private release slot;
- pointer present but invalid -> fail closed to a dependency-free recovery/maintenance response;
- automatic pointer reversion is allowed only when the update journal proves a specific pre-migration target-bootstrap failure and identifies the exact source release;
- no filesystem timestamp, “newest directory”, VERSION scan or root fallback may choose a release implicitly.

Tests must pin missing/corrupt/traversal/symlink/missing-slot behavior independently.

## Ruling 2 — Stable launcher recovery must work without Composer or Laravel

**Severity before ruling:** Critical.

The code deciding which release to boot cannot depend on the target release's autoloader or Laravel container. If target `vendor/autoload.php`, bootstrap code or PHP syntax is broken, target services are unavailable by definition.

Required behavior:

- release pointer parsing/containment and minimal update-journal phase reading use dependency-free PHP only;
- `public/index.php` wraps target boot with `Throwable` handling and registers a shutdown handler for fatal bootstrap failures that are observable after handler registration;
- fallback code may atomically revert to the exact source pointer only when journal evidence proves migrations have not begun;
- once the journal says migration mutation may have started, bootstrap failure cannot auto-revert schema/code independently; it remains maintenance + `recovery_required`/target-recovery state;
- the fallback response contains no absolute paths, credentials, raw exception or stack trace.

A release with invalid target bootstrap must be exercised by runtime acceptance, not only a unit fake.

## Ruling 3 — Source and target application classes never coexist in one update request

**Severity before ruling:** Important.

PHP cannot safely “switch” Composer application class graphs after source code has already booted.

Required behavior:

- source request may verify, snapshot, stage and atomically switch the active pointer, then it must terminate normally;
- migration/target health starts only on a later request that was launched by the target release from process start;
- rollback pointer switch from target to source follows the same rule: the target request changes the pointer and ends; source snapshot application starts on a later source-booted request;
- tests must prove coordinators do not directly instantiate target/source application code after pointer change in the same request.

## Ruling 4 — Migration rollback is exact-history based, and ambiguous DDL fails closed

**Severity before ruling:** Critical.

MariaDB DDL cannot be treated like one application-level transaction across arbitrary multi-statement migrations. A process can die after a schema statement but before Laravel records the migration as complete.

Required behavior:

- record the exact pre-update migration list/hash before any target migration;
- run official updater migrations in deterministic order and persist evidence after each completed migration;
- derive rollback candidates only as the exact verified suffix beyond the pre-update migration state;
- never use “latest batch” or an unqualified broad rollback as recovery evidence;
- if repository history, target schema or recorded suffix disagree, automatic rollback stops as `recovery_required`;
- if interruption leaves unlogged/partially applied intra-migration DDL, updater must detect that source/target fingerprints/history do not match a provable state and fail closed rather than execute guessed `down()` operations;
- official updater migration authoring rules should prefer one coherent reversible schema transition per migration and must be exercised on MariaDB forward/down fixtures.

Connected acceptance must include interruption between migration files and a deliberately ambiguous schema/history drift case that proves fail-closed behavior.

## Ruling 5 — Safety snapshot restores only under the source release/schema

**Severity before ruling:** Important.

A pre-update `seo-backup/1` snapshot is version/schema bound. It cannot safely be applied while target migrations/schema are still active.

Required rollback order:

1. target release rolls back exactly verified target migrations;
2. verify source schema fingerprint;
3. target request atomically switches active pointer to the exact source release and ends;
4. next request boots source release;
5. source release applies the pre-update system-state snapshot through the shared recovery kernel;
6. verify source ProductVersion/schema/install identity/data/files before clearing maintenance/journal.

The plan must extract only the minimum common “apply verified system-state snapshot” kernel from Restore recovery and keep existing Backup/Restore acceptance green.

## Ruling 6 — Persistent maintenance/quiescence is a first-class update primitive

**Severity before ruling:** Important.

`MaintenanceWindow::run()` is deliberately scoped to one synchronous callback. It cannot represent a source-request -> pointer switch -> target-request update.

Required behavior:

- add update-specific persistent lifecycle maintenance state driven by `UpdateJournal`;
- ordinary web mutations are blocked throughout destructive phases;
- only narrowly allowlisted status/continue/recovery routes remain bootable;
- stable CLI launcher blocks normal scheduler/queue starts during destructive phases;
- request queue-worker restart using Laravel's supported mechanism and require active reserved database jobs to drain before code/schema switch;
- do not kill processes or claim quiescence from configuration alone;
- queued-but-not-running durable jobs remain queued for the recovered/successful release.

Connected acceptance must prove a reserved job blocks destructive transition rather than being ignored.

## Ruling 7 — Stable environment/storage binding is part of launcher compatibility

**Severity before ruling:** Important.

A target release booted from `storage/app/lifecycle/update/releases/...` must not start reading a slot-local `.env` or writing a slot-local `storage` tree.

Required behavior:

- target `basePath` is the release slot;
- environment path is the stable installation root;
- storage path is stable `<installation-root>/storage`;
- launcher passes stable-root context without using environment secrets as pointer metadata;
- direct-root boot with no pointer remains byte-for-behavior compatible with the existing install;
- tests cover configuration, sessions/cache storage, logs and lifecycle paths under release-slot boot.

## Ruling 8 — Public asset switching is additive until rollback retention ends

**Severity before ruling:** Important.

Target PHP reads its own Vite manifest, while the web server still serves stable-root `/build/assets/*` paths.

Required behavior:

- copy target content-addressed hashed assets to stable public assets before pointer switch;
- verify every copied asset hash against package metadata;
- do not delete source hashes while the source release is a rollback candidate;
- non-hashed stable launcher/public files are not replaced by V1 target activation unless the launcher schema explicitly supports an independently safe transition;
- garbage collection is a later retention action and must be reference-aware.

Runtime acceptance must request a target-built asset after switch and a source-built asset after rollback.

## Ruling 9 — Package verification is TOCTOU-safe

**Severity before ruling:** Important.

A package verified before operator confirmation can change on disk or become stale relative to the installed version/schema.

Required behavior:

- private upload id/path is generated, restrictive and non-public;
- confirmation is tied to exact package content digest;
- after acquiring `LifecycleMutex('update')`, recompute package digest and revalidate source ProductVersion/schema/runtime requirements before safety snapshot/staging;
- target slot id derives from verified target version + digest, not original filename;
- existing target slot with mismatched bytes is rejected, never reused by name.

## Ruling 10 — First updater-capable release has an explicit root-source model

**Severity before ruling:** Important.

The current installation is not already a private release slot. The first update therefore needs a stable representation of “source release = root tree” without copying or guessing it after destructive work starts.

Required behavior:

- absence of active pointer means source release kind `root`;
- update journal records source kind/version/schema evidence before switch;
- root source remains untouched by target staging;
- rollback from the first target points back to explicit root-source state (normally by atomically removing/replacing pointer according to pointer service contract), not by scanning directories;
- later updates record the exact private source release id.

Tests must cover root -> slot -> root rollback and slot A -> slot B -> slot A rollback.

## Ruling 11 — Release authenticity is not falsely claimed

**Severity before ruling:** Important security/product boundary.

SHA-256 proves integrity against the uploaded manifest; it does not prove the package came from the vendor. The repository currently has no durable release-signing private-key operations.

Required behavior:

- V1 manual update UI says install only trusted update packages;
- package signing/authenticity remains `NEEDS VERIFICATION` before public/commercial automatic distribution;
- no “signed”, “official” or “verified publisher” claim is shown unless real signature verification and key rotation/revocation operations exist;
- future remote update/download work is blocked on a real signing design.

## Ruling 12 — Update channels do not weaken compatibility

**Severity before ruling:** Minor/Important boundary.

Manual V1 package manifest may declare `stable`, `beta` or `development`, but channel is informational/policy input, never permission to skip source/schema/runtime compatibility.

Required behavior:

- stable is default normal operator path;
- beta/development package requires an explicit stronger warning/confirmation in UI;
- all channels use identical integrity, source-version, schema, launcher and runtime checks;
- downgrades are rejected by V1 normal update flow; rollback is journal/safety-state driven, not a user-selected downgrade package.

---

## Builder check

A fresh implementer should be able to answer these without inventing architecture:

- **What boots if there is no pointer?** Stable root release.
- **What boots if pointer exists but is corrupt?** Nothing implicitly; dependency-free fail-closed recovery response.
- **Can source request run target migrations after pointer write?** No.
- **Can target request restore the source backup before schema rollback/source switch?** No.
- **How are rollback migrations selected?** Exact verified suffix beyond recorded pre-update migration history only.
- **What happens on ambiguous partial DDL?** `recovery_required`; no guessed rollback.
- **Where are `.env` and durable storage for a release slot?** Stable installation root/storage.
- **Can updater delete old hashed assets immediately?** No, not while a retained rollback release references them.
- **Can V1 claim package publisher authenticity?** No; signing is `NEEDS VERIFICATION` before public distribution.
- **Does first rollback scan for old code?** No; source kind/root or exact source release id is journaled.

The design plus these rulings is sufficiently specific to write the implementation plan.