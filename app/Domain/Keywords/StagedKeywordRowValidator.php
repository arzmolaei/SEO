<?php

namespace App\Domain\Keywords;

final class StagedKeywordRowValidator
{
    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function validate(KeywordImportMapping $mapping, array $row): array
    {
        if ($mapping->hasSourceColumnConflict()) {
            return ['mapping_conflict'];
        }

        if (! $mapping->hasKeywordColumn()) {
            return ['keyword_mapping_missing'];
        }

        $errors = [];
        $keywordColumn = $mapping->keywordColumn();
        $keywordValue = $keywordColumn === null ? null : ($row[$keywordColumn] ?? null);
        $keyword = is_scalar($keywordValue) ? trim((string) $keywordValue) : null;

        if ($keyword === null || $keyword === '') {
            $errors[] = 'keyword_required';
        }

        $volumeColumn = $mapping->volumeColumn();

        if ($volumeColumn !== null && $this->hasInvalidNumericValue($row[$volumeColumn] ?? null)) {
            $errors[] = 'volume_not_numeric';
        }

        return $errors;
    }

    private function hasInvalidNumericValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (! is_scalar($value)) {
            return true;
        }

        return ! is_numeric(trim((string) $value));
    }
}
