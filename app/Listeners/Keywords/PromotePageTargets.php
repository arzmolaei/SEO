<?php

namespace App\Listeners\Keywords;

use App\Domain\Keywords\ClusterTargetType;
use App\Events\Pages\PagePromoted;
use App\Models\ClusterTarget;
use App\Models\KeywordCluster;
use App\Services\Keywords\MapClusterToTarget;

class PromotePageTargets
{
    public function __construct(private MapClusterToTarget $map) {}

    public function handle(PagePromoted $event): void
    {
        $targets = ClusterTarget::query()->where('project_id', $event->project->id)->where('page_id', $event->page->id)->where('type', ClusterTargetType::PlannedPage)->orderBy('keyword_cluster_id')->get();
        foreach ($targets as $target) {
            $cluster = KeywordCluster::query()->where('project_id', $event->project->id)->lockForUpdate()->findOrFail($target->keyword_cluster_id);
            if ($cluster->archived_at !== null) {
                $cluster->update(['revision' => $cluster->revision + 1]);

                continue;
            }
            $this->map->handle($event->actor, $event->project, $cluster, ClusterTargetType::ExistingPage, $event->page, 'page_promotion');
        }
    }
}
