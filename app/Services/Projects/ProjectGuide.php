<?php

namespace App\Services\Projects;

use App\Domain\Keywords\ClusterLifecycleState;
use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Review\ReviewItemType;
use App\Models\ActivityEvent;
use App\Models\CrawlRun;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\Project;
use App\Models\ReviewItem;
use Illuminate\Support\Facades\DB;

/**
 * The beginner's SEO path for one project. Every status is derived from
 * stored project data at request time; nothing here is self-reported.
 *
 * Statuses: done, todo (do this now), attention (something needs fixing),
 * waiting (an earlier step must happen first).
 */
class ProjectGuide
{
    public const EXPORT_EVENT = 'export.seo_plan';

    /**
     * @return array{
     *   steps: list<array{key: string, status: string, count: int, total: int, href: string}>,
     *   next: list<string>,
     *   progress: array{done: int, total: int}
     * }
     */
    public function for(Project $project): array
    {
        $keywords = Keyword::query()->where('project_id', $project->id)->count();
        $pendingReviews = ReviewItem::query()->where('project_id', $project->id)->where('status', 'pending')
            ->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');
        $pendingIntent = (int) ($pendingReviews[ReviewItemType::Intent->value] ?? 0);
        $pendingClusterReviews = (int) ($pendingReviews[ReviewItemType::Cluster->value] ?? 0);
        $pendingConflicts = (int) ($pendingReviews[ReviewItemType::MappingConflict->value] ?? 0);

        $clusters = KeywordCluster::query()->where('project_id', $project->id)->whereNull('archived_at');
        $clusterCount = (clone $clusters)->count();
        $unreviewedClusters = (clone $clusters)->where('review_state', ClusterReviewState::Pending->value)->count();
        $mappedClusters = (int) DB::table('cluster_targets')
            ->join('keyword_clusters', 'keyword_clusters.id', '=', 'cluster_targets.keyword_cluster_id')
            ->where('cluster_targets.project_id', $project->id)
            ->whereNull('keyword_clusters.archived_at')
            ->count();
        $readyClusters = (clone $clusters)->where('lifecycle_state', ClusterLifecycleState::Ready->value)->count();
        $exported = ActivityEvent::query()->where('project_id', $project->id)->where('event', self::EXPORT_EVENT)->exists();
        $checkedSite = CrawlRun::query()->where('project_id', $project->id)->where('status', 'completed')->exists();

        $keywordsPage = route('projects.keywords.index', $project);
        $reviews = route('projects.reviews.index', $project);
        $hasKeywords = $keywords > 0;
        $hasClusters = $clusterCount > 0;

        $steps = [
            $this->step('import', $hasKeywords ? 'done' : 'todo', $keywords, $keywords, route('projects.keyword-imports.index', $project)),
            $this->step(
                'intent',
                ! $hasKeywords ? 'waiting' : ($pendingIntent > 0 ? 'todo' : 'done'),
                $pendingIntent, $keywords, $reviews,
            ),
            $this->step(
                'clusters',
                ! $hasKeywords ? 'waiting' : (! $hasClusters || $unreviewedClusters + $pendingClusterReviews > 0 ? 'todo' : 'done'),
                $unreviewedClusters + $pendingClusterReviews, $clusterCount,
                ! $hasClusters || $pendingClusterReviews > 0 ? $reviews : $keywordsPage.'?tab=clusters',
            ),
            $this->step(
                'mapping',
                ! $hasClusters ? 'waiting' : ($mappedClusters < $clusterCount ? 'todo' : 'done'),
                $clusterCount - $mappedClusters, $clusterCount, $keywordsPage.'?tab=mapping',
            ),
            $this->step(
                'conflicts',
                $mappedClusters === 0 ? 'waiting' : ($pendingConflicts > 0 ? 'attention' : 'done'),
                $pendingConflicts, $mappedClusters, $reviews,
            ),
            $this->step(
                'ready',
                $mappedClusters === 0 ? 'waiting' : ($readyClusters < $mappedClusters ? 'todo' : 'done'),
                $mappedClusters - min($readyClusters, $mappedClusters), $mappedClusters, $keywordsPage.'?tab=mapping',
            ),
            $this->step(
                'export',
                $readyClusters === 0 ? 'waiting' : ($exported ? 'done' : 'todo'),
                $exported ? 1 : 0, 1, route('projects.exports.seo-plan', $project),
            ),
            $this->step('site_check', $checkedSite ? 'done' : 'todo', $checkedSite ? 1 : 0, 1, route('projects.crawls.index', $project)),
        ];

        $actionable = array_values(array_filter($steps, static fn (array $step): bool => in_array($step['status'], ['attention', 'todo'], true)));
        // Problems first, then the earliest unfinished step of the path.
        usort($actionable, static fn (array $a, array $b): int => ($a['status'] === 'attention' ? 0 : 1) <=> ($b['status'] === 'attention' ? 0 : 1));

        return [
            'steps' => $steps,
            'next' => array_slice(array_map(static fn (array $step): string => $step['key'], $actionable), 0, 3),
            'progress' => [
                'done' => count(array_filter($steps, static fn (array $step): bool => $step['status'] === 'done')),
                'total' => count($steps),
            ],
        ];
    }

    /** @return array{key: string, status: string, count: int, total: int, href: string} */
    private function step(string $key, string $status, int $count, int $total, string $href): array
    {
        return ['key' => $key, 'status' => $status, 'count' => max(0, $count), 'total' => max(0, $total), 'href' => $href];
    }
}
