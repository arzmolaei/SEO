<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\ClusterTargetType;
use App\Domain\Pages\PageKind;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\ClusterTarget;
use App\Models\KeywordCluster;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MapClusterToTarget
{
    public function __construct(private ProjectPolicy $policy) {}

    public function handle(User $actor, Project $project, KeywordCluster $cluster, ClusterTargetType $type, ?Page $page = null, string $source = 'user'): ClusterTarget
    {
        if (! $this->policy->manageKeywords($actor, $project) || $cluster->project_id !== $project->id || ($page !== null && $page->project_id !== $project->id)) {
            throw new AuthorizationException;
        }
        if (! in_array($source, ['user', 'page_promotion'], true)) {
            throw new InvalidArgumentException('Unsupported mapping source.');
        }

        return DB::transaction(function () use ($actor, $project, $cluster, $type, $page, $source): ClusterTarget {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $cluster = KeywordCluster::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($cluster->id);
            if ($cluster->archived_at !== null) {
                throw new ConflictHttpException('Archived clusters cannot be remapped.');
            }
            if ($page !== null) {
                $page = Page::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($page->id);
            }
            if (($type === ClusterTargetType::Ignore && $page !== null) || ($type !== ClusterTargetType::Ignore && $page === null)) {
                throw new InvalidArgumentException('Page must match target type.');
            }
            if ($page !== null) {
                $expected = $type === ClusterTargetType::PlannedPage ? PageKind::Planned : PageKind::Existing;
                if ($page->kind !== $expected || ($expected === PageKind::Existing && $page->normalized_url === null)) {
                    throw new InvalidArgumentException('Page kind does not match target type.');
                }
            }
            $target = ClusterTarget::query()->where('project_id', $project->id)->where('keyword_cluster_id', $cluster->id)->lockForUpdate()->first();
            if ($target instanceof ClusterTarget && $target->type === $type && $target->page_id === $page?->id) {
                return $target;
            }
            $before = $target?->getAttributes();
            $target = ClusterTarget::query()->updateOrCreate(['project_id' => $project->id, 'keyword_cluster_id' => $cluster->id], ['page_id' => $page?->id, 'type' => $type, 'actor_id' => $actor->id, 'revision' => ($target instanceof ClusterTarget ? $target->revision : 0) + 1]);
            $cluster->update(['revision' => $cluster->revision + 1]);
            $set = ChangeSet::query()->create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id, 'action' => 'keywords.mapping.set',
                'changes' => [['cluster_id' => $cluster->id, 'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($target->getAttributes(), JSON_THROW_ON_ERROR)]]]);
            ActivityEvent::query()->create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id, 'change_set_id' => $set->id, 'event' => 'keywords.mapping.set',
                'metadata' => ['cluster_id' => $cluster->id, 'target_id' => $target->id, 'source' => $source]]);

            return $target;
        });
    }
}
