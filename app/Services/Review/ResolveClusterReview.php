<?php

namespace App\Services\Review;

use App\Domain\Keywords\KeywordClusterCandidate;
use App\Domain\Review\ReviewItemType;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Keyword;
use App\Models\Project;
use App\Models\ReviewItem;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\ApplyKeywordClusterCandidates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ResolveClusterReview
{
    public function __construct(private ProjectPolicy $policy, private ApplyKeywordClusterCandidates $apply) {}

    public function handle(User $actor, Project $project, ReviewItem $review, string $fingerprint, string $decision): void
    {
        if (! $this->policy->manageKeywords($actor, $project) || $review->project_id !== $project->id) {
            throw new AuthorizationException;
        }
        if (! in_array($decision, ['accept', 'skip'], true)) {
            throw new InvalidArgumentException('Unsupported cluster decision.');
        }
        $payload = $review->suggestion['candidate'] ?? null;
        if ($review->type !== ReviewItemType::Cluster || $payload === null) {
            throw new ConflictHttpException('Not a cluster suggestion.');
        }
        $candidate = KeywordClusterCandidate::fromArray($payload);
        DB::transaction(function () use ($actor, $project, $review, $candidate, $fingerprint, $decision): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            Keyword::query()->where('project_id', $project->id)->whereIn('id', $candidate->keywordIds)->orderBy('id')->lockForUpdate()->get();
            $review = ReviewItem::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($review->id);
            if ($review->status !== 'pending' || ! hash_equals($review->fingerprint, $fingerprint) || ! hash_equals($fingerprint, $candidate->fingerprint())) {
                throw new ConflictHttpException('Cluster suggestion changed.');
            }
            if ($decision === 'accept') {
                $this->apply->handle($actor, $project, [$candidate], humanApproved: true);
            }
            $review->update(['status' => 'resolved', 'resolution' => $decision, 'resolved_by_id' => $actor->id, 'resolved_at' => now()]);
            $set = ChangeSet::query()->create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
                'action' => 'review.cluster.resolve', 'changes' => [['keyword_id' => $candidate->primaryKeywordId, 'before' => 'pending', 'after' => $decision]]]);
            ActivityEvent::query()->create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
                'change_set_id' => $set->id, 'event' => 'review.cluster.resolved', 'metadata' => ['review_item_id' => $review->id, 'decision' => $decision, 'fingerprint' => $fingerprint]]);
        });
    }
}
