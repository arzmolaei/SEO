<?php

namespace App\Domain\Keywords;

final class KeywordImportMapping
{
    /** @param array<string, string> $columns */
    private function __construct(private array $columns) {}

    /** @param array<string, string> $mapping */
    public static function fromArray(array $mapping): self
    {
        return new self($mapping);
    }

    public function keywordColumn(): ?string
    {
        return $this->column('keyword');
    }

    public function volumeColumn(): ?string
    {
        return $this->column('volume');
    }

    public function hasKeywordColumn(): bool
    {
        return $this->keywordColumn() !== null;
    }

    public function hasSourceColumnConflict(): bool
    {
        $columns = [];

        foreach ($this->columns as $column) {
            $column = trim($column);

            if ($column !== '') {
                $columns[] = $column;
            }
        }

        return count($columns) !== count(array_unique($columns));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function mapRow(array $row): array
    {
        $mapped = [];

        foreach ($this->columns as $field => $column) {
            $mapped[$field] = $row[$column] ?? null;
        }

        return $mapped;
    }

    private function column(string $field): ?string
    {
        $column = trim($this->columns[$field] ?? '');

        return $column === '' ? null : $column;
    }
}
