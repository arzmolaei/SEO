<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $source
 * @property string $original_filename
 * @property string $status
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $invalid_rows
 * @property int $warning_count
 * @property int $error_count
 */
#[Fillable([
    'project_id',
    'source',
    'original_filename',
    'mapping',
    'status',
    'total_rows',
    'valid_rows',
    'invalid_rows',
    'warning_count',
    'error_count',
    'warnings',
    'errors',
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
        ];
    }
}
