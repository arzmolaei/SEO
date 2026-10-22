<?php

namespace App\Http\Controllers;

use App\Models\ChangeSet;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Keywords\UndoKeywordChangeSet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class KeywordChangeSetController extends Controller
{
    public function __construct(
        private ProjectPolicy $policy,
        private UndoKeywordChangeSet $undoKeywordChangeSet,
    ) {}

    /** @throws AuthorizationException */
    public function undo(Request $request, Project $project, ChangeSet $changeSet): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->policy->manageKeywords($user, $project)) {
            throw new AuthorizationException;
        }

        $this->undoKeywordChangeSet->handle($user, $project, $changeSet);

        return redirect()->route('projects.keywords.index', $project);
    }
}
