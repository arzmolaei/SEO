<?php

namespace App\Services\Update;

use Illuminate\Cache\CacheManager;
use Illuminate\Database\DatabaseManager;
use Throwable;

final class UpdateQuiescence
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly DatabaseManager $database,
    ) {}

    public function requestWorkerRestart(): void
    {
        try {
            $this->cache->store()->forever('illuminate:queue:restart', time());
        } catch (Throwable $exception) {
            throw new UpdateException(
                'update_quiescence_required',
                'برای ادامه به‌روزرسانی باید وضعیت workerهای صف قابل تأیید باشد.',
                $exception,
            );
        }
    }

    public function assertReady(): void
    {
        $connectionName = (string) config('queue.default', '');
        $driver = config('queue.connections.'.$connectionName.'.driver');

        if ($driver === 'sync') {
            return;
        }

        if ($driver !== 'database') {
            throw $this->quiescenceRequired();
        }

        $databaseConnection = config('queue.connections.'.$connectionName.'.connection');
        $table = config('queue.connections.'.$connectionName.'.table', 'jobs');
        if ($databaseConnection !== null && ! is_string($databaseConnection)) {
            throw $this->quiescenceRequired();
        }
        if (! is_string($table) || $table === '') {
            throw $this->quiescenceRequired();
        }

        try {
            $connection = $this->database->connection($databaseConnection ?: null);
            if ($connection->table($table)->whereNotNull('reserved_at')->exists()) {
                throw $this->quiescenceRequired();
            }
        } catch (UpdateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new UpdateException(
                'update_quiescence_required',
                'برای ادامه به‌روزرسانی باید کارهای در حال اجرای صف متوقف شوند.',
                $exception,
            );
        }
    }

    private function quiescenceRequired(): UpdateException
    {
        return new UpdateException(
            'update_quiescence_required',
            'برای ادامه به‌روزرسانی باید کارهای در حال اجرای صف متوقف شوند.',
        );
    }
}
