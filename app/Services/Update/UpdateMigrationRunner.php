<?php

namespace App\Services\Update;

use App\Support\Update\UpdatePackageFormat;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UpdateMigrationRunner
{
    private readonly string $connection;

    private readonly string $migrationPath;

    private readonly string $attemptPath;

    public function __construct(
        private readonly Migrator $migrator,
        ?string $connection = null,
        ?string $migrationPath = null,
        ?string $attemptPath = null,
    ) {
        $this->connection = $connection ?? (string) config('database.default');
        $this->migrationPath = $migrationPath ?? database_path('migrations');
        $this->attemptPath = $attemptPath ?? storage_path('app/lifecycle/update/migration-attempt.json');
    }

    public function snapshot(): UpdateMigrationState
    {
        $connection = DB::connection($this->connection);
        $rows = $connection->table((string) config('database.migrations.table', 'migrations'))
            ->orderBy('id')->get(['migration', 'batch'])
            ->map(static fn (object $row): array => ['migration' => (string) $row->migration, 'batch' => (int) $row->batch])->all();

        return new UpdateMigrationState($rows, $this->schemaFingerprint($connection));
    }

    /** @param callable(UpdateMigrationState): void $afterEachCompleted */
    public function runPending(UpdateMigrationState $before, callable $afterEachCompleted): UpdateMigrationState
    {
        $this->resolveAbandonedForwardAttempt($before);
        $completed = $this->completedAttempt();
        if ($completed !== null) {
            if ($completed['direction'] !== 'up') {
                throw $this->recoveryRequired();
            }
            if ($before->toArray() === $completed['before']->toArray()) {
                $before = $completed['after'];
                $afterEachCompleted($before);
            } elseif ($before->toArray() !== $completed['after']->toArray()) {
                throw $this->recoveryRequired();
            }
            $this->clearAttempt();
        }
        $this->assertCheckpoint($before);
        $files = $this->migrationFiles();
        $cursor = $before;
        $suffix = $before->appliedSuffix;
        foreach ($files as $name => $file) {
            if (in_array($name, array_column($cursor->ran, 'migration'), true)) {
                continue;
            }
            $this->assertCheckpoint($cursor);
            $this->startAttempt('up', $name, $cursor);
            try {
                $this->migrator->usingConnection($this->connection, fn () => $this->migrator->run([$file], ['pretend' => false, 'step' => true]));
            } catch (Throwable $exception) {
                throw $this->failedForwardStep($cursor, $exception);
            }
            $after = $this->snapshot();
            if (
                count($after->ran) !== count($cursor->ran) + 1
                || array_slice($after->ran, 0, count($cursor->ran)) !== $cursor->ran
                || $after->ran[array_key_last($after->ran)]['migration'] !== $name
            ) {
                throw $this->recoveryRequired();
            }
            $row = $after->ran[array_key_last($after->ran)];
            $suffix[] = [...$row, 'schema_fingerprint_sha256' => $after->schemaFingerprintSha256];
            $completedBefore = $cursor;
            $cursor = new UpdateMigrationState($after->ran, $after->schemaFingerprintSha256, $suffix);
            $this->completeAttempt('up', $completedBefore, $cursor);
            $afterEachCompleted($cursor);
            $this->clearAttempt();
        }

        return $cursor;
    }

    /**
     * @param  list<array{migration:string,batch:int,schema_fingerprint_sha256:string}>  $expectedAppliedSuffix
     * @param  (callable(UpdateMigrationState): void)|null  $afterEachCompleted
     */
    public function rollbackExactSuffix(UpdateMigrationState $before, array $expectedAppliedSuffix, ?callable $afterEachCompleted = null): void
    {
        $this->consumeFailedForwardRecord($before, $expectedAppliedSuffix);
        $completed = $this->completedAttempt();
        if ($completed !== null && $completed['direction'] !== 'down') {
            throw $this->recoveryRequired();
        }
        $suffix = $expectedAppliedSuffix;
        $files = $this->migrationFiles();
        $rows = array_map(static fn (array $row): array => ['migration' => $row['migration'], 'batch' => $row['batch']], $suffix);
        // Validate all persisted evidence before reconciling a completed reverse step.
        $this->reverseCheckpoint($before, $rows, $suffix);
        $current = $this->snapshot();
        while (true) {
            $expected = $this->reverseCheckpoint($before, $rows, $suffix);
            if ($current->ran === $expected->ran && $current->schemaFingerprintSha256 === $expected->schemaFingerprintSha256) {
                break;
            }
            if ($suffix === [] || $completed === null) {
                throw $this->recoveryRequired();
            }
            array_pop($suffix);
            array_pop($rows);
        }
        if ($completed !== null) {
            if ($completed['after']->toArray() !== $expected->toArray()) {
                throw $this->recoveryRequired();
            }
            if ($afterEachCompleted !== null) {
                $afterEachCompleted($expected);
            }
            $this->clearAttempt();
        }
        foreach (array_reverse($suffix) as $row) {
            $this->assertCheckpoint($expected);
            $file = $files[$row['migration']] ?? null;
            if (! is_string($file)) {
                throw $this->recoveryRequired();
            }
            $this->startAttempt('down', $row['migration'], $expected);
            try {
                // Only this proven file is available to Migrator; no broad batch rollback.
                $this->migrator->usingConnection($this->connection, fn () => $this->migrator->rollback([$file], ['pretend' => false, 'step' => 1]));
            } catch (Throwable $exception) {
                throw $this->recoveryRequired($exception);
            }
            array_pop($suffix);
            array_pop($rows);
            $completedBefore = $expected;
            $expected = $this->reverseCheckpoint($before, $rows, $suffix);
            $this->assertCheckpoint($expected);
            $this->completeAttempt('down', $completedBefore, $expected);
            if ($afterEachCompleted !== null) {
                $afterEachCompleted($expected);
            }
            $this->clearAttempt();
        }
    }

    /**
     * @param  list<array{migration:string,batch:int}>  $rows
     * @param  list<array{migration:string,batch:int,schema_fingerprint_sha256:string}>  $suffix
     */
    private function reverseCheckpoint(UpdateMigrationState $before, array $rows, array $suffix): UpdateMigrationState
    {
        return new UpdateMigrationState(
            [...$before->ran, ...$rows],
            $suffix === [] ? $before->schemaFingerprintSha256 : $suffix[array_key_last($suffix)]['schema_fingerprint_sha256'],
            $suffix,
        );
    }

    private function assertCheckpoint(UpdateMigrationState $expected): void
    {
        $current = $this->snapshot();
        if ($current->ran !== $expected->ran || $current->schemaFingerprintSha256 !== $expected->schemaFingerprintSha256) {
            throw $this->recoveryRequired();
        }
    }

    /** @return array<string, string> */
    private function migrationFiles(): array
    {
        $root = realpath($this->migrationPath);
        if ($root === false || is_link($this->migrationPath) || ! is_dir($root)) {
            throw $this->recoveryRequired();
        }
        $files = $this->migrator->getMigrationFiles([$root]);
        foreach ($files as $path) {
            if (is_link($path) || ! is_file($path) || realpath($path) !== $path || dirname($path) !== $root) {
                throw $this->recoveryRequired();
            }
        }

        return $files;
    }

    /** @return array{direction:string,before:UpdateMigrationState,after:UpdateMigrationState}|null */
    private function completedAttempt(): ?array
    {
        if (! file_exists($this->attemptPath) && ! is_link($this->attemptPath)) {
            return null;
        }
        if (is_link($this->attemptPath) || ! is_file($this->attemptPath)) {
            throw $this->recoveryRequired();
        }
        $raw = file_get_contents($this->attemptPath);
        if (! is_string($raw) || strlen($raw) > 65536) {
            throw $this->recoveryRequired();
        }
        try {
            $attempt = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw $this->recoveryRequired($exception);
        }
        if (! is_array($attempt) || ($attempt['schema'] ?? null) !== 'seo-update-migration-attempt/1' || ($attempt['phase'] ?? null) !== 'complete' || ! is_array($attempt['checkpoint'] ?? null) || ! is_array($attempt['before_checkpoint'] ?? null) || ! in_array($attempt['direction'] ?? null, ['up', 'down'], true)) {
            throw $this->recoveryRequired();
        }
        $after = UpdateMigrationState::fromArray($attempt['checkpoint']);
        $before = UpdateMigrationState::fromArray($attempt['before_checkpoint']);
        $this->assertCheckpoint($after);

        return ['direction' => $attempt['direction'], 'before' => $before, 'after' => $after];
    }

    /**
     * A forward step that failed or was interrupted is never repeated. When the
     * migration repository and the complete DDL fingerprint still equal the
     * step's recorded starting checkpoint, nothing persistent beyond the
     * recorded suffix can remain except data-only work, which the source safety
     * snapshot replaces: exact rollback is provable. Any residue stays ambiguous.
     */
    private function failedForwardStep(UpdateMigrationState $cursor, ?Throwable $exception = null): UpdateException
    {
        try {
            $current = $this->snapshot();
        } catch (Throwable) {
            return $this->recoveryRequired($exception);
        }
        if ($current->ran !== $cursor->ran || $current->schemaFingerprintSha256 !== $cursor->schemaFingerprintSha256) {
            return $this->recoveryRequired($exception);
        }
        try {
            // Retained as durable evidence so the step is never run again; only
            // exact rollback from this checkpoint may consume it.
            $this->writeAttempt(['phase' => 'failed', 'direction' => 'up', 'before_checkpoint' => $cursor->toArray()]);
        } catch (Throwable) {
            return $this->recoveryRequired($exception);
        }

        return new UpdateException('update_migration_failed', 'مهاجرت نسخه جدید کامل نشد؛ بازگردانی دقیق نسخه قبلی لازم است.', $exception);
    }

    /** @param list<array{migration:string,batch:int,schema_fingerprint_sha256:string}> $suffix */
    private function consumeFailedForwardRecord(UpdateMigrationState $before, array $suffix): void
    {
        if (! is_file($this->attemptPath) || is_link($this->attemptPath)) {
            return;
        }
        $raw = file_get_contents($this->attemptPath);
        $attempt = is_string($raw) && strlen($raw) <= 65536 ? json_decode($raw, true) : null;
        if (! is_array($attempt) || ($attempt['phase'] ?? null) !== 'failed') {
            return;
        }
        $rows = array_map(static fn (array $row): array => ['migration' => $row['migration'], 'batch' => $row['batch']], $suffix);
        try {
            $failedAt = UpdateMigrationState::fromArray(is_array($attempt['before_checkpoint'] ?? null) ? $attempt['before_checkpoint'] : []);
        } catch (Throwable $exception) {
            throw $this->recoveryRequired($exception);
        }
        $expected = $this->reverseCheckpoint($before, $rows, $suffix);
        if (($attempt['direction'] ?? null) !== 'up' || $failedAt->ran !== $expected->ran || $failedAt->schemaFingerprintSha256 !== $expected->schemaFingerprintSha256) {
            throw $this->recoveryRequired();
        }
        $this->assertCheckpoint($expected);
        $this->clearAttempt();
    }

    private function resolveAbandonedForwardAttempt(UpdateMigrationState $cursor): void
    {
        if (! file_exists($this->attemptPath) || is_link($this->attemptPath) || ! is_file($this->attemptPath)) {
            return;
        }
        $raw = file_get_contents($this->attemptPath);
        $attempt = is_string($raw) && strlen($raw) <= 65536 ? json_decode($raw, true) : null;
        if (! is_array($attempt) || ! in_array($attempt['phase'] ?? null, ['started', 'failed'], true)) {
            return; // completed or malformed records keep their existing handling
        }
        if (($attempt['schema'] ?? null) !== 'seo-update-migration-attempt/1' || ($attempt['direction'] ?? null) !== 'up' || ! is_array($attempt['before_checkpoint'] ?? null)) {
            throw $this->recoveryRequired();
        }
        try {
            $started = UpdateMigrationState::fromArray($attempt['before_checkpoint']);
        } catch (Throwable $exception) {
            throw $this->recoveryRequired($exception);
        }
        if ($started->toArray() !== $cursor->toArray()) {
            throw $this->recoveryRequired();
        }

        throw $this->failedForwardStep($cursor);
    }

    private function startAttempt(string $direction, string $migration, UpdateMigrationState $before): void
    {
        if (file_exists($this->attemptPath) || is_link($this->attemptPath)) {
            throw $this->recoveryRequired();
        }
        $this->writeAttempt(['phase' => 'started', 'direction' => $direction, 'migration' => $migration, 'before_checkpoint' => $before->toArray()]);
    }

    private function completeAttempt(string $direction, UpdateMigrationState $before, UpdateMigrationState $after): void
    {
        $this->writeAttempt(['phase' => 'complete', 'direction' => $direction, 'before_checkpoint' => $before->toArray(), 'checkpoint' => $after->toArray()]);
    }

    /** @param array<string, mixed> $data */
    private function writeAttempt(array $data): void
    {
        $directory = dirname($this->attemptPath);
        if (is_link($directory) || is_link($this->attemptPath) || is_link($this->attemptPath.'.tmp')) {
            throw $this->recoveryRequired();
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw $this->recoveryRequired();
        }
        $json = UpdatePackageFormat::canonicalMetadataJson(['schema' => 'seo-update-migration-attempt/1', ...$data]);
        if (file_put_contents($this->attemptPath.'.tmp', $json, LOCK_EX) !== strlen($json)) {
            throw $this->recoveryRequired();
        }
        @chmod($this->attemptPath.'.tmp', 0600);
        if (! rename($this->attemptPath.'.tmp', $this->attemptPath)) {
            throw $this->recoveryRequired();
        }
        @chmod($this->attemptPath, 0600);
    }

    private function clearAttempt(): void
    {
        if (! unlink($this->attemptPath)) {
            throw $this->recoveryRequired();
        }
    }

    private function schemaFingerprint(Connection $connection): string
    {
        if ($connection->getDriverName() === 'sqlite') {
            $definitions = $connection->select("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE substr(name, 1, 7) <> 'sqlite_' ORDER BY type, name");
            $objects = array_map(static fn (object $row): array => (array) $row, $definitions);
        } elseif (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            // V1 proves table/view/trigger DDL. Unsupported executable schema
            // objects cannot silently fall outside a recovery checkpoint.
            $unsupported = $connection->select('SELECT ROUTINE_NAME AS name FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() UNION ALL SELECT EVENT_NAME AS name FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() UNION ALL SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE NOT IN (\'BASE TABLE\', \'VIEW\')');
            if ($unsupported !== []) {
                throw $this->recoveryRequired();
            }
            $names = $connection->getSchemaBuilder()->getTableListing(null, false);
            sort($names, SORT_STRING);
            $objects = [];
            foreach ($names as $name) {
                $quoted = '`'.str_replace('`', '``', $name).'`';
                $definition = (array) $connection->selectOne('SHOW CREATE TABLE '.$quoted);
                $sql = $definition['Create Table'] ?? null;
                if (! is_string($sql)) {
                    throw $this->recoveryRequired();
                }
                // AUTO_INCREMENT is mutable row state, not structural DDL evidence.
                $sql = $this->normalizeTableCounter($sql);
                $objects[] = ['type' => 'table', 'name' => $name, 'sql' => $sql];
            }
            foreach ($connection->getSchemaBuilder()->getViews() as $view) {
                $quoted = '`'.str_replace('`', '``', $view['name']).'`';
                $objects[] = ['type' => 'view', ...(array) $connection->selectOne('SHOW CREATE VIEW '.$quoted)];
            }
            $triggers = $connection->select('SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_ORDER, ACTION_CONDITION, ACTION_STATEMENT, ACTION_ORIENTATION, ACTION_TIMING, SQL_MODE, DEFINER, CHARACTER_SET_CLIENT, COLLATION_CONNECTION, DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
            foreach ($triggers as $trigger) {
                $objects[] = ['type' => 'trigger', ...(array) $trigger];
            }
            usort($objects, static fn (array $a, array $b): int => strcmp(UpdatePackageFormat::canonicalMetadataJson($a), UpdatePackageFormat::canonicalMetadataJson($b)));
        } else {
            throw $this->recoveryRequired();
        }

        return hash('sha256', UpdatePackageFormat::canonicalMetadataJson(['objects' => $objects]));
    }

    private function normalizeTableCounter(string $sql): string
    {
        $quote = null;
        $depth = 0;
        $result = '';
        for ($index = 0, $length = strlen($sql); $index < $length; $index++) {
            $character = $sql[$index];
            if ($quote !== null) {
                $result .= $character;
                if ($character === '\\' && $index + 1 < $length) {
                    $index++;
                    $result .= $sql[$index];
                } elseif ($character === $quote) {
                    if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                        $index++;
                        $result .= $sql[$index];
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }
            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
            } elseif ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            } elseif ($depth === 0 && $character === ' ' && preg_match('/\G AUTO_INCREMENT=[0-9]+\b/', $sql, $match, 0, $index) === 1) {
                $index += strlen($match[0]) - 1;

                continue;
            }
            $result .= $character;
        }

        return $result;
    }

    private function recoveryRequired(?Throwable $previous = null): UpdateException
    {
        return new UpdateException('update_recovery_required', 'تاریخچه یا ساختار داده برای ادامه ایمن مهاجرت قابل اثبات نیست.', $previous);
    }
}
