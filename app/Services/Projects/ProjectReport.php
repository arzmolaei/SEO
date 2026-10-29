<?php

namespace App\Services\Projects;

use App\Models\ActivityEvent;
use App\Models\ContentBrief;
use App\Models\CrawlRun;
use App\Models\InternalLinkDecision;
use App\Models\Page;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\SearchConsole\SearchConsoleInsights;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One printable summary of a project's SEO progress for a period: where the
 * SEO path stands, the state of keywords, pages, content, tasks and links,
 * search performance from imported Search Console data, and the work done
 * (from the audit trail). Every figure is counted from stored data; a fixed
 * number of queries regardless of project size.
 */
class ProjectReport
{
    public const TOP_QUERIES = 10;

    public function __construct(
        private readonly ProjectGuide $guide,
        private readonly SearchConsoleInsights $insights,
    ) {}

    /** @return array<string, mixed> */
    public function for(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();
        $guide = $this->guide->for($project);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'generated_at' => now()->toIso8601String(),
            'path' => [
                'progress' => $guide['progress'],
                'steps' => array_map(fn (array $step): array => ['key' => $step['key'], 'status' => $step['status']], $guide['steps']),
            ],
            'keywords' => $this->keywords($project),
            'pages' => $this->pages($project),
            'content' => $this->content($project, $start, $end),
            'tasks' => $this->tasks($project, $start, $end),
            'links' => [
                'added_in_period' => InternalLinkDecision::query()->where('project_id', $project->id)
                    ->where('status', 'done')->whereBetween('updated_at', [$start, $end])->count(),
                'added_total' => InternalLinkDecision::query()->where('project_id', $project->id)->where('status', 'done')->count(),
            ],
            'performance' => $this->performance($project),
            'activity' => array_values(ActivityEvent::query()->where('project_id', $project->id)
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw('event, count(*) as total')->groupBy('event')
                ->orderByDesc('total')->orderBy('event')->limit(20)->get()
                ->map(fn (ActivityEvent $row): array => ['event' => $row->event, 'count' => (int) $row->getAttribute('total')])
                ->all()),
        ];
    }

    /** @return array{keywords: int, clusters: int, mapped: int, ready: int} */
    private function keywords(Project $project): array
    {
        $clusters = DB::table('keyword_clusters')->where('project_id', $project->id)->whereNull('archived_at');

        return [
            'keywords' => DB::table('keywords')->where('project_id', $project->id)->count(),
            'clusters' => (clone $clusters)->count(),
            'mapped' => DB::table('cluster_targets')
                ->join('keyword_clusters', 'keyword_clusters.id', '=', 'cluster_targets.keyword_cluster_id')
                ->where('cluster_targets.project_id', $project->id)->whereNotNull('cluster_targets.page_id')
                ->whereNull('keyword_clusters.archived_at')->count(),
            'ready' => (clone $clusters)->where('lifecycle_state', 'ready')->count(),
        ];
    }

    /** @return array{existing: int, planned: int, on_page: array{todo: int, in_progress: int, done: int}, last_check: array{finished_at: ?string, checked: int, errors: int}|null} */
    private function pages(Project $project): array
    {
        $kinds = Page::query()->where('project_id', $project->id)->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');
        $statuses = Page::query()->where('project_id', $project->id)->selectRaw('on_page_status, count(*) as total')->groupBy('on_page_status')->pluck('total', 'on_page_status');
        $run = CrawlRun::query()->where('project_id', $project->id)->where('status', 'completed')->latest('id')->first();

        return [
            'existing' => (int) ($kinds['existing'] ?? 0),
            'planned' => (int) ($kinds['planned'] ?? 0),
            'on_page' => [
                'todo' => (int) ($statuses['todo'] ?? 0),
                'in_progress' => (int) ($statuses['in_progress'] ?? 0),
                'done' => (int) ($statuses['done'] ?? 0),
            ],
            'last_check' => $run === null ? null : [
                'finished_at' => $run->finished_at?->toIso8601String(),
                'checked' => $run->fetched_count + $run->failed_count,
                'errors' => DB::table('crawl_urls')->where('crawl_run_id', $run->id)
                    ->where(fn ($query) => $query->where('state', 'failed')->orWhere('status_code', '>=', 400))->count(),
            ],
        ];
    }

    /** @return array{draft: int, review: int, published: int, published_in_period: int, due_in_period: int} */
    private function content(Project $project, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $statuses = ContentBrief::query()->where('project_id', $project->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'draft' => (int) ($statuses['draft'] ?? 0),
            'review' => (int) ($statuses['review'] ?? 0),
            'published' => (int) ($statuses['published'] ?? 0),
            'published_in_period' => ContentBrief::query()->where('project_id', $project->id)->where('status', 'published')
                ->whereBetween('published_on', [$start->toDateString(), $end->toDateString()])->count(),
            'due_in_period' => ContentBrief::query()->where('project_id', $project->id)->where('status', '!=', 'published')
                ->whereBetween('due_on', [$start->toDateString(), $end->toDateString()])->count(),
        ];
    }

    /** @return array{open: int, overdue: int, done_in_period: int, created_in_period: int} */
    private function tasks(Project $project, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $tasks = fn () => ProjectTask::query()->where('project_id', $project->id);

        return [
            'open' => $tasks()->where('status', '!=', 'done')->count(),
            'overdue' => $tasks()->where('status', '!=', 'done')->where('due_on', '<', CarbonImmutable::today()->toDateString())->count(),
            'done_in_period' => $tasks()->where('status', 'done')->whereBetween('completed_at', [$start, $end])->count(),
            'created_in_period' => $tasks()->whereBetween('created_at', [$start, $end])->count(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function performance(Project $project): ?array
    {
        $latest = $this->insights->latest($project, 'queries');
        if ($latest === null) {
            return null;
        }
        $previous = $this->insights->latest($project, 'queries', 1);
        $top = DB::table('search_console_rows')->where('search_console_import_id', $latest->id)
            ->orderByDesc('clicks')->orderBy('id')->limit(self::TOP_QUERIES)
            ->get(['key_text', 'clicks', 'impressions', 'position']);

        return [
            'period' => ['from' => $latest->period_start->toDateString(), 'to' => $latest->period_end->toDateString()],
            'totals' => $this->insights->totals($latest) + ['days' => $latest->days()],
            'previous' => $previous === null ? null : $this->insights->totals($previous) + ['days' => $previous->days()],
            'top_queries' => array_values($top->map(fn ($row): array => [
                'key' => (string) $row->key_text,
                'clicks' => (int) $row->clicks,
                'impressions' => (int) $row->impressions,
                'position' => (float) $row->position,
            ])->all()),
        ];
    }
}
