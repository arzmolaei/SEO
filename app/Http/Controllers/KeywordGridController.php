<?php

namespace App\Http\Controllers;

use App\Domain\Keywords\KeywordGridQuery;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\QueryKeywordGrid;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class KeywordGridController extends Controller
{
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

        try {
            $filters = KeywordGridQuery::fromArray($request->query());
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return Inertia::render('projects/keywords', [
            'keywords' => $this->queryKeywordGrid->handle($project, $filters),
            'filters' => $filters->toArray(),
        ]);
    }
}
