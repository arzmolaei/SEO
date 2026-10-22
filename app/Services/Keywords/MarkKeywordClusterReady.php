<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\ClusterLifecycleState;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\KeywordCluster;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MarkKeywordClusterReady
{
    public function __construct(private ProjectPolicy $policy) {}

    public function handle(User $actor, Project $project, KeywordCluster $cluster): ?ChangeSet
    {
        if (! $this->policy->manageKeywords($actor, $project) || $cluster->project_id !== $project->id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $project, $cluster): ?ChangeSet {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $cluster = KeywordCluster::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($cluster->id);
            if ($cluster->archived_at !== null) {
                throw new ConflictHttpException('Archived clusters cannot become ready.');
            }
            if ($cluster->lifecycle_state === ClusterLifecycleState::Ready) {
                return null;
            }

            $before = $cluster->getAttributes();
            $cluster->lifecycle_state = ClusterLifecycleState::Ready;
            $cluster->origin = 'user';
            $cluster->automation_key = null;
            $cluster->revision++;
            $cluster->save();

            $changeSet = ChangeSet::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->id,
                'actor_id' => $actor->id,
                'action' => 'keywords.cluster.ready',
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
                'event' => 'keywords.cluster.ready',
                'metadata' => ['cluster_id' => $cluster->id, 'revision' => $cluster->revision],
            ]);

            return $changeSet;
        });
    }
}
