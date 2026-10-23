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
