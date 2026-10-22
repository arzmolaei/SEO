<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $keyword_id
 * @property int $keyword_source_id
 * @property string $metric
 * @property string|null $value
 * @property CarbonImmutable $observed_at
 */
#[Fillable([
    'keyword_id',
    'keyword_source_id',
    'metric',
    'value',
    'observed_at',
])]
class KeywordMetric extends Model
{
    /** @return BelongsTo<Keyword, $this> */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    /** @return BelongsTo<KeywordSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(KeywordSource::class, 'keyword_source_id');
    }

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'observed_at' => 'immutable_datetime',
        ];
    }
}
