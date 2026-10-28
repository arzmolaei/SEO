<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\Tasks\ProjectTasks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['open', 'overdue', 'done', 'all'])],
            'page_id' => ['nullable', 'integer'],
            'cluster_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $view = $filters['view'] ?? 'open';
        $today = CarbonImmutable::today()->toDateString();
        $scoped = fn (): Builder => ProjectTask::query()->where('project_id', $project->id)
            ->when($filters['page_id'] ?? null, fn (Builder $query, int $id) => $query->where('page_id', $id))
            ->when($filters['cluster_id'] ?? null, fn (Builder $query, int $id) => $query->where('keyword_cluster_id', $id));

        $tasks = $scoped()
            ->when($view === 'open', fn (Builder $query) => $query->where('status', '!=', 'done'))
            ->when($view === 'overdue', fn (Builder $query) => $query->where('status', '!=', 'done')->where('due_on', '<', $today))
            ->when($view === 'done', fn (Builder $query) => $query->where('status', 'done'))
            ->when($filters['q'] ?? null, function (Builder $query, string $term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('notes', 'like', $like));
            })
            ->when($view === 'done',
                fn (Builder $query) => $query->orderByDesc('completed_at')->orderByDesc('id'),
                fn (Builder $query) => $query->orderByRaw('case when due_on is null then 1 else 0 end')->orderBy('due_on')->orderBy('id'))
            ->paginate(25)->withQueryString();

        $collection = $tasks->getCollection();
        $pages = DB::table('pages')->where('project_id', $project->id)
            ->whereIn('id', $collection->pluck('page_id')->filter()->unique()->values()->all())
            ->get(['id', 'title', 'url'])->keyBy('id');
        $clusters = DB::table('keyword_clusters')->where('project_id', $project->id)
            ->whereIn('id', $collection->pluck('keyword_cluster_id')->filter()->unique()->values()->all())
            ->get(['id', 'name'])->keyBy('id');

        return Inertia::render('projects/tasks', [
            'tasks' => $tasks->through(fn (ProjectTask $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'notes' => $task->notes,
                'status' => $task->status,
                'due_on' => $task->due_on?->toDateString(),
                'overdue' => $task->status !== 'done' && $task->due_on !== null && $task->due_on->toDateString() < $today,
                'completed_at' => $task->completed_at?->toIso8601String(),
                'page' => ($page = $pages->get($task->page_id)) === null ? null
                    : ['id' => (int) $page->id, 'label' => (string) ($page->title ?? $page->url ?? '')],
                'cluster' => ($cluster = $clusters->get($task->keyword_cluster_id)) === null ? null
                    : ['id' => (int) $cluster->id, 'label' => (string) $cluster->name],
            ]),
            'filters' => ['view' => $view] + array_intersect_key($filters, array_flip(['page_id', 'cluster_id', 'q'])),
            'counts' => fn (): array => [
                'open' => $scoped()->where('status', '!=', 'done')->count(),
                'overdue' => $scoped()->where('status', '!=', 'done')->where('due_on', '<', $today)->count(),
                'done' => $scoped()->where('status', 'done')->count(),
            ],
            'context' => fn (): array => $this->context($project, $filters['page_id'] ?? null, $filters['cluster_id'] ?? null),
            'canEdit' => Gate::allows('update', $project),
        ]);
    }

    public function store(Request $request, Project $project, ProjectTasks $tasks): RedirectResponse
    {
        Gate::authorize('update', $project);
        $data = $request->validate($this->rules(false));
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $tasks->create($project, $actor, $data);

        return back();
    }

    public function update(Request $request, Project $project, ProjectTask $task, ProjectTasks $tasks): RedirectResponse
    {
        Gate::authorize('update', $project);
        $data = $request->validate($this->rules(true));
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $tasks->update($project, $task, $actor, $data);

        return back();
    }

    public function destroy(Request $request, Project $project, ProjectTask $task, ProjectTasks $tasks): RedirectResponse
    {
        Gate::authorize('update', $project);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $tasks->delete($project, $task, $actor);

        return back();
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(ProjectTask::STATUSES)],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'page_id' => ['sometimes', 'nullable', 'integer'],
            'keyword_cluster_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * The page or cluster the list is filtered to, so new tasks link to it.
     *
     * @return array{page: array{id: int, label: string}|null, cluster: array{id: int, label: string}|null}
     */
    private function context(Project $project, ?int $pageId, ?int $clusterId): array
    {
        $page = $pageId === null ? null : DB::table('pages')->where('project_id', $project->id)->where('id', $pageId)->first(['id', 'title', 'url']);
        $cluster = $clusterId === null ? null : DB::table('keyword_clusters')->where('project_id', $project->id)->where('id', $clusterId)->first(['id', 'name']);

        return [
            'page' => $page === null ? null : ['id' => (int) $page->id, 'label' => (string) ($page->title ?? $page->url ?? '')],
            'cluster' => $cluster === null ? null : ['id' => (int) $cluster->id, 'label' => (string) $cluster->name],
        ];
    }
}
