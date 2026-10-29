<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $created_by_id
 * @property string $kind
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $original_filename
 * @property int $row_count
 * @property int $skipped_count
 * @property CarbonImmutable $created_at
 */
#[Fillable(['project_id', 'created_by_id', 'kind', 'period_start', 'period_end', 'original_filename', 'row_count', 'skipped_count'])]
class SearchConsoleImport extends Model
{
    /** @return HasMany<SearchConsoleRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(SearchConsoleRow::class);
    }

    /** Inclusive number of days the export covers. */
    public function days(): int
    {
        return max(1, (int) $this->period_start->diffInDays($this->period_end) + 1);
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'row_count' => 'integer',
            'skipped_count' => 'integer',
        ];
    }
}
