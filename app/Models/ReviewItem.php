<?php

namespace App\Models;

use App\Domain\Review\ReviewItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $keyword_id
 * @property ReviewItemType $type
 * @property string $status
 * @property string $fingerprint
 * @property array{original_text: string, normalized_text: string, intent: string, confidence: string, matched_rule_ids: list<string>, evidence: list<string>, candidate?: array<string, mixed>} $suggestion
 * @property string|null $resolution
 * @property int|null $resolved_by_id
 * @property Carbon|null $resolved_at
 */
class ReviewItem extends Model
{
    protected $fillable = [
        'project_id', 'keyword_id', 'type', 'status', 'fingerprint', 'suggestion',
        'resolution', 'resolved_by_id', 'resolved_at',
    ];

    /** @return BelongsTo<Keyword, $this> */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    protected function casts(): array
    {
        return ['type' => ReviewItemType::class, 'suggestion' => 'array', 'resolved_at' => 'datetime'];
    }
}
