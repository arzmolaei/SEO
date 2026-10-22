<?php

namespace App\Services\Review;

use App\Domain\Review\ReviewItemType;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Project;
use App\Models\ReviewItem;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\DetectKeywordMappingConflicts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ResolveMappingConflictReview
{
    public function __construct(
        private ProjectPolicy $policy,
        private DetectKeywordMappingConflicts $detector,
    ) {}

    public function handle(
        User $actor,
        Project $project,
        ReviewItem $review,
        string $fingerprint,
        string $decision,
    ): ReviewItem {
        if (! $this->policy->manageKeywords($actor, $project) || $review->project_id !== $project->id) {
            throw new AuthorizationException;
        }
        if (! in_array($decision, ['accept', 'skip'], true)) {
            throw new InvalidArgumentException('Unsupported mapping conflict decision.');
        }

        return DB::transaction(function () use ($actor, $project, $review, $fingerprint, $decision): ReviewItem {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $review = ReviewItem::query()
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->findOrFail($review->id);

            if ($review->type !== ReviewItemType::MappingConflict || $review->status !== 'pending' || ! hash_equals($review->fingerprint, $fingerprint)) {
                throw new ConflictHttpException('Review evidence changed. Reload before deciding.');
            }

            $currentFingerprint = $this->detector->currentFingerprint($actor, $project, $review->keyword_id);
            if ($currentFingerprint === null || ! hash_equals($review->fingerprint, $currentFingerprint)) {
                throw new ConflictHttpException('Mapping evidence changed. Refresh the queue.');
            }

            $review->update([
                'status' => 'resolved',
                'resolution' => $decision,
                'resolved_by_id' => $actor->id,
                'resolved_at' => now(),
            ]);
            $changeSet = ChangeSet::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'actor_id' => $actor->id,
                'action' => 'review.mapping_conflict.resolve',
                'changes' => [[
                    'keyword_id' => $review->keyword_id,
                    'before' => 'pending',
                    'after' => $decision,
                ]],
            ]);
            ActivityEvent::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'actor_id' => $actor->id,
                'change_set_id' => $changeSet->id,
                'event' => 'review.mapping_conflict.resolved',
                'metadata' => [
                    'review_item_id' => $review->id,
                    'decision' => $decision,
                    'fingerprint' => $fingerprint,
                    'suggestion' => $review->suggestion,
                ],
            ]);

            return $review;
        });
    }
}
