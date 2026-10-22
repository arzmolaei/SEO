<?php

namespace App\Services\Review;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Domain\Review\ReviewItemType;
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

class UpsertIntentReviewItems
{
    public function __construct(
        private ProjectPolicy $policy,
        private ClassifyKeywordIntent $classifier,
        private AssignKeywordIntent $assignIntent,
    ) {}

    public function handle(User $actor, Project $project, Keyword $keyword): ?ReviewItem
    {
        if (! $this->policy->manageKeywords($actor, $project) || $keyword->project_id !== $project->id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $project, $keyword): ?ReviewItem {
            $keyword = Keyword::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($keyword->id);
            $review = ReviewItem::query()->where('project_id', $project->id)
                ->where('keyword_id', $keyword->id)->where('type', ReviewItemType::Intent)->lockForUpdate()->first();
            $manual = KeywordIntentAssignment::query()->where('project_id', $project->id)
                ->where('keyword_id', $keyword->id)->where('is_current', true)->where('source', 'user')->first();

            // A human's accepted intent remains authoritative across classifier refreshes.
            if ($manual instanceof KeywordIntentAssignment) {
                if ($review instanceof ReviewItem && $review->status === 'pending') {
                    $review->update(['status' => 'resolved', 'resolution' => 'superseded', 'resolved_by_id' => $manual->actor_id, 'resolved_at' => now()]);
                    $changeSet = ChangeSet::query()->create([
                        'workspace_id' => $project->workspace_id, 'project_id' => $project->id,
                        'actor_id' => $manual->actor_id, 'action' => 'review.intent.supersede',
                        'changes' => [['keyword_id' => $keyword->id, 'before' => 'pending', 'after' => 'superseded']],
                    ]);
                    ActivityEvent::query()->create([
                        'workspace_id' => $project->workspace_id, 'project_id' => $project->id,
                        'actor_id' => $manual->actor_id, 'change_set_id' => $changeSet->id, 'event' => 'review.intent.superseded',
                        'metadata' => ['review_item_id' => $review->id, 'assignment_id' => $manual->id, 'fingerprint' => $review->fingerprint],
                    ]);
                }

                return $review;
            }

            $result = $this->classifier->classify($keyword->display_text);
            $suggestion = $result->toArray();
            $fingerprint = hash('sha256', json_encode($suggestion, JSON_THROW_ON_ERROR));

            if ($review instanceof ReviewItem && $review->fingerprint === $fingerprint) {
                return $review;
            }

            if (
                $review === null
                && config('keyword-intelligence.intent.auto_apply_high_confidence', false) === true
                && $result->confidence === IntentConfidence::High
                && ! in_array($result->intent, [KeywordIntent::Unknown, KeywordIntent::Mixed], true)
            ) {
                $this->assignIntent->handle($actor, $project, $keyword, $result->intent, $result->confidence, 'rule', $suggestion);

                return null;
            }

            return ReviewItem::query()->updateOrCreate([
                'project_id' => $project->id,
                'keyword_id' => $keyword->id,
                'type' => ReviewItemType::Intent,
            ], [
                'status' => 'pending',
                'fingerprint' => $fingerprint,
                'suggestion' => $suggestion,
                'resolution' => null,
                'resolved_by_id' => null,
                'resolved_at' => null,
            ]);
        });
    }
}
