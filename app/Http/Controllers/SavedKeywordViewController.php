<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\SavedKeywordView;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\CreateSavedKeywordView;
use App\Services\Keywords\SetDefaultSavedKeywordView;
use App\Services\Keywords\UpdateSavedKeywordView;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SavedKeywordViewController extends Controller
{
    public function __construct(
        private ProjectPolicy $policy,
        private CreateSavedKeywordView $createSavedKeywordView,
        private UpdateSavedKeywordView $updateSavedKeywordView,
        private SetDefaultSavedKeywordView $setDefaultSavedKeywordView,
    ) {}

    /** @throws AuthorizationException */
    public function store(Request $request, Project $project): RedirectResponse
    {
        $user = $this->authorizedUser($request, $project);

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

    /** @throws AuthorizationException */
    public function update(
        Request $request,
        Project $project,
        SavedKeywordView $savedKeywordView,
    ): RedirectResponse {
        $user = $this->authorizedUser($request, $project);

        try {
            $this->updateSavedKeywordView->handle(
                actor: $user,
                project: $project,
                view: $savedKeywordView,
                name: $request->input('name'),
                state: $request->input('state'),
            );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return redirect()->route('projects.keywords.index', $project);
    }

    /** @throws AuthorizationException */
    public function setDefault(
        Request $request,
        Project $project,
        SavedKeywordView $savedKeywordView,
    ): RedirectResponse {
        $user = $this->authorizedUser($request, $project);
        $this->setDefaultSavedKeywordView->handle($user, $project, $savedKeywordView);

        return redirect()->route('projects.keywords.index', $project);
    }

    /** @throws AuthorizationException */
    private function authorizedUser(Request $request, Project $project): User
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->policy->view($user, $project)) {
            throw new AuthorizationException;
        }

        return $user;
    }
}
