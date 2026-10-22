<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordClusterCandidate;
use App\Domain\Review\ReviewItemType;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordClusterMember;
use App\Models\KeywordIntentAssignment;
use App\Models\Project;
use App\Models\ReviewItem;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ApplyKeywordClusterCandidates
{
    public function __construct(private ProjectPolicy $policy, private GenerateKeywordClusterCandidates $generator,
        private CreateKeywordCluster $create, private ClassifyKeywordIntent $classifier) {}

    /** @param list<KeywordClusterCandidate> $candidates */
    public function handle(User $actor, Project $project, array $candidates, bool $humanApproved = false): void
    {
        if (! $this->policy->manageKeywords($actor, $project)) {
            throw new AuthorizationException;
        }
        foreach ($candidates as $candidate) {
            if ($candidate->projectId !== $project->id) {
                throw new AuthorizationException;
            }
        }
        DB::transaction(function () use ($actor, $project, $candidates, $humanApproved): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $ids = [];
            foreach ($candidates as $candidate) {
                array_push($ids, ...$candidate->keywordIds);
            }
            $ids = array_values(array_unique($ids));
            sort($ids, SORT_NUMERIC);
            $keywords = Keyword::query()->where('project_id', $project->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($keywords->count() !== count($ids)) {
                throw new AuthorizationException;
            }
            $clusterIds = KeywordClusterMember::query()->whereIn('keyword_id', $ids)->pluck('keyword_cluster_id')->unique()->sort()->values();
            KeywordCluster::query()->whereIn('id', $clusterIds)->orderBy('id')->lockForUpdate()->get();
            $members = KeywordClusterMember::query()->whereIn('keyword_id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('keyword_id');
            foreach ($candidates as $candidate) {
                $rows = [];
                foreach ($candidate->keywordIds as $id) {
                    $keyword = $keywords->get($id);
                    if (! $keyword instanceof Keyword) {
                        throw new AuthorizationException;
                    }
                    $assignment = KeywordIntentAssignment::query()->where('project_id', $project->id)->where('keyword_id', $id)->where('is_current', true)->first();
                    $rows[] = ['id' => $id, 'project_id' => $project->id, 'text' => $keyword->display_text,
                        'intent' => $assignment instanceof KeywordIntentAssignment ? $assignment->intent : $this->classifier->classify($keyword->display_text)->intent];
                }
                $fresh = $this->generator->generate($rows);
                if (count($fresh) !== 1 || ! hash_equals($candidate->fingerprint(), $fresh[0]->fingerprint())) {
                    throw new ConflictHttpException('Cluster evidence changed. Regenerate candidates.');
                }
                // Never move a current membership, regardless of origin/lock state.
                if (collect($candidate->keywordIds)->contains(fn (int $id): bool => $members->has($id))) {
                    if ($humanApproved) {
                        throw new ConflictHttpException('Cluster memberships changed after this suggestion.');
                    }

                    continue;
                }
                $review = ReviewItem::query()->where('project_id', $project->id)->where('type', ReviewItemType::Cluster)
                    ->where('keyword_id', $candidate->primaryKeywordId)->lockForUpdate()->first();
                if (! $humanApproved && $review instanceof ReviewItem && $review->fingerprint === $candidate->fingerprint()) {
                    continue;
                }
                if ($humanApproved || ($review === null && $candidate->confidence === IntentConfidence::High
                    && config('keyword-intelligence.clustering.auto_apply_high_confidence', false) === true)) {
                    $primary = $keywords->get($candidate->primaryKeywordId);
                    if (! $primary instanceof Keyword) {
                        throw new AuthorizationException;
                    }
                    $cluster = $this->create->handle($actor, $project, $candidate->name, $primary, $candidate->keywordIds,
                        $candidate->intent, $candidate->confidence, origin: $humanApproved ? 'user' : 'lexical',
                        automationKey: $humanApproved ? null : $candidate->fingerprint());
                    foreach ($cluster->members()->get() as $member) {
                        $members->put($member->keyword_id, $member);
                    }
                    $this->audit($actor, $project, $candidate, $humanApproved ? 'keywords.cluster.confirmed' : 'keywords.cluster.automated', ['cluster_id' => $cluster->id, 'source' => $humanApproved ? 'user' : 'lexical']);

                    continue;
                }
                $review = ReviewItem::query()->updateOrCreate(['project_id' => $project->id, 'type' => ReviewItemType::Cluster, 'keyword_id' => $candidate->primaryKeywordId], [
                    'fingerprint' => $candidate->fingerprint(), 'status' => 'pending', 'resolution' => null, 'resolved_by_id' => null, 'resolved_at' => null,
                    'suggestion' => ['original_text' => $candidate->name, 'normalized_text' => $candidate->name, 'intent' => $candidate->intent->value,
                        'confidence' => $candidate->confidence->value, 'matched_rule_ids' => ['meaningful-prefix-v1'],
                        'evidence' => ['ویژگی مشترک: '.implode('، ', $candidate->evidence['shared_tokens']),
                            'امتیاز شواهد: '.$candidate->score.'/100؛ تعداد کلمات: '.count($candidate->keywordIds),
                            'سازگاری نیت: '.$candidate->evidence['intent_compatibility']], 'candidate' => $candidate->toArray()],
                ]);
                $this->audit($actor, $project, $candidate, 'review.cluster.proposed', ['review_item_id' => $review->id, 'source' => 'lexical']);
            }
        });
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $actor, Project $project, KeywordClusterCandidate $candidate, string $event, array $metadata): void
    {
        $changes = array_map(fn (int $id): array => ['keyword_id' => $id, 'before' => 'unclustered', 'after' => $event], $candidate->keywordIds);
        $set = ChangeSet::query()->create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
            'action' => $event, 'changes' => $changes]);
        ActivityEvent::query()->create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
            'change_set_id' => $set->id, 'event' => $event, 'metadata' => [...$metadata, 'fingerprint' => $candidate->fingerprint(), 'evidence' => $candidate->evidence]]);
    }
}
