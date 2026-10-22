<?php

namespace App\Services\Keywords;

use App\Models\ChangeSet;
use App\Models\KeywordCluster;
use App\Models\Project;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SetKeywordClusterLock
{
    public function __construct(private ClusterMutation $mutation) {}

    public function handle(User $actor, Project $project, KeywordCluster $cluster, bool $locked): ?ChangeSet
    {
        return $this->mutation->handle($actor, $project, [$cluster->id], 'keywords.clusters.lock', function ($clusters) use ($cluster, $locked): array {
            $current = $clusters->get($cluster->id);
            if (! $current instanceof KeywordCluster || $current->archived_at !== null) {
                throw new ConflictHttpException('An active cluster is required.');
            }
            if ($current->is_locked !== $locked) {
                $current->update(['origin' => 'user', 'automation_key' => null, 'is_locked' => $locked]);
            }

            return [];
        });
    }
}
