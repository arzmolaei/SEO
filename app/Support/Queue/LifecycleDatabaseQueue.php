<?php

namespace App\Support\Queue;

use App\Services\Update\UpdateException;
use App\Services\Update\UpdateJournal;
use App\Support\Lifecycle\LifecycleMutex;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Connection;
use Illuminate\Queue\DatabaseQueue;
use RuntimeException;

final class LifecycleDatabaseQueue extends DatabaseQueue
{
    public function __construct(
        Connection $database,
        string $table,
        string $default,
        int $retryAfter,
        ?bool $dispatchAfterCommit,
        private readonly LifecycleMutex $mutex,
        private readonly UpdateJournal $journal,
    ) {
        parent::__construct($database, $table, $default, $retryAfter, $dispatchAfterCommit);
    }

    /** @param \UnitEnum|string|null $queue */
    public function pop($queue = null): ?Job
    {
        if ($this->database->transactionLevel() !== 0) {
            throw new RuntimeException('Queue reservation requires an independent transaction.');
        }
        try {
            $ownership = $this->mutex->acquire('queue-reserve');
        } catch (RuntimeException) {
            return null;
        }
        try {
            try {
                $state = $this->journal->state();
            } catch (UpdateException) {
                return null;
            }
            if (
                app()->isDownForMaintenance()
                || UpdateJournal::isMaintenancePhase(is_string($state['phase'] ?? null) ? $state['phase'] : null)
            ) {
                return null;
            }

            // Laravel commits this independent reservation before ownership ends.
            return parent::pop($queue);
        } finally {
            $ownership->release();
        }
    }
}
