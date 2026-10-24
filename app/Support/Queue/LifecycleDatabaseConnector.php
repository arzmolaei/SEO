<?php

namespace App\Support\Queue;

use App\Services\Update\UpdateJournal;
use App\Support\Lifecycle\LifecycleMutex;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\Connectors\ConnectorInterface;
use InvalidArgumentException;

final class LifecycleDatabaseConnector implements ConnectorInterface
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly LifecycleMutex $mutex,
        private readonly UpdateJournal $journal,
    ) {}

    /** @param array<string, mixed> $config */
    public function connect(array $config): LifecycleDatabaseQueue
    {
        $connection = $config['connection'] ?? null;
        $table = $config['table'] ?? null;
        $queue = $config['queue'] ?? null;
        $retryAfter = $config['retry_after'] ?? 60;
        $afterCommit = $config['after_commit'] ?? null;
        if (
            ($connection !== null && ! is_string($connection))
            || ! is_string($table) || ! is_string($queue) || ! is_int($retryAfter)
            || ($afterCommit !== null && ! is_bool($afterCommit))
        ) {
            throw new InvalidArgumentException('Database queue configuration is invalid.');
        }

        return new LifecycleDatabaseQueue(
            $this->database->connection($connection), $table, $queue, $retryAfter, $afterCommit,
            $this->mutex, $this->journal,
        );
    }
}
