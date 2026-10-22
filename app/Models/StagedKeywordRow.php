<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $import_batch_id
 * @property int $row_number
 * @property string|null $keyword_original
 * @property string|null $row_fingerprint
 * @property bool $is_valid
 */
#[Fillable([
    'import_batch_id',
    'row_number',
    'keyword_original',
    'row_fingerprint',
    'raw_payload',
    'mapped_payload',
    'is_valid',
    'warnings',
    'errors',
])]
class StagedKeywordRow extends Model
{
    /** @return BelongsTo<ImportBatch, $this> */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'mapped_payload' => 'array',
            'is_valid' => 'boolean',
            'warnings' => 'array',
            'errors' => 'array',
        ];
    }
}
