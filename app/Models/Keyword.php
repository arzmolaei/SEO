<?php

namespace App\Models;

use App\Enums\KeywordStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property string $display_text
 * @property string $normalized_text
 * @property KeywordStatus $status
 */
#[Fillable([
    'project_id',
    'display_text',
    'normalized_text',
    'status',
])]
class Keyword extends Model
{
    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<KeywordSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(KeywordSource::class);
    }

    protected function casts(): array
    {
        return [
            'status' => KeywordStatus::class,
        ];
    }
}
