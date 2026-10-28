<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Keywords\CommitKeywordImportBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Commits a staged batch as the member who requested it (idempotent). */
class CommitKeywordImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly int $batchId) {}

    public function handle(CommitKeywordImportBatch $commit): void
    {
        $batch = ImportBatch::query()->find($this->batchId);
        if ($batch === null || $batch->status !== 'staged' || $batch->commit_requested_at === null) {
            return;
        }
        $actor = User::query()->find($batch->commit_requested_by);
        if (! $actor instanceof User) {
            $this->failed();

            return;
        }
        $commit->handle($actor, $batch);
    }

    public function failed(): void
    {
        ImportBatch::query()
            ->whereKey($this->batchId)
            ->where('status', 'staged')
            ->update(['commit_requested_at' => null, 'commit_requested_by' => null]);
    }
}
