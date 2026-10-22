<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'keyword_id',
    'import_batch_id',
    'staged_keyword_row_id',
    'source',
    'original_text',
    'raw_payload',
])]
class KeywordSource extends Model
{
    /** @return BelongsTo<Keyword, $this> */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    /** @return BelongsTo<StagedKeywordRow, $this> */
    public function stagedKeywordRow(): BelongsTo
    {
        return $this->belongsTo(StagedKeywordRow::class);
    }

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
        ];
    }
}
