<?php

namespace App\Services\Backup;

use Illuminate\Database\Connection;
use InvalidArgumentException;

final class ApplicationTableCatalog
{
    /** @var list<string> */
    public const TABLES = [
        'users',
        'passkeys',
        'workspaces',
        'memberships',
        'projects',
        'import_batches',
        'staged_keyword_rows',
        'keywords',
        'keyword_sources',
        'keyword_metrics',
        'saved_keyword_views',
        'change_sets',
        'activity_events',
        'keyword_intent_assignments',
        'review_items',
        'keyword_clusters',
        'keyword_cluster_members',
        'pages',
        'cluster_targets',
    ];

    /** @var list<string> */
    private const EXCLUDED_TABLES = [
        'migrations',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    /** @return list<string> */
    public function tableNames(): array
    {
        return self::TABLES;
    }

    /** @return list<string> */
    public function insertOrder(): array
    {
        return self::TABLES;
    }

    /** @return list<string> */
    public function deleteOrder(): array
    {
        return array_reverse(self::TABLES);
    }

    /** @return list<string> */
    public function excludedTables(): array
    {
        return self::EXCLUDED_TABLES;
    }

    public function isDurable(string $table): bool
    {
        return in_array($table, self::TABLES, true);
    }

    public function entryPath(string $table): string
    {
        if (! $this->isDurable($table)) {
            throw new BackupException(
                'backup_catalog_drift',
                'ساختار داده‌های برنامه با فهرست پشتیبان هم‌خوان نیست.',
            );
        }

        return "database/{$table}.ndjson";
    }

    /** @return list<string> */
    public function cursorFor(string $table): array
    {
        if (! $this->isDurable($table)) {
            throw new InvalidArgumentException('Unknown durable backup table.');
        }

        return ['id'];
    }

    public function assertNoDrift(Connection $connection): void
    {
        $actual = [];

        foreach ($connection->getSchemaBuilder()->getTableListing(null, false) as $table) {
            $name = strtolower((string) $table);

            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }

            $actual[] = $name;
        }

        $actual = array_values(array_unique($actual));
        sort($actual, SORT_STRING);

        $known = array_merge(self::TABLES, self::EXCLUDED_TABLES);
        $missing = array_values(array_diff(self::TABLES, $actual));
        $unknown = array_values(array_diff($actual, $known));

        if ($missing !== [] || $unknown !== []) {
            throw new BackupException(
                'backup_catalog_drift',
                'ساختار داده‌های برنامه با فهرست پشتیبان هم‌خوان نیست.',
            );
        }
    }

    /**
     * @return list<array{
     *     name: string,
     *     restore_order: int,
     *     cursor: list<string>,
     *     columns: list<array{name: string, type: string, type_name: string, family: string, nullable: bool, auto_increment: bool}>
     * }>
     */
    public function metadata(Connection $connection): array
    {
        $this->assertNoDrift($connection);
        $schema = $connection->getSchemaBuilder();
        $tables = [];

        foreach (self::TABLES as $index => $table) {
            $columns = [];

            foreach ($schema->getColumns($table) as $column) {
                $type = $this->normalizeType($column['type']);
                $typeName = $this->normalizeType($column['type_name']);

                $columns[] = [
                    'name' => $column['name'],
                    'type' => $type,
                    'type_name' => $typeName,
                    'family' => $this->columnFamily($typeName !== '' ? $typeName : $type),
                    'nullable' => $column['nullable'],
                    'auto_increment' => $column['auto_increment'],
                ];
            }

            $tables[] = [
                'name' => $table,
                'restore_order' => $index + 1,
                'cursor' => $this->cursorFor($table),
                'columns' => $columns,
            ];
        }

        return $tables;
    }

    private function normalizeType(string $type): string
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower(trim($type)));

        return is_string($normalized) ? $normalized : strtolower(trim($type));
    }

    private function columnFamily(string $type): string
    {
        if (preg_match('/(?:binary|blob)/', $type) === 1) {
            return 'binary';
        }

        if (str_contains($type, 'json')) {
            return 'json';
        }

        if (preg_match('/(?:^|\b)(?:tinyint|smallint|mediumint|int|integer|bigint)(?:\b|$)/', $type) === 1) {
            return 'integer';
        }

        if (preg_match('/(?:decimal|numeric)/', $type) === 1) {
            return 'decimal';
        }

        if (preg_match('/(?:float|double|real)/', $type) === 1) {
            return 'float';
        }

        if (preg_match('/(?:date|time|timestamp|year)/', $type) === 1) {
            return 'temporal';
        }

        return 'text';
    }
}
