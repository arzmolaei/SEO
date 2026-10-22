<?php

namespace App\Services\Keywords;

use App\Models\ChangeSet;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordClusterMember;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MoveKeywordBetweenClusters
{
    public function __construct(private ClusterMutation $mutation) {}

    public function handle(User $actor, Project $project, Keyword $keyword, KeywordCluster $target, ?Keyword $replacement = null): ?ChangeSet
    {
        $this->mutation->authorize($actor, $project);
        if ($keyword->project_id !== $project->id) {
            throw new AuthorizationException;
        }
        $membership = KeywordClusterMember::query()->where('project_id', $project->id)->where('keyword_id', $keyword->id)->firstOrFail();

        return $this->mutation->handle($actor, $project, [$membership->keyword_cluster_id, $target->id], 'keywords.clusters.move', function ($clusters) use ($membership, $keyword, $target, $replacement): array {
            foreach ($clusters as $cluster) {
                $this->mutation->editable($cluster);
            }
            $source = $clusters->get($membership->keyword_cluster_id);
            $member = KeywordClusterMember::query()->findOrFail($membership->id);
            if (! $source instanceof KeywordCluster || $member->keyword_cluster_id !== $source->id) {
                throw new ConflictHttpException('Membership changed.');
            }
            if ($source->primary_keyword_id === $keyword->id) {
                if ($replacement === null || $replacement->id === $keyword->id || ! $source->members()->where('keyword_id', $replacement->id)->exists()) {
                    throw new ConflictHttpException('Select a remaining member as replacement primary.');
                }
                $source->update(['primary_keyword_id' => $replacement->id]);
            }
            $member->update(['keyword_cluster_id' => $target->id]);
            foreach ($clusters as $cluster) {
                $cluster->update($this->mutation->manual());
            }

            return [];
        });
    }
}
