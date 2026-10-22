<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\KeywordImportMapping;
use App\Domain\Keywords\StagedKeywordRowValidator;
use App\Models\ImportBatch;
use App\Models\StagedKeywordRow;
use InvalidArgumentException;
use Throwable;

class ResumableKeywordImportStager
{
    public function __construct(private StagedKeywordRowValidator $rowValidator) {}

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     *
     * @throws Throwable
     */
    public function handle(ImportBatch $batch, iterable $rows, int $checkpointSize = 500): ImportBatch
    {
        if ($checkpointSize < 1) {
            throw new InvalidArgumentException('Checkpoint size must be at least one row.');
        }

        $batch->refresh();
        $mapping = KeywordImportMapping::fromArray($batch->mapping);
        $batchErrors = $this->batchErrors($mapping);
        $checkpoint = $batch->checkpoint_row;
        $rowNumber = 0;

        $batch->update([
            'status' => 'processing',
            'started_at' => $batch->started_at ?? now(),
            'completed_at' => null,
            'failed_at' => null,
            'failure_message' => null,
            'errors' => $batchErrors,
        ]);

        try {
            foreach ($rows as $row) {
                $rowNumber++;

                if ($rowNumber <= $checkpoint) {
                    continue;
                }

                $this->stageRow($batch, $mapping, $rowNumber, $row);

                if ($rowNumber % $checkpointSize === 0) {
                    $this->syncProgress($batch, $batchErrors);
                }
            }

            $this->syncProgress($batch, $batchErrors);

            $batch->update([
                'status' => 'staged',
                'total_rows' => $rowNumber,
                'completed_at' => now(),
                'failed_at' => null,
                'failure_message' => null,
            ]);

            return $batch->refresh();
        } catch (Throwable $exception) {
            $this->syncProgress($batch, $batchErrors);

            $batch->update([
                'status' => 'failed',
                'failed_at' => now(),
                'completed_at' => null,
                'failure_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $row */
    private function stageRow(
        ImportBatch $batch,
        KeywordImportMapping $mapping,
        int $rowNumber,
        array $row,
    ): void {
        $keywordColumn = $mapping->keywordColumn();
        $keywordValue = $keywordColumn === null ? null : ($row[$keywordColumn] ?? null);
        $keyword = is_scalar($keywordValue) ? trim((string) $keywordValue) : null;
        $rowErrors = $this->rowValidator->validate($mapping, $row);
        $fingerprint = hash('sha256', serialize($row));
        $isDuplicate = $batch->rows()
            ->where('row_fingerprint', $fingerprint)
            ->where('row_number', '<>', $rowNumber)
            ->exists();

        $batch->rows()->updateOrCreate(
            ['row_number' => $rowNumber],
            [
                'keyword_original' => $keyword,
                'row_fingerprint' => $fingerprint,
                'raw_payload' => $row,
                'mapped_payload' => $mapping->mapRow($row),
                'is_valid' => $rowErrors === [],
                'warnings' => $isDuplicate ? ['duplicate_row'] : [],
                'errors' => $rowErrors,
            ],
        );
    }

    /** @param list<string> $batchErrors */
    private function syncProgress(ImportBatch $batch, array $batchErrors): void
    {
        $processedRows = $batch->rows()->count();
        $checkpointRow = (int) ($batch->rows()->max('row_number') ?? 0);
        $validRows = $batch->rows()->where('is_valid', true)->count();
        $invalidRows = $batch->rows()->where('is_valid', false)->count();
        $warningCount = 0;
        $errorCount = count($batchErrors);

        /** @var StagedKeywordRow $row */
        foreach ($batch->rows()->select(['id', 'warnings', 'errors'])->cursor() as $row) {
            $warningCount += count($row->warnings ?? []);
            $errorCount += count($row->errors ?? []);
        }

        $batch->update([
            'processed_rows' => $processedRows,
            'checkpoint_row' => $checkpointRow,
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'warning_count' => $warningCount,
            'error_count' => $errorCount,
        ]);
    }

    /** @return list<string> */
    private function batchErrors(KeywordImportMapping $mapping): array
    {
        $errors = [];

        if (! $mapping->hasKeywordColumn()) {
            $errors[] = 'mapping.keyword_required';
        }

        if ($mapping->hasSourceColumnConflict()) {
            $errors[] = 'mapping.source_column_conflict';
        }

        return $errors;
    }
}
