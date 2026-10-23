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

    /** @param list<array{migration:string,batch:int,schema_fingerprint_sha256:string}> $expectedAppliedSuffix */
    public function rollbackExactSuffix(UpdateMigrationState $before, array $expectedAppliedSuffix): void
    {
        $suffix = $expectedAppliedSuffix;
        $files = $this->migrationFiles();
        $rows = array_map(static fn (array $row): array => ['migration' => $row['migration'], 'batch' => $row['batch']], $suffix);
        $expected = new UpdateMigrationState(
            [...$before->ran, ...$rows],
            $suffix === [] ? $before->schemaFingerprintSha256 : $suffix[array_key_last($suffix)]['schema_fingerprint_sha256'],
            $suffix,
        );
        $this->assertCheckpoint($expected);
        foreach (array_reverse($suffix) as $row) {
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
            $expected = new UpdateMigrationState(
                [...$before->ran, ...$rows],
                $suffix === [] ? $before->schemaFingerprintSha256 : $suffix[array_key_last($suffix)]['schema_fingerprint_sha256'],
                $suffix,
            );
            $this->assertCheckpoint($expected);
        }
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
        $schema = $connection->getSchemaBuilder();
        $names = array_values(array_filter($schema->getTableListing(null, false), static fn (string $name): bool => ! str_starts_with($name, 'sqlite_')));
        sort($names, SORT_STRING);
        $tables = [];
        $prefix = $connection->getTablePrefix();
        foreach ($names as $name) {
            $logical = $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
            $indexes = $schema->getIndexes($logical);
            $foreignKeys = $schema->getForeignKeys($logical);
            usort($indexes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            usort($foreignKeys, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
            $tables[] = ['name' => $name, 'columns' => $schema->getColumns($logical), 'indexes' => $indexes, 'foreign_keys' => $foreignKeys];
        }
        $views = $schema->getViews();
        usort($views, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return hash('sha256', UpdatePackageFormat::canonicalMetadataJson(['tables' => $tables, 'views' => $views]));
    }

    private function recoveryRequired(?Throwable $previous = null): UpdateException
    {
        return new UpdateException('update_recovery_required', 'تاریخچه یا ساختار داده برای ادامه ایمن مهاجرت قابل اثبات نیست.', $previous);
    }
}
