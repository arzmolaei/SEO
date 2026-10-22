<?php

namespace App\Http\Controllers;

use App\Domain\Keywords\KeywordGridQuery;
use App\Models\Project;
use App\Models\SavedKeywordView;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\QueryKeywordGrid;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class KeywordGridController extends Controller
{
    /** @var list<string> */
    private const GRID_QUERY_KEYS = [
        'q',
        'source',
        'status',
        'volume_operator',
        'volume',
        'sort',
        'direction',
        'per_page',
    ];

    public function __construct(
        private ProjectPolicy $policy,
        private QueryKeywordGrid $queryKeywordGrid,
    ) {}

    /** @throws AuthorizationException */
    public function index(Request $request, Project $project): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->policy->view($user, $project)) {
            throw new AuthorizationException;
        }

        $savedViews = $this->savedViews($user, $project);
        $activeView = $this->resolveActiveView($request, $user, $project, $savedViews);
        $filterInput = $this->filterInput($request, $activeView);

        try {
            $filters = KeywordGridQuery::fromArray($filterInput);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

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
            ->where('project_id', $project->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, SavedKeywordView>  $savedViews
     *
     * @throws AuthorizationException
     */
    private function resolveActiveView(
        Request $request,
        User $user,
        Project $project,
        Collection $savedViews,
    ): ?SavedKeywordView {
        $requestedView = $request->query('view');

        if ($requestedView !== null && $requestedView !== '') {
            if (! is_string($requestedView) || ! ctype_digit($requestedView)) {
                abort(422, 'Saved keyword view id must be a positive integer.');
            }

            $view = SavedKeywordView::query()->find((int) $requestedView);

            if (! $view instanceof SavedKeywordView) {
                abort(404);
            }

            if (
                $view->user_id !== $user->id
                || $view->project_id !== $project->id
                || $view->workspace_id !== $project->workspace_id
            ) {
                throw new AuthorizationException;
            }

            return $view;
        }

        if ($this->hasExplicitGridQuery($request)) {
            return null;
        }

        return $savedViews->firstWhere('is_default', true);
    }

    /** @return array<string, mixed> */
    private function filterInput(Request $request, ?SavedKeywordView $activeView): array
    {
        $input = [];

        if ($activeView !== null) {
            $state = $activeView->state;
            $input = $state['query'];
        }

        foreach (self::GRID_QUERY_KEYS as $key) {
            if ($request->query->has($key)) {
                $input[$key] = $request->query($key);
            }
        }

        return $input;
    }

    private function hasExplicitGridQuery(Request $request): bool
    {
        foreach (self::GRID_QUERY_KEYS as $key) {
            if ($request->query->has($key)) {
                return true;
            }
        }

        return false;
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
