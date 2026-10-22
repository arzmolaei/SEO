<?php

namespace App\Models;

use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property int $primary_keyword_id
 * @property int|null $created_by_id
 * @property string $name
 * @property KeywordIntent $intent
 * @property IntentConfidence $confidence
 * @property ClusterReviewState $review_state
 * @property bool $is_locked
 */
class KeywordCluster extends Model
{
    protected $fillable = ['project_id', 'primary_keyword_id', 'created_by_id', 'name', 'intent', 'confidence', 'review_state', 'is_locked'];

    protected static function booted(): void
    {
        static::updating(function (self $cluster): void {
            if ($cluster->isDirty('project_id')) {
                throw new DomainException('A cluster cannot move across projects.');
            }
            if ($cluster->isDirty('primary_keyword_id') && ! $cluster->members()->where('keyword_id', $cluster->primary_keyword_id)->exists()) {
                throw new DomainException('The primary keyword must already belong to this cluster.');
            }
        });
    }

    /** @return HasMany<KeywordClusterMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(KeywordClusterMember::class);
    }

    /** @return BelongsTo<Keyword, $this> */
    public function primaryKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'primary_keyword_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return ['intent' => KeywordIntent::class, 'confidence' => IntentConfidence::class,
            'review_state' => ClusterReviewState::class, 'is_locked' => 'boolean'];
    }
}
