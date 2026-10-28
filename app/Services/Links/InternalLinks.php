<?php

namespace App\Services\Links;

use App\Domain\Links\InternalLinkSuggester;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\InternalLinkDecision;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Internal link suggestions for a project's mapped pages and the owner's
 * decisions about them (added / not useful / reopened).
 */
class InternalLinks
{
    /** Upper bound on clusters considered, so one request stays bounded. */
    public const MAX_CLUSTERS = 2000;

    /** Keywords per cluster used for topic matching. */
    private const MAX_KEYWORDS = 30;

    /**
     * @return list<array{source_page_id: int, target_page_id: int, target_cluster_id: int, anchor: string, alternatives: list<string>, shared: list<string>, score: float}>
     */
    public function suggestions(Project $project): array
    {
        $mapped = DB::table('cluster_targets')
            ->join('keyword_clusters', 'keyword_clusters.id', '=', 'cluster_targets.keyword_cluster_id')
            ->join('keywords', 'keywords.id', '=', 'keyword_clusters.primary_keyword_id')
            ->where('cluster_targets.project_id', $project->id)
            ->whereNotNull('cluster_targets.page_id')
            ->whereNull('keyword_clusters.archived_at')
            ->orderBy('keyword_clusters.id')
            ->limit(self::MAX_CLUSTERS)
            ->get(['keyword_clusters.id as cluster_id', 'cluster_targets.page_id', 'keywords.display_text as primary']);
        if ($mapped->isEmpty()) {
            return [];
        }
        $keywords = [];
        foreach ($mapped->pluck('cluster_id')->chunk(500) as $chunk) {
            DB::table('keyword_cluster_members')
                ->join('keywords', 'keywords.id', '=', 'keyword_cluster_members.keyword_id')
                ->where('keyword_cluster_members.project_id', $project->id)
                ->whereIn('keyword_cluster_members.keyword_cluster_id', $chunk->all())
                ->orderBy('keyword_cluster_members.id')
                ->get(['keyword_cluster_members.keyword_cluster_id', 'keywords.display_text'])
                ->each(function ($row) use (&$keywords): void {
                    $cluster = (int) $row->keyword_cluster_id;
                    if (count($keywords[$cluster] ?? []) < self::MAX_KEYWORDS) {
                        $keywords[$cluster][] = (string) $row->display_text;
                    }
                });
        }

        return InternalLinkSuggester::suggest(array_values($mapped->map(fn ($row): array => [
            'cluster_id' => (int) $row->cluster_id,
            'page_id' => (int) $row->page_id,
            'primary' => (string) $row->primary,
            'keywords' => $keywords[(int) $row->cluster_id] ?? [],
        ])->all()));
    }

    public function decide(Project $project, User $actor, int $sourcePageId, int $targetPageId, ?string $status, ?string $anchor): void
    {
        Gate::forUser($actor)->authorize('update', $project);
        if ($sourcePageId === $targetPageId
            || DB::table('pages')->where('project_id', $project->id)->whereIn('id', [$sourcePageId, $targetPageId])->count() !== 2) {
            throw ValidationException::withMessages(['link' => 'این دو صفحه در این پروژه پیدا نشدند.']);
        }
        DB::transaction(function () use ($project, $actor, $sourcePageId, $targetPageId, $status, $anchor): void {
            $existing = InternalLinkDecision::query()->where('project_id', $project->id)
                ->where('source_page_id', $sourcePageId)->where('target_page_id', $targetPageId)
                ->lockForUpdate()->first();
            $before = $existing?->only(['status', 'anchor']);
            if ($status === null) {
                $existing?->delete();
                $after = null;
            } else {
                $decision = $existing ?? new InternalLinkDecision([
                    'project_id' => $project->id, 'source_page_id' => $sourcePageId, 'target_page_id' => $targetPageId,
                ]);
                $decision->fill(['status' => $status, 'anchor' => $anchor, 'decided_by_id' => $actor->id])->save();
                $after = $decision->only(['status', 'anchor']);
            }
            if ($before === $after) {
                return;
            }
            $set = ChangeSet::query()->create([
                'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
                'action' => 'internal_link.decided',
                'changes' => [['source_page_id' => $sourcePageId, 'target_page_id' => $targetPageId, 'before' => $before, 'after' => $after]],
            ]);
            ActivityEvent::query()->create([
                'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
                'change_set_id' => $set->id, 'event' => 'internal_link.decided',
                'metadata' => ['source_page_id' => $sourcePageId, 'target_page_id' => $targetPageId, 'status' => $status],
            ]);
        });
    }
}
