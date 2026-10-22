<?php

namespace App\Services\Keywords;

use App\Models\ChangeSet;
use App\Models\KeywordCluster;
use App\Models\Project;
use App\Models\User;
use InvalidArgumentException;

class MergeKeywordClusters
{
    public function __construct(private ClusterMutation $mutation) {}

    /** @param list<int> $sourceIds */
    public function handle(User $actor, Project $project, KeywordCluster $target, array $sourceIds): ?ChangeSet
    {
        if ($sourceIds === [] || in_array($target->id, $sourceIds, true)) {
            throw new InvalidArgumentException('Distinct source and target clusters required.');
        }

        return $this->mutation->handle($actor, $project, [$target->id, ...$sourceIds], 'keywords.clusters.merge', function ($clusters) use ($target, $sourceIds): array {
            foreach ($clusters as $cluster) {
                $this->mutation->editable($cluster);
            }
            foreach ($sourceIds as $id) {
                $source = $clusters->get($id);
                if (! $source instanceof KeywordCluster) {
                    throw new InvalidArgumentException('Missing source cluster.');
                }
                $source->update([...$this->mutation->manual(), 'archived_at' => now()]);
                foreach ($source->members()->orderBy('id')->get() as $member) {
                    $member->update(['keyword_cluster_id' => $target->id]);
                }
            }
            $current = $clusters->get($target->id);
            $current?->update($this->mutation->manual());

            return [];
        });
    }
}
