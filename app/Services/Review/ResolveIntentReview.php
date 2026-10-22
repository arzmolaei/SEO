<?php

namespace App\Services\Review;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Keyword;
use App\Models\KeywordIntentAssignment;
use App\Models\Project;
use App\Models\ReviewItem;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\AssignKeywordIntent;
use App\Services\Keywords\ClassifyKeywordIntent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ResolveIntentReview
{
    public function __construct(private ProjectPolicy $policy, private AssignKeywordIntent $assignIntent, private ClassifyKeywordIntent $classifier) {}

    public function handle(
        User $actor,
        Project $project,
        ReviewItem $review,
        string $fingerprint,
        string $decision,
        ?KeywordIntent $intent = null,
    ): ReviewItem {
        if (! $this->policy->manageKeywords($actor, $project) || $review->project_id !== $project->id) {
            throw new AuthorizationException;
        }
        if (! in_array($decision, ['accept', 'change', 'skip'], true) || ($decision === 'change' && $intent === null)) {
            throw new InvalidArgumentException('Unsupported review decision.');
        }

        return DB::transaction(function () use ($actor, $project, $review, $fingerprint, $decision, $intent): ReviewItem {
            $keyword = Keyword::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($review->keyword_id);
            $review = ReviewItem::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($review->id);
            if ($review->status !== 'pending' || ! hash_equals($review->fingerprint, $fingerprint)) {
                throw new ConflictHttpException('Review evidence changed. Reload before deciding.');
            }

            $currentFingerprint = hash('sha256', json_encode($this->classifier->classify($keyword->display_text)->toArray(), JSON_THROW_ON_ERROR));
            $manual = KeywordIntentAssignment::query()->where('project_id', $project->id)
                ->where('keyword_id', $keyword->id)->where('is_current', true)->where('source', 'user')->exists();
            if ($manual || ! hash_equals($review->fingerprint, $currentFingerprint)) {
                throw new ConflictHttpException('Keyword input or accepted intent changed. Refresh the queue.');
            }

            if ($decision !== 'skip') {
                $selected = $decision === 'accept' ? KeywordIntent::from($review->suggestion['intent']) : $intent;
                $this->assignIntent->handle($actor, $project, $keyword, $selected, IntentConfidence::High, 'user', [
                    'review_item_id' => $review->id,
                    'fingerprint' => $review->fingerprint,
                    'suggestion' => $review->suggestion,
                ]);
            }

            $review->update(['status' => 'resolved', 'resolution' => $decision, 'resolved_by_id' => $actor->id, 'resolved_at' => now()]);
            $changeSet = ChangeSet::query()->create([
                'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
                'action' => 'review.intent.resolve',
                'changes' => [['keyword_id' => $keyword->id, 'before' => 'pending', 'after' => $decision]],
            ]);
            ActivityEvent::query()->create([
                'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
                'change_set_id' => $changeSet->id, 'event' => 'review.intent.resolved',
                'metadata' => ['review_item_id' => $review->id, 'decision' => $decision, 'fingerprint' => $fingerprint, 'suggestion' => $review->suggestion],
            ]);

            return $review;
        });
    }
}
