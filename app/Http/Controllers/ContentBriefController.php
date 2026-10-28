<?php

namespace App\Http\Controllers;

use App\Models\ContentBrief;
use App\Models\Project;
use App\Models\User;
use App\Services\Content\ContentBriefs;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContentBriefController extends Controller
{
    private const AGENDA_LIMIT = 50;

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(ContentBrief::STATUSES)],
            'cluster_q' => ['nullable', 'string', 'max:200'],
        ]);
        $briefs = ContentBrief::query()->where('project_id', $project->id)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByRaw('case when due_on is null then 1 else 0 end')->orderBy('due_on')->orderBy('id')
            ->paginate(20)->withQueryString();

        return Inertia::render('projects/content', [
            'briefs' => $briefs->through(fn (ContentBrief $brief): array => $this->row($brief)),
            'filters' => ['status' => $filters['status'] ?? null],
            'agenda' => fn (): array => $this->agenda($project),
            'clusterOptions' => fn (): array => $this->clusterOptions($project, $filters['cluster_q'] ?? null),
            'canEdit' => Gate::allows('update', $project),
        ]);
    }

    public function store(Request $request, Project $project, ContentBriefs $briefs): RedirectResponse
    {
        Gate::authorize('update', $project);
        $data = $request->validate(['cluster_id' => ['required', 'integer']]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $brief = $briefs->generate($project, $actor, (int) $data['cluster_id']);

        return to_route('projects.content.show', ['project' => $project, 'contentBrief' => $brief]);
    }

    public function show(Project $project, ContentBrief $contentBrief): Response
    {
        Gate::authorize('view', $project);
        $page = $contentBrief->page_id === null ? null : DB::table('pages')
            ->where('project_id', $project->id)->where('id', $contentBrief->page_id)->first(['id', 'title', 'url']);

        return Inertia::render('projects/content-brief', [
            'brief' => $this->row($contentBrief) + [
                'secondary_keywords' => $contentBrief->secondary_keywords,
                'headings' => $contentBrief->headings,
                'notes' => $contentBrief->notes,
            ],
            'page' => $page === null ? null : ['id' => (int) $page->id, 'title' => $page->title, 'url' => $page->url],
            'canEdit' => Gate::allows('update', $project),
        ]);
    }

    public function update(Request $request, Project $project, ContentBrief $contentBrief, ContentBriefs $briefs): RedirectResponse
    {
        Gate::authorize('update', $project);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'headings' => ['present', 'array', 'max:40'],
            // Blank lines arrive as null (TrimStrings/ConvertEmptyStringsToNull).
            'headings.*' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'status' => ['required', Rule::in(ContentBrief::STATUSES)],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'published_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $data['headings'] = array_values(array_filter(
            array_map(fn (?string $heading): string => trim((string) $heading), $data['headings']),
            fn (string $heading): bool => $heading !== '',
        ));
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $briefs->update($project, $contentBrief, $actor, $data);

        return to_route('projects.content.show', ['project' => $project, 'contentBrief' => $contentBrief]);
    }

    public function destroy(Request $request, Project $project, ContentBrief $contentBrief, ContentBriefs $briefs): RedirectResponse
    {
        Gate::authorize('update', $project);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $briefs->delete($project, $contentBrief, $actor);

        return to_route('projects.content.index', $project);
    }

    /** @return array{id: int, title: string, primary_keyword: string, intent: string, status: string, due_on: ?string, published_on: ?string, cluster_id: ?int, page_id: ?int} */
    private function row(ContentBrief $brief): array
    {
        return [
            'id' => $brief->id,
            'title' => $brief->title,
            'primary_keyword' => $brief->primary_keyword,
            'intent' => $brief->intent,
            'status' => $brief->status,
            'due_on' => $brief->due_on?->toDateString(),
            'published_on' => $brief->published_on?->toDateString(),
            'cluster_id' => $brief->keyword_cluster_id,
            'page_id' => $brief->page_id,
        ];
    }

    /**
     * Unpublished briefs by due date relative to today, plus recent
     * publications. Locale-neutral buckets instead of a month grid, so the
     * view reads the same in the Persian and Gregorian calendars.
     *
     * @return array<string, array{count: int, items: list<array<string, mixed>>}>
     */
    private function agenda(Project $project): array
    {
        $today = CarbonImmutable::today();
        $open = fn (): Builder => ContentBrief::query()->where('project_id', $project->id)->where('status', '!=', 'published');
        $buckets = [
            'overdue' => $open()->where('due_on', '<', $today->toDateString()),
            'week' => $open()->whereBetween('due_on', [$today->toDateString(), $today->addDays(7)->toDateString()]),
            'month' => $open()->where('due_on', '>', $today->addDays(7)->toDateString())->where('due_on', '<=', $today->addDays(30)->toDateString()),
            'later' => $open()->where('due_on', '>', $today->addDays(30)->toDateString()),
            'unscheduled' => $open()->whereNull('due_on'),
            'published' => ContentBrief::query()->where('project_id', $project->id)->where('status', 'published')
                ->where('published_on', '>=', $today->subDays(30)->toDateString()),
        ];
        $agenda = [];
        foreach ($buckets as $key => $query) {
            $ordered = $key === 'published'
                ? (clone $query)->orderByDesc('published_on')->orderByDesc('id')
                : (clone $query)->orderBy('due_on')->orderBy('id');
            $agenda[$key] = [
                'count' => (clone $query)->count(),
                'items' => array_values($ordered->limit(self::AGENDA_LIMIT)->get()->map(fn (ContentBrief $brief): array => $this->row($brief))->all()),
            ];
        }

        return $agenda;
    }

    /** @return list<array{id: int, name: string, primary_keyword: string, has_brief: bool}> */
    private function clusterOptions(Project $project, ?string $term): array
    {
        return array_values(DB::table('keyword_clusters')
            ->join('keywords', 'keywords.id', '=', 'keyword_clusters.primary_keyword_id')
            ->leftJoin('content_briefs', 'content_briefs.keyword_cluster_id', '=', 'keyword_clusters.id')
            ->where('keyword_clusters.project_id', $project->id)
            ->whereNull('keyword_clusters.archived_at')
            ->when($term !== null && trim($term) !== '', function ($query) use ($term): void {
                $like = '%'.addcslashes(trim((string) $term), '%_\\').'%';
                $query->where(fn ($q) => $q->where('keyword_clusters.name', 'like', $like)->orWhere('keywords.display_text', 'like', $like));
            })
            ->orderByRaw('case when content_briefs.id is null then 0 else 1 end')
            ->orderBy('keyword_clusters.id')
            ->limit(20)
            ->get(['keyword_clusters.id', 'keyword_clusters.name', 'keywords.display_text', 'content_briefs.id as brief_id'])
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'primary_keyword' => (string) $row->display_text,
                'has_brief' => $row->brief_id !== null,
            ])->all());
    }
}
