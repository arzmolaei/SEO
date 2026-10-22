<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SavedKeywordView;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\QueryKeywordGrid;
use App\Services\Keywords\ResolveKeywordGridView;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class KeywordGridController extends Controller
{
    public function __construct(
        private ProjectPolicy $policy,
        private QueryKeywordGrid $queryKeywordGrid,
        private ResolveKeywordGridView $resolveKeywordGridView,
    ) {}

    /** @throws AuthorizationException */
    public function index(Request $request, Project $project): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->policy->view($user, $project)) {
            throw new AuthorizationException;
        }

        try {
            $resolution = $this->resolveKeywordGridView->handle($user, $project, $request->query());
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        $filters = $resolution['filters'];
        $activeView = $resolution['activeView'];
        $savedViews = $this->savedViews($user, $project);

        return Inertia::render('projects/keywords', [
            'keywords' => $this->queryKeywordGrid->handle($project, $filters),
            'filters' => $filters->toArray(),
            'savedViews' => $savedViews->map(fn (SavedKeywordView $view): array => $this->serializeView($view))->values(),
            'activeView' => $activeView === null ? null : $this->serializeView($activeView),
        ]);
    }

    /** @return Collection<int, SavedKeywordView> */
    private function savedViews(User $user, Project $project): Collection
    {
        return SavedKeywordView::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $project->workspace_id)
            ->where('project_id', $project->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     schema_version: int,
     *     state: array{query: array<string, mixed>, columns: list<string>},
     *     is_default: bool
     * }
     */
    private function serializeView(SavedKeywordView $view): array
    {
        return [
            'id' => $view->id,
            'name' => $view->name,
            'schema_version' => $view->schema_version,
            'state' => $view->state,
            'is_default' => $view->is_default,
        ];
    }
}
