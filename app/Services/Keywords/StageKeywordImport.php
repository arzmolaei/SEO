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
            $batch = ImportBatch::query()->create([
                'project_id' => $project->id,
                'source' => $source,
                'original_filename' => $originalFilename,
                'mapping' => $mapping,
                'status' => 'staged',
                'total_rows' => count($rows),
                'valid_rows' => count($rows),
                'invalid_rows' => 0,
                'warning_count' => 0,
                'error_count' => 0,
                'warnings' => [],
                'errors' => [],
            ]);

            $keywordColumn = $mapping['keyword'] ?? '';

            foreach ($rows as $index => $row) {
                $keywordValue = $row[$keywordColumn] ?? null;
                $keyword = is_scalar($keywordValue) ? trim((string) $keywordValue) : null;

                $mappedPayload = [];

                foreach ($mapping as $field => $column) {
                    $mappedPayload[$field] = $row[$column] ?? null;
                }

                $batch->rows()->create([
                    'row_number' => $index + 1,
                    'keyword_original' => $keyword,
                    'raw_payload' => $row,
                    'mapped_payload' => $mappedPayload,
                    'is_valid' => true,
                    'warnings' => [],
                    'errors' => [],
                ]);
            }

            return $batch;
        });
    }
}
