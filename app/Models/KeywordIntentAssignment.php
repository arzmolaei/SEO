<?php

namespace App\Models;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $keyword_id
 * @property int $project_id
 * @property int|null $actor_id
 * @property KeywordIntent $intent
 * @property IntentConfidence $confidence
 * @property string $source
 * @property array<string, mixed> $evidence
 * @property bool $is_current
 */
#[Fillable([
    'keyword_id',
    'project_id',
    'actor_id',
    'intent',
    'confidence',
    'source',
    'evidence',
    'is_current',
])]
class KeywordIntentAssignment extends Model
{
    /** @return BelongsTo<Keyword, $this> */
    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @param Builder<KeywordIntentAssignment> $query */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }

    protected function casts(): array
    {
        return [
            'intent' => KeywordIntent::class,
            'confidence' => IntentConfidence::class,
            'evidence' => 'array',
            'is_current' => 'boolean',
        ];
    }
}
