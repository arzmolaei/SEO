<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\PersianKeywordNormalizer;
use App\Models\ImportBatch;
use App\Models\Keyword;
use App\Models\KeywordMetric;
use App\Models\KeywordSource;
use App\Models\Project;
use App\Models\StagedKeywordRow;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

class CommitKeywordImportBatch
{
    public function __construct(
        private PersianKeywordNormalizer $normalizer,
        private ProjectPolicy $projectPolicy,
    ) {}

    /** @throws AuthorizationException */
    public function handle(User $actor, ImportBatch $batch): ImportBatch
    {
        $project = Project::query()->findOrFail($batch->project_id);

        if (! $this->projectPolicy->stageKeywordImports($actor, $project)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($batch): ImportBatch {
            $lockedBatch = ImportBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBatch->status === 'committed') {
                return $lockedBatch;
            }

            if ($lockedBatch->status !== 'staged') {
                throw new LogicException('Only staged keyword imports can be committed.');
            }

            /** @var StagedKeywordRow $row */
            foreach ($lockedBatch->rows()->where('is_valid', true)->orderBy('row_number')->cursor() as $row) {
                $original = $row->keyword_original;

                if ($original === null || trim($original) === '') {
                    continue;
                }

                $normalized = $this->normalizer->normalize($original);
                $keyword = Keyword::query()->firstOrCreate(
                    [
                        'project_id' => $lockedBatch->project_id,
                        'normalized_text' => $normalized,
                    ],
                    [
                        'display_text' => $original,
                    ],
                );

                $keywordSource = KeywordSource::query()->firstOrCreate(
                    [
                        'staged_keyword_row_id' => $row->id,
                    ],
                    [
                        'keyword_id' => $keyword->id,
                        'import_batch_id' => $lockedBatch->id,
                        'source' => $lockedBatch->source,
                        'original_text' => $original,
                        'raw_payload' => $row->raw_payload,
                    ],
                );

                $volume = $this->numericMetric($row, 'volume');

                if ($volume !== null) {
                    KeywordMetric::query()->firstOrCreate(
                        [
                            'keyword_source_id' => $keywordSource->id,
                            'metric' => 'volume',
                        ],
                        [
                            'keyword_id' => $keyword->id,
                            'value' => $volume,
                            'observed_at' => now(),
                        ],
                    );
                }
            }

            $lockedBatch->update([
                'status' => 'committed',
                'committed_at' => now(),
            ]);

            return $lockedBatch->refresh();
        });
    }

    private function numericMetric(StagedKeywordRow $row, string $field): ?string
    {
        $value = ($row->mapped_payload ?? [])[$field] ?? null;

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && is_numeric($value) ? $value : null;
    }
}
