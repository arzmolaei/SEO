// Which server states can change without this tab acting. Polling only
// re-reads server truth for those states; it never invents progress.

export const STATUS_POLL_INTERVAL_MS = 3000;

export type PolledImportBatch = {
    status: string;
    commit_requested_at: string | null;
};

export function importBatchNeedsPolling(
    batch: PolledImportBatch | null,
): boolean {
    if (batch === null) return false;
    if (batch.status === 'queued' || batch.status === 'processing') return true;

    return batch.status === 'staged' && batch.commit_requested_at !== null;
}

export type PolledOperations = {
    scheduler: { state: string };
    queue: { state: string };
};

// 'warning' means the installer is still waiting to observe local
// scheduler/worker evidence; 'verified' and 'fail' need no re-read.
export function operationsNeedPolling(operations: PolledOperations): boolean {
    return (
        operations.scheduler.state === 'warning' ||
        operations.queue.state === 'warning'
    );
}

// Phases another tab or the server itself can advance. Phases that wait
// for this operator's decision (ready, verified, target_active, recovery,
// complete …) are not polled.
const UPDATE_TRANSIENT_PHASES = new Set([
    'preparing',
    'quiescing',
    'snapshot_ready',
    'staged',
    'switch_pending',
    'migrating',
    'validating',
    'migration_rollback',
    'source_switch_pending',
]);

export function updateNeedsPolling(status: string, busy: boolean): boolean {
    return !busy && UPDATE_TRANSIENT_PHASES.has(status);
}
