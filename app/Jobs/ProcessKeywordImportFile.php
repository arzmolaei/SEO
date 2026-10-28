<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\Keywords\KeywordImportFiles;
use App\Services\Keywords\ProcessKeywordCsvImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stages an uploaded original file. Retries resume from the batch checkpoint,
 * so a worker killed mid-file never re-stages completed rows.
 */
class ProcessKeywordImportFile implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly int $batchId) {}

    public function handle(ProcessKeywordCsvImport $process): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch === null || ! in_array($batch->status, ['queued', 'processing'], true)) {
            return;
        }
        $process->handle($batch, app(KeywordImportFiles::class)->absolutePath($batch));
    }

    public function failed(): void
    {
        ImportBatch::query()
            ->whereKey($this->batchId)
            ->whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'failed', 'failed_at' => now(), 'failure_message' => 'keyword_import_processing_failed']);
    }
}
