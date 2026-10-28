<?php

namespace App\Http\Controllers;

use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Services\Pages\PageChecklistFacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PageWorkspaceController extends Controller
{
    public const STATUSES = ['todo', 'in_progress', 'done'];

    private const EDITABLE = ['seo_title', 'meta_description', 'h1', 'on_page_status', 'on_page_notes'];

    public function index(Request $request, Project $project, PageChecklistFacts $facts): Response
    {
        Gate::authorize('view', $project);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'kind' => ['nullable', Rule::in(['existing', 'planned'])],
        ]);
        $pages = Page::query()->where('project_id', $project->id)
            ->when($filters['q'] ?? null, function ($query, string $term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('url', 'like', $like)->orWhere('seo_title', 'like', $like));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('on_page_status', $status))
            ->when($filters['kind'] ?? null, fn ($query, string $kind) => $query->where('kind', $kind))
            ->orderBy('id')
            ->paginate(20)->withQueryString();
        $pageFacts = $facts->for($project, $pages->getCollection());

        return Inertia::render('projects/pages', [
            'pages' => $pages->through(fn (Page $page): array => [
                'id' => $page->id,
                'title' => $page->title,
                'kind' => $page->kind->value,
                'url' => $page->url,
                'seo_title' => $page->seo_title,
                'on_page_status' => $page->on_page_status,
                'score' => $pageFacts[$page->id]['score'],
                'targets' => $pageFacts[$page->id]['targets'],
            ]),
            'filters' => $filters,
            'canEdit' => Gate::allows('update', $project),
        ]);
    }

    public function show(Project $project, Page $page, PageChecklistFacts $facts): Response
    {
        Gate::authorize('view', $project);
        $fact = $facts->for($project, collect([$page]))[$page->id];
        $clusterIds = array_column($fact['targets'], 'cluster_id');
        $secondary = $clusterIds === [] ? [] : DB::table('keyword_cluster_members')
            ->join('keywords', 'keywords.id', '=', 'keyword_cluster_members.keyword_id')
            ->join('keyword_clusters', 'keyword_clusters.id', '=', 'keyword_cluster_members.keyword_cluster_id')
            ->where('keyword_cluster_members.project_id', $project->id)
            ->whereIn('keyword_cluster_members.keyword_cluster_id', $clusterIds)
            ->whereColumn('keywords.id', '!=', 'keyword_clusters.primary_keyword_id')
            ->orderBy('keywords.id')->limit(15)
            ->pluck('keywords.display_text')->unique()->values()->all();

        return Inertia::render('projects/page-detail', [
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'kind' => $page->kind->value,
                'url' => $page->url,
            ] + $page->only(self::EDITABLE),
            'targets' => $fact['targets'],
            'secondaryKeywords' => $secondary,
            'checklist' => $fact['items'],
            'score' => $fact['score'],
            'canEdit' => Gate::allows('update', $project),
        ]);
    }

    public function update(Request $request, Project $project, Page $page): RedirectResponse
    {
        Gate::authorize('update', $project);
        $data = $request->validate([
            'seo_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'h1' => ['nullable', 'string', 'max:255'],
            'on_page_status' => ['required', Rule::in(self::STATUSES)],
            'on_page_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        DB::transaction(function () use ($project, $page, $data, $actor): void {
            $locked = Page::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($page->id);
            $before = $locked->only(self::EDITABLE);
            $locked->fill($data)->save();
            $after = $locked->only(self::EDITABLE);
            if ($before === $after) {
                return;
            }
            $set = ChangeSet::query()->create([
                'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
                'action' => 'page.on_page_updated',
                'changes' => [['page_id' => $locked->id, 'before' => $before, 'after' => $after]],
            ]);
            ActivityEvent::query()->create([
                'workspace_id' => $project->workspace_id, 'project_id' => $project->id, 'actor_id' => $actor->id,
                'change_set_id' => $set->id, 'event' => 'page.on_page_updated', 'metadata' => ['page_id' => $locked->id],
            ]);
        });

        return to_route('projects.pages.show', ['project' => $project, 'page' => $page]);
    }
}
