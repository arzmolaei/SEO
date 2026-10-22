<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\ClusterReviewState;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\KeywordCluster;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UpdateKeywordClusterPlanning
{
    public function __construct(private ProjectPolicy $policy) {}

    /**
     * @param array{review_state?: ClusterReviewState, business_relevance?: int|null} $changes
     */
    public function handle(User $actor, Project $project, KeywordCluster $cluster, array $changes): ?ChangeSet
    {
        if (! $this->policy->manageKeywords($actor, $project) || $cluster->project_id !== $project->id) {
            throw new AuthorizationException;
        }
        if ($changes === [] || array_diff(array_keys($changes), ['review_state', 'business_relevance']) !== []) {
            throw new InvalidArgumentException('Unsupported cluster planning changes.');
        }
        if (array_key_exists('business_relevance', $changes) && $changes['business_relevance'] !== null
            && ($changes['business_relevance'] < 1 || $changes['business_relevance'] > 5)) {
            throw new InvalidArgumentException('Business relevance must be between 1 and 5.');
        }

        return DB::transaction(function () use ($actor, $project, $cluster, $changes): ?ChangeSet {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $cluster = KeywordCluster::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($cluster->id);
            if ($cluster->archived_at !== null) {
                throw new ConflictHttpException('Archived clusters cannot be edited.');
            }

            $before = $cluster->getAttributes();
            if (array_key_exists('review_state', $changes)) {
                $cluster->review_state = $changes['review_state'];
                if ($changes['review_state'] === ClusterReviewState::Pending) {
                    $cluster->invalidateReadiness();
                }
            }
            if (array_key_exists('business_relevance', $changes)) {
                $cluster->business_relevance = $changes['business_relevance'];
            }
            $cluster->origin = 'user';
            $cluster->automation_key = null;

            if (! $cluster->isDirty()) {
                return null;
            }

            $cluster->revision++;
            $cluster->save();
            $changeSet = ChangeSet::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'actor_id' => $actor->id,
                'action' => 'keywords.cluster.planning.update',
                'changes' => [[
                    'cluster_id' => $cluster->id,
                    'before' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after' => json_encode($cluster->getAttributes(), JSON_THROW_ON_ERROR),
                ]],
            ]);
            ActivityEvent::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'actor_id' => $actor->id,
                'change_set_id' => $changeSet->id,
                'event' => 'keywords.cluster.planning.updated',
                'metadata' => [
                    'cluster_id' => $cluster->id,
                    'review_state' => $cluster->review_state->value,
                    'business_relevance' => $cluster->business_relevance,
                ],
            ]);

            return $changeSet;
        });
    }
}
