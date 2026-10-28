<?php

namespace App\Services\Content;

use App\Domain\Content\BriefOutline;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\ContentBrief;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates, edits and removes content briefs. Every change is one audited
 * change set with before/after values, so a removed brief can be recovered.
 */
class ContentBriefs
{
    public const EDITABLE = ['title', 'headings', 'notes', 'status', 'due_on', 'published_on'];

    /** Returns the cluster's brief, creating it from the cluster's keywords on first use. */
    public function generate(Project $project, User $actor, int $clusterId): ContentBrief
    {
        Gate::forUser($actor)->authorize('update', $project);

        return DB::transaction(function () use ($project, $actor, $clusterId): ContentBrief {
            $cluster = DB::table('keyword_clusters')
                ->join('keywords', 'keywords.id', '=', 'keyword_clusters.primary_keyword_id')
                ->where('keyword_clusters.project_id', $project->id)
                ->where('keyword_clusters.id', $clusterId)
                ->whereNull('keyword_clusters.archived_at')
                ->lockForUpdate()
                ->first(['keyword_clusters.id', 'keyword_clusters.intent', 'keywords.id as primary_id', 'keywords.display_text as primary']);
            if ($cluster === null) {
                throw ValidationException::withMessages(['cluster' => 'این خوشه در این پروژه پیدا نشد.']);
            }
            $existing = ContentBrief::query()->where('keyword_cluster_id', $clusterId)->first();
            if ($existing instanceof ContentBrief) {
                return $existing;
            }

            // Current volume = the latest observation, as in the SEO plan export.
            $secondary = DB::table('keyword_cluster_members')
                ->join('keywords', 'keywords.id', '=', 'keyword_cluster_members.keyword_id')
                ->leftJoin('keyword_metrics as volumes', function ($join): void {
                    $join->whereRaw("volumes.id = (select m.id from keyword_metrics m where m.keyword_id = keywords.id and m.metric = 'volume' order by m.observed_at desc, m.id desc limit 1)");
                })
                ->where('keyword_cluster_members.project_id', $project->id)
                ->where('keyword_cluster_members.keyword_cluster_id', $clusterId)
                ->where('keywords.id', '!=', $cluster->primary_id)
                ->orderByRaw('case when volumes.value is null then 1 else 0 end')
                ->orderByDesc('volumes.value')
                ->orderBy('keywords.id')
                ->limit(50)
                ->pluck('keywords.display_text')
                ->map(fn ($text): string => (string) $text)
                ->all();
            $pageId = DB::table('cluster_targets')->where('project_id', $project->id)
                ->where('keyword_cluster_id', $clusterId)->value('page_id');
            $primary = (string) $cluster->primary;

            $brief = ContentBrief::query()->create([
                'project_id' => $project->id,
                'keyword_cluster_id' => $clusterId,
                'page_id' => $pageId === null ? null : (int) $pageId,
                'created_by_id' => $actor->id,
                'title' => mb_substr($primary, 0, 255),
                'primary_keyword' => mb_substr($primary, 0, 255),
                'secondary_keywords' => array_values($secondary),
                'intent' => (string) $cluster->intent,
                'headings' => BriefOutline::headings($primary, array_values($secondary)),
                'status' => 'draft',
            ]);
            $this->record($project, $actor, 'content_brief.created', $brief->id, null, $this->snapshot($brief));

            return $brief;
        });
    }

    /** @param  array<string, mixed>  $data  validated subset of EDITABLE */
    public function update(Project $project, ContentBrief $brief, User $actor, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $project);
        DB::transaction(function () use ($project, $brief, $actor, $data): void {
            $locked = ContentBrief::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($brief->id);
            $before = $this->snapshot($locked);
            // A published brief always has a publication date: keep the known
            // one, or use today when it is first published without a date.
            if (($data['status'] ?? null) === 'published' && ($data['published_on'] ?? null) === null) {
                $data['published_on'] = $locked->published_on?->toDateString() ?? now()->toDateString();
            }
            $locked->fill($data)->save();
            $after = $this->snapshot($locked->refresh());
            if ($before !== $after) {
                $this->record($project, $actor, 'content_brief.updated', $locked->id, $before, $after);
            }
        });
    }

    public function delete(Project $project, ContentBrief $brief, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $project);
        DB::transaction(function () use ($project, $brief, $actor): void {
            $locked = ContentBrief::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($brief->id);
            $this->record($project, $actor, 'content_brief.deleted', $locked->id, $this->snapshot($locked), null);
            $locked->delete();
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(ContentBrief $brief): array
    {
        return [
            'keyword_cluster_id' => $brief->keyword_cluster_id,
            'page_id' => $brief->page_id,
            'title' => $brief->title,
            'primary_keyword' => $brief->primary_keyword,
            'secondary_keywords' => $brief->secondary_keywords,
            'intent' => $brief->intent,
            'headings' => $brief->headings,
            'notes' => $brief->notes,
            'status' => $brief->status,
            'due_on' => $brief->due_on?->toDateString(),
            'published_on' => $brief->published_on?->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Project $project, User $actor, string $action, int $briefId, ?array $before, ?array $after): void
    {
        $set = ChangeSet::query()->create([
            'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
            'action' => $action,
            'changes' => [['content_brief_id' => $briefId, 'before' => $before, 'after' => $after]],
        ]);
        ActivityEvent::query()->create([
            'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
            'change_set_id' => $set->id, 'event' => $action, 'metadata' => ['content_brief_id' => $briefId],
        ]);
    }
}
