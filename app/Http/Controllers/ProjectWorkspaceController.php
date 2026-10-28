<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Services\Projects\ProjectGuide;
use App\Support\ProjectWorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectWorkspaceController extends Controller
{
    public function __construct(private ProjectPolicy $policy) {}

    /** @throws AuthorizationException */
    public function overview(Request $request, Project $project, ProjectGuide $guide): Response
    {
        $this->authorizeProject($request, $project);

        return Inertia::render('projects/overview', [
            'guide' => fn (): array => $guide->for($project),
        ]);
    }

    /** @throws AuthorizationException */
    public function section(Request $request, Project $project, string $section): Response
    {
        $this->authorizeProject($request, $project);

        if (! in_array($section, ProjectWorkspaceContext::SECTIONS, true) || $section === 'overview') {
            abort(404);
        }

        return Inertia::render('projects/section', [
            'section' => $section,
        ]);
    }

    /** @throws AuthorizationException */
    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->policy->view($user, $project)) {
            throw new AuthorizationException;
        }
    }
}
