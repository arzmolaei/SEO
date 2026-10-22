<?php

namespace App\Services\Keywords;

use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\KeywordCluster;
use App\Models\KeywordClusterMember;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** @phpstan-import-type ClusterSnapshot from ClusterMutation */
class UndoKeywordClusterChangeSet
{
    public function __construct(private ClusterMutation $mutation, private ProjectPolicy $policy) {}

    public function handle(User $actor, Project $project, ChangeSet $set): ChangeSet
    {
        if (! $this->policy->manageKeywords($actor, $project) || $set->project_id !== $project->id) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $project, $set): ChangeSet {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $set = ChangeSet::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($set->id);
            if ($set->undone_at !== null || ! in_array($set->action, ['keywords.clusters.merge', 'keywords.clusters.move', 'keywords.clusters.split', 'keywords.clusters.lock'], true) || count($set->changes) !== 1) {
                throw new ConflictHttpException('Change set is not safely reversible.');
            }
            /** @var ClusterSnapshot $before */
            $before = json_decode($set->changes[0]['before'], true, flags: JSON_THROW_ON_ERROR);
            /** @var ClusterSnapshot $after */
            $after = json_decode($set->changes[0]['after'], true, flags: JSON_THROW_ON_ERROR);
            $current = $this->mutation->lock($project, $after['cluster_ids']);
            if ($this->mutation->snapshot($project, $after['cluster_ids']) !== $after) {
                throw new ConflictHttpException('Cluster state changed after this operation.');
            }
            foreach ($current as $cluster) {
                $cluster->update(['archived_at' => now()]);
            }
            foreach (KeywordClusterMember::query()->where('project_id', $project->id)->whereIn('keyword_cluster_id', $after['cluster_ids'])->orderBy('id')->get() as $member) {
                $member->delete();
            }
            foreach ($before['members'] as $attributes) {
                $member = new KeywordClusterMember;
                $member->fill($attributes);
                $member->id = (int) $attributes['id'];
                $member->save();
            }
            $restoredIds = [];
            foreach ($before['clusters'] as $attributes) {
                $cluster = $current->get((int) $attributes['id']);
                if (! $cluster instanceof KeywordCluster) {
                    throw new ConflictHttpException('Original cluster identity is missing.');
                }
                $attributes['revision'] = $cluster->revision + 1;
                $cluster->update($attributes);
                $restoredIds[] = $cluster->id;
            }
            foreach ($current as $cluster) {
                if (! in_array($cluster->id, $restoredIds, true)) {
                    $cluster->delete();
                }
            }
            $set->update(['undone_at' => now(), 'undone_by_id' => $actor->id]);
            ActivityEvent::query()->create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
                'change_set_id' => $set->id, 'event' => 'keywords.clusters.undone', 'metadata' => ['cluster_ids' => $after['cluster_ids']]]);

            return $set;
        });
    }
}
