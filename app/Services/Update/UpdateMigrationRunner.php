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

    public function __construct(
        private readonly Migrator $migrator,
        ?string $connection = null,
        ?string $migrationPath = null,
    ) {
        $this->connection = $connection ?? (string) config('database.default');
        $this->migrationPath = $migrationPath ?? database_path('migrations');
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
        $this->assertCheckpoint($before);
        $files = $this->migrationFiles();
        $cursor = $before;
        $suffix = $before->appliedSuffix;
        foreach ($files as $name => $file) {
            if (in_array($name, array_column($cursor->ran, 'migration'), true)) {
                continue;
            }
            $this->assertCheckpoint($cursor);
            try {
                $this->migrator->usingConnection($this->connection, fn () => $this->migrator->run([$file], ['pretend' => false, 'step' => true]));
            } catch (Throwable $exception) {
                $afterFailure = $this->snapshot();
                if ($afterFailure->ran !== $cursor->ran || $afterFailure->schemaFingerprintSha256 !== $cursor->schemaFingerprintSha256) {
                    throw $this->recoveryRequired($exception);
                }
                throw new UpdateException('update_migration_failed', 'مهاجرت نسخه جدید اجرا نشد؛ برگشت باید از وضعیت ثبت‌شده انجام شود.', $exception);
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
            $cursor = new UpdateMigrationState($after->ran, $after->schemaFingerprintSha256, $suffix);
            $afterEachCompleted($cursor);
        }

        return $cursor;
    }

    /**
     * @param  list<array{migration:string,batch:int,schema_fingerprint_sha256:string}>  $expectedAppliedSuffix
     * @param  (callable(UpdateMigrationState): void)|null  $afterEachCompleted
     */
    public function rollbackExactSuffix(UpdateMigrationState $before, array $expectedAppliedSuffix, ?callable $afterEachCompleted = null): void
    {
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
            if ($suffix === []) {
                throw $this->recoveryRequired();
            }
            array_pop($suffix);
            array_pop($rows);
        }
        foreach (array_reverse($suffix) as $row) {
            $this->assertCheckpoint($expected);
            $file = $files[$row['migration']] ?? null;
            if (! is_string($file)) {
                throw $this->recoveryRequired();
            }
            try {
                // Only this proven file is available to Migrator; no broad batch rollback.
                $this->migrator->usingConnection($this->connection, fn () => $this->migrator->rollback([$file], ['pretend' => false, 'step' => 1]));
            } catch (Throwable $exception) {
                throw $this->recoveryRequired($exception);
            }
            array_pop($suffix);
            array_pop($rows);
            $expected = $this->reverseCheckpoint($before, $rows, $suffix);
            $this->assertCheckpoint($expected);
            if ($afterEachCompleted !== null) {
                $afterEachCompleted($expected);
            }
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

    private function schemaFingerprint(Connection $connection): string
    {
        if ($connection->getDriverName() === 'sqlite') {
            $definitions = $connection->select("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE substr(name, 1, 7) <> 'sqlite_' ORDER BY type, name");
            $objects = array_map(static fn (object $row): array => (array) $row, $definitions);
        } elseif ($connection->getDriverName() === 'mysql') {
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
                $sql = preg_replace('/ AUTO_INCREMENT=[0-9]+\b/', '', $sql);
                $objects[] = ['type' => 'table', 'name' => $name, 'sql' => $sql];
            }
            foreach ($connection->getSchemaBuilder()->getViews() as $view) {
                $objects[] = ['type' => 'view', ...$view];
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

    private function recoveryRequired(?Throwable $previous = null): UpdateException
    {
        return new UpdateException('update_recovery_required', 'تاریخچه یا ساختار داده برای ادامه ایمن مهاجرت قابل اثبات نیست.', $previous);
    }
}
