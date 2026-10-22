<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\CreateSavedKeywordView;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SavedKeywordViewController extends Controller
{
    public function __construct(
        private ProjectPolicy $policy,
        private CreateSavedKeywordView $createSavedKeywordView,
    ) {}

    /** @throws AuthorizationException */
    public function store(Request $request, Project $project): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->policy->view($user, $project)) {
            throw new AuthorizationException;
        }

        try {
            $this->createSavedKeywordView->handle(
                actor: $user,
                project: $project,
                name: $request->input('name'),
                state: $request->input('state'),
            );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return redirect()->route('projects.keywords.index', $project);
    }
}
