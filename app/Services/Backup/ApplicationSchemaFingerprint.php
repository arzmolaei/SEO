<?php

namespace App\Services\Backup;

use Illuminate\Database\Connection;

final class ApplicationSchemaFingerprint
{
    public function __construct(private readonly ApplicationTableCatalog $catalog) {}

    public function forConnection(Connection $connection): string
    {
        $this->catalog->assertNoDrift($connection);
        $schema = $connection->getSchemaBuilder();
        $tables = [];

        foreach ($this->catalog->tableNames() as $table) {
            $columns = [];

            foreach ($schema->getColumns($table) as $column) {
                $columns[] = [
                    'name' => $column['name'],
                    'type' => $this->normalizeType($column['type']),
                    'type_name' => $this->normalizeType($column['type_name']),
                    'nullable' => $column['nullable'],
                    'auto_increment' => $column['auto_increment'],
                ];
            }

            /** @var list<array{name: string, columns: list<string>, type: string, unique: bool, primary: bool}> $indexes */
            $indexes = [];
            foreach ($schema->getIndexes($table) as $index) {
                $indexes[] = [
                    'name' => strtolower($index['name']),
                    'columns' => $this->stringList($index['columns']),
                    'type' => strtolower($index['type']),
                    'unique' => $index['unique'],
                    'primary' => $index['primary'],
                ];
            }
            usort($indexes, static fn (array $left, array $right): int => strcmp(
                $left['name'].'|'.implode(',', $left['columns']),
                $right['name'].'|'.implode(',', $right['columns']),
            ));

            /** @var list<array{name: string, columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_update: ?string, on_delete: ?string}> $foreignKeys */
            $foreignKeys = [];
            foreach ($schema->getForeignKeys($table) as $foreignKey) {
                $foreignKeys[] = [
                    'name' => strtolower((string) ($foreignKey['name'] ?? '')),
                    'columns' => $this->stringList($foreignKey['columns']),
                    'foreign_table' => strtolower($foreignKey['foreign_table']),
                    'foreign_columns' => $this->stringList($foreignKey['foreign_columns']),
                    'on_update' => $this->nullableString($foreignKey['on_update']),
                    'on_delete' => $this->nullableString($foreignKey['on_delete']),
                ];
            }
            usort($foreignKeys, static fn (array $left, array $right): int => strcmp(
                $left['name'].'|'.implode(',', $left['columns']).'|'.$left['foreign_table'],
                $right['name'].'|'.implode(',', $right['columns']).'|'.$right['foreign_table'],
            ));

            $tables[] = [
                'name' => $table,
                'columns' => $columns,
                'indexes' => $indexes,
                'foreign_keys' => $foreignKeys,
            ];
        }

        return hash('sha256', json_encode(
            ['tables' => $tables],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private function normalizeType(string $type): string
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower(trim($type)));

        return is_string($normalized) ? $normalized : strtolower(trim($type));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $result[] = strtolower((string) $item);
            }
        }

        return $result;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtolower((string) $value);
    }
}
