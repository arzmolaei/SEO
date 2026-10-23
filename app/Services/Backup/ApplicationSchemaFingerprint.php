<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Schema;
use JsonException;

final class ApplicationSchemaFingerprint
{
    public function fingerprint(): string
    {
        try {
            $json = json_encode(
                $this->metadata(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new BackupException('Unable to encode application schema metadata.', previous: $exception);
        }

        return hash('sha256', $json);
    }

    /** @return array<string, array<string, mixed>> */
    public function metadata(): array
    {
        $this->assertCatalogMatchesDatabase();

        $metadata = [];

        foreach (ApplicationTableCatalog::TABLES as $table) {
            $columns = [];

            foreach (Schema::getColumns($table) as $column) {
                $columns[] = [
                    'name' => (string) $column['name'],
                    'type' => (string) $column['type'],
                    'type_name' => (string) $column['type_name'],
                    'family' => $this->columnFamily((string) $column['type_name'], (string) $column['type']),
                    'nullable' => (bool) $column['nullable'],
                    'auto_increment' => (bool) $column['auto_increment'],
                ];
            }

            $indexes = [];
            $primaryKey = [];

            foreach (Schema::getIndexes($table) as $index) {
                $normalized = [
                    'columns' => array_values(array_map('strval', $index['columns'])),
                    'type' => strtolower((string) $index['type']),
                    'unique' => (bool) $index['unique'],
                    'primary' => (bool) $index['primary'],
                ];

                if ($normalized['primary']) {
                    $primaryKey = $normalized['columns'];
                }

                $indexes[] = $normalized;
            }

            usort($indexes, static fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_UNESCAPED_SLASHES) ?: '',
                json_encode($right, JSON_UNESCAPED_SLASHES) ?: '',
            ));

            $foreignKeys = [];
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $foreignKeys[] = [
                    'columns' => array_values(array_map('strval', $foreignKey['columns'])),
                    'foreign_table' => (string) $foreignKey['foreign_table'],
                    'foreign_columns' => array_values(array_map('strval', $foreignKey['foreign_columns'])),
                    'on_update' => $foreignKey['on_update'] === null ? null : strtolower((string) $foreignKey['on_update']),
                    'on_delete' => $foreignKey['on_delete'] === null ? null : strtolower((string) $foreignKey['on_delete']),
                ];
            }

            usort($foreignKeys, static fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_UNESCAPED_SLASHES) ?: '',
                json_encode($right, JSON_UNESCAPED_SLASHES) ?: '',
            ));

            $metadata[$table] = [
                'columns' => $columns,
                'primary_key' => $primaryKey,
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
            ];
        }

        return $metadata;
    }

    public function assertCatalogMatchesDatabase(): void
    {
        $actual = [];

        foreach (Schema::getTableListing(null, false) as $table) {
            $table = $this->normalizeTableName((string) $table);

            if ($table === '' || str_starts_with($table, 'sqlite_')) {
                continue;
            }

            $actual[] = $table;
        }

        $actual = array_values(array_unique($actual));
        sort($actual, SORT_STRING);

        $expected = array_merge(
            ApplicationTableCatalog::TABLES,
            ApplicationTableCatalog::excludedTables(),
        );
        sort($expected, SORT_STRING);

        $missing = array_values(array_diff($expected, $actual));
        $unexpected = array_values(array_diff($actual, $expected));

        if ($missing !== [] || $unexpected !== []) {
            $parts = [];

            if ($missing !== []) {
                $parts[] = 'missing: '.implode(', ', $missing);
            }

            if ($unexpected !== []) {
                $parts[] = 'unexpected: '.implode(', ', $unexpected);
            }

            throw new BackupException('Backup table catalog does not match the database ('.implode('; ', $parts).').');
        }
    }

    private function columnFamily(string $typeName, string $type): string
    {
        $normalized = strtolower($typeName.' '.$type);

        if (str_contains($normalized, 'binary') || str_contains($normalized, 'blob')) {
            return 'binary';
        }

        if (str_contains($normalized, 'json')) {
            return 'json';
        }

        return 'text';
    }

    private function normalizeTableName(string $table): string
    {
        $table = trim($table, "`\" ");

        if (str_contains($table, '.')) {
            $table = substr($table, strrpos($table, '.') + 1);
        }

        return trim($table, "`\" ");
    }
}
