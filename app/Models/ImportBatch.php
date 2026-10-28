<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $source
 * @property string $original_filename
 * @property array<string, string> $mapping
 * @property string $status
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $checkpoint_row
 * @property int $valid_rows
 * @property int $invalid_rows
 * @property int $warning_count
 * @property int $error_count
 * @property list<string>|null $warnings
 * @property list<string>|null $errors
 * @property string|null $failure_message
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $committed_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $source_path
 * @property int|null $source_bytes
 * @property CarbonImmutable|null $commit_requested_at
 * @property int|null $commit_requested_by
 * @property CarbonImmutable|null $discarded_at
 * @property int|null $discarded_by
 */
#[Fillable([
    'project_id',
    'source',
    'original_filename',
    'source_path',
    'source_bytes',
    'mapping',
    'status',
    'total_rows',
    'processed_rows',
    'checkpoint_row',
    'valid_rows',
    'invalid_rows',
    'warning_count',
    'error_count',
    'warnings',
    'errors',
    'started_at',
    'completed_at',
    'committed_at',
    'failed_at',
    'failure_message',
    'commit_requested_at',
    'commit_requested_by',
    'discarded_at',
    'discarded_by',
])]
class ImportBatch extends Model
{
    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<StagedKeywordRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(StagedKeywordRow::class);
    }

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'warnings' => 'array',
            'errors' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'committed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'commit_requested_at' => 'immutable_datetime',
            'discarded_at' => 'immutable_datetime',
            'source_bytes' => 'integer',
            'commit_requested_by' => 'integer',
            'discarded_by' => 'integer',
        ];
    }
}
