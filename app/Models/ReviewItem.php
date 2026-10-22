<?php

namespace App\Models;

use App\Domain\Keywords\ClusterLifecycleState;
use App\Domain\Review\ReviewItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $project_id
 * @property int $keyword_id
 * @property ReviewItemType $type
 * @property string $status
 * @property string $fingerprint
 * @property array{original_text?: string, normalized_text?: string, intent?: string, confidence?: string, matched_rule_ids?: list<string>, evidence: list<string>, candidate?: array<string, mixed>, warnings?: list<array<string, mixed>>} $suggestion
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

    protected static function booted(): void
    {
        static::saved(function (self $review): void {
            if ($review->status !== 'pending' || (! $review->wasRecentlyCreated && ! $review->wasChanged(['status', 'fingerprint']))) {
                return;
            }

            DB::transaction(function () use ($review): void {
                $project = Project::query()->whereKey($review->project_id)->lockForUpdate()->first();
                if (! $project instanceof Project) {
                    return;
                }
                $cluster = KeywordCluster::query()
                    ->where('project_id', $review->project_id)
                    ->where('primary_keyword_id', $review->keyword_id)
                    ->whereNull('archived_at')
                    ->lockForUpdate()
                    ->first();
                if (! $cluster instanceof KeywordCluster || $cluster->lifecycle_state !== ClusterLifecycleState::Ready) {
                    return;
                }

                $cluster->invalidateReadiness();
                $cluster->save();
                $changeSet = ChangeSet::query()->create([
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->id,
                    'actor_id' => null,
                    'action' => 'keywords.cluster.readiness_invalidated',
                    'changes' => [[
                        'cluster_id' => $cluster->id,
                        'before' => ClusterLifecycleState::Ready->value,
                        'after' => ClusterLifecycleState::Planning->value,
                    ]],
                ]);
                ActivityEvent::query()->create([
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->id,
                    'actor_id' => null,
                    'change_set_id' => $changeSet->id,
                    'event' => 'keywords.cluster.readiness_invalidated',
                    'metadata' => [
                        'cluster_id' => $cluster->id,
                        'review_id' => $review->id,
                        'review_type' => $review->type->value,
                        'reason' => 'pending_review',
                    ],
                ]);
            });
        });
    }

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
