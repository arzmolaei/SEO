# Lifecycle admission/drain acceptance correction

Base: active feature branch `eea234d`. Confirmed Important review findings: an HTTP mutation admitted before maintenance can write after safety capture, and an idle Laravel worker can reserve work after the reserved-at poll because restart timestamps are checked later.

## Scoped implementation plan

1. Extend the existing LifecycleMutex with a shared read lease on the same lock file. A separate lease handle cannot satisfy destructive APIs requiring exclusive LifecycleMutexHandle. Shared leases never truncate exclusive ownership metadata.
2. Prepend a web admission middleware before session/controller writes on installed instances. Hold its shared lease across the whole downstream request stack; check persistent Update maintenance while holding the lease. Exact lifecycle routes that acquire their own exclusive lock are exempt from shared acquisition to avoid self-deadlock; recovery/auth allowlisting remains pinned to the dependency-free launcher paths. Fail closed with a sanitized retryable 503 when admission cannot be obtained.
3. Replace only the database queue connector with a compatible subclass whose pop reservation executes under the same exclusive LifecycleMutex and a journal maintenance check. Release only after Laravel commits the reservation. Busy/maintenance returns no job; failures do not bypass admission. Already-reserved work continues to block quiescence; pending work stays queued. No second lock, polling-only proof or worker-kill mechanism.
4. Tests first: ordinary request blocked by exclusive lifecycle ownership, shared request excludes updater until controller/session work returns, maintenance blocks a request admitted through an earlier launcher check, route exemptions preserve lifecycle/auth flow. Use a real two-process paused request to prove drain. Use the actual database connector and a real worker/process interleaving to prove reservation cannot cross the exclusive snapshot boundary.
5. Preserve permanent six-job CI and add connected reservation/drain acceptance as appropriate. Review exact APIs against Laravel v13.38.0, then require observed RED, final exact-head GREEN and independent review before merge into active branch. Full Update/Rollback Tasks 9–11/13 remain incomplete.

This is a targeted correction within approved lifecycle maintenance/quiescence requirements, not a new product architecture. User authorizes autonomous routine decisions; no further confirmation is required.

## Plan review corrections

Independent review requires booted-release identity revalidation while holding admission/pop ownership, so a paused old HTTP process/worker cannot resume after an entire update has completed and maintenance has cleared. Both paths must reject a process whose booted release root differs from the active pointer. Tests span a completed pointer switch, not just a maintenance phase.

Database reservation must reject ambient transactions (`transactionLevel() !== 0`): Laravel's nested transaction return does not commit an outer transaction, so ownership otherwise could be released before a reservation becomes visible. Test this using a second database connection. Preserve all five DatabaseConnector constructor arguments and QueueManager connection/container behavior.

Exemptions are exact method+path pairs only for lifecycle actions acquiring exclusive ownership. Authentication/status requests keep shared admission. Prepending the middleware outside StartSession covers downstream session persistence before return.

Audit also found `ActiveReleasePointer` defaulted to `base_path()` even in a slot-booted process. Its default must use `SEO_OS_INSTALLATION_ROOT`, matching the existing stager/launcher; explicit test roots remain supported. Add a real slot-bootstrap regression before fixing it.


## Implementation checkpoint

Observed HTTP/shared-lease/stable-pointer RED: `cfbdfe21`, CI `40027177798`, 5 failed/496 passed. HTTP implementation `1b52dfca` passed all six jobs in `40027464885` before a later test update canceled the aggregate workflow; final-head verification remains required. Observed queue RED: `715e7fb2`, CI `40027625618`, 3 failed/503 passed. Queue implementation `288d4ef6` passed backend/connected gates but CI `40027889202` caught an import-order style issue, now corrected from the formatter diff.

Independent implementation review found no concrete Important findings. Permanent real-process tests now pin an already-admitted HTTP mutation blocking exclusive safety capture, an idle actual Laravel worker denied reservation under the updater's ownership, and paused source request/worker rejection after a completed pointer switch with maintenance cleared. Connector defaults and ambient transaction behavior have direct integration tests; connected multi-connection MariaDB acceptance remains required before the full lifecycle release.

Queue-retention PR #13 was reviewed and merged at `b9999976f5273342ecf233318a966635bd70eb6e` after combined-tree CI `40027046367` passed all six jobs. This branch now combines that accepted boundary before final admission verification. Task 9 is still next after these confirmed Task 6/10 acceptance corrections; no main integration or full-product claim.


## Verified code boundary

Code head `3b2864baa93ec0df1af89e1b9d1369839361ca2a` passed all six permanent gates in CI `40057637639`. The connected log confirms eight MariaDB cases (32 assertions), including the two-connection queue reservation test, and the ordinary suite executes the real paused request/worker cases. Independent review found no Important issues in the incremental process/connected tests. Final documentation-head CI remains required before expected-head integration of PR #14; next planned task is target health/finalization. Main and full lifecycle release status remain unchanged.
