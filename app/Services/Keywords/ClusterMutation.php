<?php

namespace App\Services\Keywords;

use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordClusterMember;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** @phpstan-type ClusterSnapshot array{cluster_ids:list<int>, clusters:list<array<string,mixed>>, members:list<array<string,mixed>>} */
class ClusterMutation
{
    public function __construct(private ProjectPolicy $policy) {}

    /**
     * @param  list<int>  $ids
     * @param  Closure(Collection<int, KeywordCluster>):list<int>  $operation
     */
    public function handle(User $actor, Project $project, array $ids, string $action, Closure $operation): ?ChangeSet
    {
        if (! $this->policy->manageKeywords($actor, $project)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $project, $ids, $action, $operation): ?ChangeSet {
            $clusters = $this->lock($project, $ids);
            $before = $this->snapshot($project, $ids);
            $newIds = $operation($clusters);
            $allIds = array_values(array_unique([...$ids, ...$newIds]));
            sort($allIds, SORT_NUMERIC);
            $before['cluster_ids'] = $allIds;
            $after = $this->snapshot($project, $allIds);
            if ($before === $after) {
                return null;
            }
            foreach (KeywordCluster::query()->where('project_id', $project->id)->whereIn('id', $allIds)->orderBy('id')->get() as $cluster) {
                $cluster->invalidateReadiness();
                $cluster->revision++;
                $cluster->save();
            }
            $after = $this->snapshot($project, $allIds);
            $set = ChangeSet::query()->create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id,
                'actor_id' => $actor->id, 'action' => $action, 'changes' => [['keyword_id' => (int) $clusters->firstOrFail()->primary_keyword_id,
                    'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR)]]]);
            ActivityEvent::query()->create(['workspace_id' => $project->workspace_id, 'project_id' => $project->id,
                'actor_id' => $actor->id, 'change_set_id' => $set->id, 'event' => $action, 'metadata' => ['cluster_ids' => $allIds, 'source' => 'user']]);

            return $set;
        });
    }

    public function authorize(User $actor, Project $project): void
    {
        if (! $this->policy->manageKeywords($actor, $project)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, KeywordCluster>
     */
    public function lock(Project $project, array $ids): Collection
    {
        if ($ids === [] || count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Distinct cluster IDs required.');
        }
        Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
        $keywordIds = KeywordClusterMember::query()->where('project_id', $project->id)->whereIn('keyword_cluster_id', $ids)->pluck('keyword_id');
        Keyword::query()->where('project_id', $project->id)->whereIn('id', $keywordIds)->orderBy('id')->lockForUpdate()->get();
        $clusters = KeywordCluster::query()->where('project_id', $project->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($clusters->count() !== count($ids)) {
            throw new AuthorizationException;
        }
        KeywordClusterMember::query()->where('project_id', $project->id)->whereIn('keyword_cluster_id', $ids)->orderBy('id')->lockForUpdate()->get();

        return $clusters;
    }

    public function editable(KeywordCluster $cluster): void
    {
        if ($cluster->is_locked || $cluster->archived_at !== null) {
            throw new ConflictHttpException('Unlock an active cluster before editing membership.');
        }
    }

    /** @return array{origin:string,automation_key:null,review_state:string} */
    public function manual(): array
    {
        return ['origin' => 'user', 'automation_key' => null, 'review_state' => 'pending'];
    }

    /**
     * @param  list<int>  $ids
     * @return ClusterSnapshot
     */
    public function snapshot(Project $project, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return ['cluster_ids' => $ids,
            'clusters' => array_values(KeywordCluster::query()->where('project_id', $project->id)->whereIn('id', $ids)->orderBy('id')
                ->get(['id', 'project_id', 'primary_keyword_id', 'created_by_id', 'name', 'intent', 'confidence', 'review_state', 'business_relevance', 'lifecycle_state', 'is_locked', 'origin', 'automation_key', 'revision', 'archived_at'])
                ->map(fn (KeywordCluster $c): array => $c->getAttributes())->all()),
            'members' => array_values(KeywordClusterMember::query()->where('project_id', $project->id)->whereIn('keyword_cluster_id', $ids)->orderBy('id')
                ->get(['id', 'project_id', 'keyword_cluster_id', 'keyword_id'])->map(fn (KeywordClusterMember $m): array => $m->getAttributes())->all())];
    }
}
