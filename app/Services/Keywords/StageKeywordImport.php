<?php

namespace App\Services\Keywords;

use App\Models\ImportBatch;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class StageKeywordImport
{
    /**
     * @param  array<string, string>  $mapping
     * @param  list<array<string, mixed>>  $rows
     */
    public function handle(
        Project $project,
        string $source,
        string $originalFilename,
        array $mapping,
        array $rows,
    ): ImportBatch {
        return DB::transaction(function () use ($project, $source, $originalFilename, $mapping, $rows): ImportBatch {
            $keywordColumn = trim($mapping['keyword'] ?? '');
            $hasKeywordMapping = $keywordColumn !== '';
            $batchErrors = $hasKeywordMapping ? [] : ['mapping.keyword_required'];
            $rowErrorCount = $hasKeywordMapping ? 0 : count($rows);

            $batch = ImportBatch::query()->create([
                'project_id' => $project->id,
                'source' => $source,
                'original_filename' => $originalFilename,
                'mapping' => $mapping,
                'status' => 'staged',
                'total_rows' => count($rows),
                'valid_rows' => $hasKeywordMapping ? count($rows) : 0,
                'invalid_rows' => $hasKeywordMapping ? 0 : count($rows),
                'warning_count' => 0,
                'error_count' => count($batchErrors) + $rowErrorCount,
                'warnings' => [],
                'errors' => $batchErrors,
            ]);

            foreach ($rows as $index => $row) {
                $keywordValue = $hasKeywordMapping ? ($row[$keywordColumn] ?? null) : null;
                $keyword = is_scalar($keywordValue) ? trim((string) $keywordValue) : null;
                $rowErrors = $hasKeywordMapping ? [] : ['keyword_mapping_missing'];

                $mappedPayload = [];

                foreach ($mapping as $field => $column) {
                    $mappedPayload[$field] = $row[$column] ?? null;
                }

                $batch->rows()->create([
                    'row_number' => $index + 1,
                    'keyword_original' => $keyword,
                    'raw_payload' => $row,
                    'mapped_payload' => $mappedPayload,
                    'is_valid' => $rowErrors === [],
                    'warnings' => [],
                    'errors' => $rowErrors,
                ]);
            }

            return $batch;
        });
    }
}
