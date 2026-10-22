<?php

namespace App\Services\Keywords;

use App\Models\ImportBatch;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class StageKeywordImport
{
    public function __construct(private ProjectPolicy $policy) {}

    /**
     * @param  array<string, string>  $mapping
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws AuthorizationException
     */
    public function handle(
        User $actor,
        Project $project,
        string $source,
        string $originalFilename,
        array $mapping,
        array $rows,
    ): ImportBatch {
        if (! $this->policy->update($actor, $project)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($project, $source, $originalFilename, $mapping, $rows): ImportBatch {
            $keywordColumn = trim($mapping['keyword'] ?? '');
            $volumeColumn = trim($mapping['volume'] ?? '');
            $hasKeywordMapping = $keywordColumn !== '';
            $batchErrors = $hasKeywordMapping ? [] : ['mapping.keyword_required'];

            $batch = ImportBatch::query()->create([
                'project_id' => $project->id,
                'source' => $source,
                'original_filename' => $originalFilename,
                'mapping' => $mapping,
                'status' => 'staged',
                'total_rows' => count($rows),
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'warning_count' => 0,
                'error_count' => count($batchErrors),
                'warnings' => [],
                'errors' => $batchErrors,
            ]);

            $validRows = 0;
            $invalidRows = 0;
            $errorCount = count($batchErrors);

            foreach ($rows as $index => $row) {
                $keywordValue = $hasKeywordMapping ? ($row[$keywordColumn] ?? null) : null;
                $keyword = is_scalar($keywordValue) ? trim((string) $keywordValue) : null;
                $rowErrors = $hasKeywordMapping ? [] : ['keyword_mapping_missing'];

                if ($hasKeywordMapping && ($keyword === null || $keyword === '')) {
                    $rowErrors[] = 'keyword_required';
                }

                if ($volumeColumn !== '') {
                    $volumeValue = $row[$volumeColumn] ?? null;

                    if ($this->hasInvalidNumericValue($volumeValue)) {
                        $rowErrors[] = 'volume_not_numeric';
                    }
                }

                $mappedPayload = [];

                foreach ($mapping as $field => $column) {
                    $mappedPayload[$field] = $row[$column] ?? null;
                }

                $isValid = $rowErrors === [];
                $validRows += $isValid ? 1 : 0;
                $invalidRows += $isValid ? 0 : 1;
                $errorCount += count($rowErrors);

                $batch->rows()->create([
                    'row_number' => $index + 1,
                    'keyword_original' => $keyword,
                    'raw_payload' => $row,
                    'mapped_payload' => $mappedPayload,
                    'is_valid' => $isValid,
                    'warnings' => [],
                    'errors' => $rowErrors,
                ]);
            }

            $batch->update([
                'valid_rows' => $validRows,
                'invalid_rows' => $invalidRows,
                'error_count' => $errorCount,
            ]);

            return $batch;
        });
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
