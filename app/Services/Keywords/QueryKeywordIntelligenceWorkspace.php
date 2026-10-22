<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\ClusterLifecycleState;
use App\Domain\Keywords\ClusterPriorityScore;
use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Review\ReviewItemType;
use App\Models\ClusterTarget;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordMetric;
use App\Models\KeywordSource;
use App\Models\Project;
use App\Models\ReviewItem;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use LogicException;

class QueryKeywordIntelligenceWorkspace
{
    private const PAGE_SIZE = 20;

    public function __construct(private ProjectPolicy $policy) {}

    /** @param array<string,mixed> $query */
    public function handle(User $actor, Project $project, array $query = []): array
    {
        if (! $this->policy->view($actor, $project)) {
            throw new AuthorizationException;
        }

        $page = max(1, (int) ($query['cluster_page'] ?? 1));
        $clusters = KeywordCluster::query()
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->with('primaryKeyword:id,project_id,display_text,normalized_text')
            ->withCount('members')
            ->orderBy('id')
            ->paginate(self::PAGE_SIZE, ['*'], 'cluster_page', $page);

        $clusterModels = $clusters->getCollection();
        $clusterIds = $clusterModels->modelKeys();
        $primaryIds = $clusterModels->pluck('primary_keyword_id')->map(fn (mixed $id): int => (int) $id)->all();
        $targets = ClusterTarget::query()
            ->where('project_id', $project->id)
            ->whereIn('keyword_cluster_id', $clusterIds)
            ->with('page:id,project_id,title,kind,page_type,url,normalized_url')
            ->get()
            ->keyBy('keyword_cluster_id');
        $metrics = $this->latestMetrics($primaryIds);
        $pending = ReviewItem::query()
            ->where('project_id', $project->id)
            ->whereIn('keyword_id', $primaryIds)
            ->where('status', 'pending')
            ->pluck('keyword_id')
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true])
            ->all();

        $clusters->through(function (KeywordCluster $cluster) use ($targets, $metrics, $pending): array {
            $primary = $cluster->primaryKeyword;
            if (! $primary instanceof Keyword) {
                throw new LogicException('Active cluster primary keyword is missing.');
            }
            $target = $targets->get($cluster->id);
            $volume = $metrics[$cluster->primary_keyword_id]['volume'] ?? null;
            $difficulty = $metrics[$cluster->primary_keyword_id]['difficulty'] ?? null;
            $priority = ClusterPriorityScore::calculate(
                volume: $volume === null ? null : (float) $volume->value,
                difficulty: $difficulty === null ? null : (float) $difficulty->value,
                businessRelevance: $cluster->business_relevance,
                hasMapping: $target instanceof ClusterTarget,
                reviewComplete: $cluster->review_state === ClusterReviewState::Reviewed && ! isset($pending[$cluster->primary_keyword_id]),
                volumeEvidence: $this->metricEvidence($volume),
                difficultyEvidence: $this->metricEvidence($difficulty),
            );
            $memberCount = (int) $cluster->getAttribute('members_count');

            return [
                'id' => $cluster->id,
                'name' => $cluster->name,
                'intent' => $cluster->intent->value,
                'confidence' => $cluster->confidence->value,
                'review_state' => $cluster->review_state->value,
                'business_relevance' => $cluster->business_relevance,
                'lifecycle_state' => $cluster->lifecycle_state->value,
                'is_locked' => $cluster->is_locked,
                'revision' => $cluster->revision,
                'member_count' => $memberCount,
                'primary_keyword' => ['id' => $primary->id, 'display_text' => $primary->display_text],
                'target' => $target instanceof ClusterTarget ? [
                    'id' => $target->id,
                    'type' => $target->type->value,
                    'revision' => $target->revision,
                    'page' => $target->page === null ? null : [
                        'id' => $target->page->id,
                        'title' => $target->page->title,
                        'kind' => $target->page->kind->value,
                        'page_type' => $target->page->page_type,
                        'url' => $target->page->url,
                    ],
                ] : null,
                'priority' => $priority->toArray(),
            ];
        });

        $activeClusters = KeywordCluster::query()->where('project_id', $project->id)->whereNull('archived_at');
        $activeCount = (clone $activeClusters)->count();
        $readyCount = (clone $activeClusters)->where('lifecycle_state', ClusterLifecycleState::Ready)->count();
        $mappedClusterIds = ClusterTarget::query()->where('project_id', $project->id)->select('keyword_cluster_id');
        $unmappedCount = (clone $activeClusters)->whereNotIn('id', $mappedClusterIds)->count();

        return [
            'can_manage' => $this->policy->manageKeywords($actor, $project),
            'summary' => [
                'active_clusters' => $activeCount,
                'ready_clusters' => $readyCount,
                'unmapped_clusters' => $unmappedCount,
                'pending_reviews' => ReviewItem::query()->where('project_id', $project->id)->where('status', 'pending')->count(),
                'mapping_conflicts' => ReviewItem::query()->where('project_id', $project->id)
                    ->where('type', ReviewItemType::MappingConflict)->where('status', 'pending')->count(),
            ],
            'clusters' => $clusters,
        ];
    }

    /**
     * @param list<int> $keywordIds
     * @return array<int,array<string,KeywordMetric>>
     */
    private function latestMetrics(array $keywordIds): array
    {
        if ($keywordIds === []) {
            return [];
        }

        $metrics = KeywordMetric::query()
            ->with('source:id,source')
            ->whereIn('keyword_id', $keywordIds)
            ->whereIn('metric', ['volume', 'difficulty'])
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('keyword_metrics as newer')
                    ->whereColumn('newer.keyword_id', 'keyword_metrics.keyword_id')
                    ->whereColumn('newer.metric', 'keyword_metrics.metric')
                    ->where(function (Builder $newer): void {
                        $newer->whereColumn('newer.observed_at', '>', 'keyword_metrics.observed_at')
                            ->orWhere(function (Builder $tie): void {
                                $tie->whereColumn('newer.observed_at', '=', 'keyword_metrics.observed_at')
                                    ->whereColumn('newer.id', '>', 'keyword_metrics.id');
                            });
                    });
            })
            ->get();

        $result = [];
        foreach ($metrics as $metric) {
            $result[$metric->keyword_id][$metric->metric] = $metric;
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    private function metricEvidence(?KeywordMetric $metric): ?array
    {
        if (! $metric instanceof KeywordMetric) {
            return null;
        }
        $source = $metric->source;

        return [
            'metric_id' => $metric->id,
            'source_id' => $metric->keyword_source_id,
            'source' => $source instanceof KeywordSource ? $source->source : null,
            'observed_at' => $metric->observed_at->toIso8601String(),
        ];
    }
}
