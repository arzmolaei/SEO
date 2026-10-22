<?php

namespace App\Models;

use App\Domain\Keywords\ClusterLifecycleState;
use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $revision
 * @property Carbon|null $archived_at
 * @property int $id
 * @property int $project_id
 * @property int $primary_keyword_id
 * @property int|null $created_by_id
 * @property string $name
 * @property KeywordIntent $intent
 * @property IntentConfidence $confidence
 * @property ClusterReviewState $review_state
 * @property int|null $business_relevance
 * @property ClusterLifecycleState $lifecycle_state
 * @property string $origin
 * @property string|null $automation_key
 * @property bool $is_locked
 */
class KeywordCluster extends Model
{
    protected $fillable = [
        'project_id', 'primary_keyword_id', 'created_by_id', 'name', 'intent', 'confidence',
        'review_state', 'business_relevance', 'lifecycle_state', 'is_locked', 'origin',
        'automation_key', 'revision', 'archived_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $cluster): void {
            if ($cluster->isDirty('project_id')) {
                throw new DomainException('A cluster cannot move across projects.');
            }
            if ($cluster->archived_at === null && $cluster->isDirty(['primary_keyword_id', 'archived_at']) && ! $cluster->members()->where('keyword_id', $cluster->primary_keyword_id)->exists()) {
                throw new DomainException('The primary keyword must already belong to this cluster.');
            }
            if ($cluster->isDirty('business_relevance') && $cluster->business_relevance !== null
                && ($cluster->business_relevance < 1 || $cluster->business_relevance > 5)) {
                throw new DomainException('Business relevance must be between 1 and 5.');
            }
            if ($cluster->isDirty('lifecycle_state') && $cluster->lifecycle_state === ClusterLifecycleState::Ready) {
                $cluster->assertCanBecomeReady();
            }
        });
    }

    public function invalidateReadiness(): void
    {
        if ($this->lifecycle_state === ClusterLifecycleState::Ready) {
            $this->lifecycle_state = ClusterLifecycleState::Planning;
        }
    }

    private function assertCanBecomeReady(): void
    {
        if ($this->archived_at !== null) {
            throw new DomainException('Archived clusters cannot become ready.');
        }
        if ($this->review_state !== ClusterReviewState::Reviewed) {
            throw new DomainException('Cluster review must be completed before it can become ready.');
        }
        if (! ClusterTarget::query()
            ->where('project_id', $this->project_id)
            ->where('keyword_cluster_id', $this->id)
            ->exists()) {
            throw new DomainException('Cluster mapping is required before it can become ready.');
        }
        if (ReviewItem::query()
            ->where('project_id', $this->project_id)
            ->where('keyword_id', $this->primary_keyword_id)
            ->where('status', 'pending')
            ->exists()) {
            throw new DomainException('Required reviews must be resolved before the cluster can become ready.');
        }
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
        return [
            'intent' => KeywordIntent::class,
            'confidence' => IntentConfidence::class,
            'review_state' => ClusterReviewState::class,
            'business_relevance' => 'integer',
            'lifecycle_state' => ClusterLifecycleState::class,
            'is_locked' => 'boolean',
            'revision' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
