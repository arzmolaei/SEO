<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\KeywordImportMapping;
use App\Domain\Keywords\StagedKeywordRowValidator;
use App\Models\ImportBatch;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class StageKeywordImport
{
    public function __construct(
        private ProjectPolicy $policy,
        private StagedKeywordRowValidator $rowValidator,
    ) {}

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
        if (! $this->policy->stageKeywordImports($actor, $project)) {
            throw new AuthorizationException;
        }

        $importMapping = KeywordImportMapping::fromArray($mapping);

        return DB::transaction(function () use (
            $project,
            $source,
            $originalFilename,
            $mapping,
            $rows,
            $importMapping,
        ): ImportBatch {
            $batchErrors = $importMapping->hasKeywordColumn() ? [] : ['mapping.keyword_required'];

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
            $keywordColumn = $importMapping->keywordColumn();

            foreach ($rows as $index => $row) {
                $keywordValue = $keywordColumn === null ? null : ($row[$keywordColumn] ?? null);
                $keyword = is_scalar($keywordValue) ? trim((string) $keywordValue) : null;
                $rowErrors = $this->rowValidator->validate($importMapping, $row);
                $isValid = $rowErrors === [];

                $validRows += $isValid ? 1 : 0;
                $invalidRows += $isValid ? 0 : 1;
                $errorCount += count($rowErrors);

                $batch->rows()->create([
                    'row_number' => $index + 1,
                    'keyword_original' => $keyword,
                    'raw_payload' => $row,
                    'mapped_payload' => $importMapping->mapRow($row),
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
}
