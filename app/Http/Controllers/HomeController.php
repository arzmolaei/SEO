<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Policies\WorkspacePolicy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Persian-first entry point after installation and login: the member's own
 * workspaces and a server-paginated list of their projects.
 */
final class HomeController extends Controller
{
    private const PROJECTS_PER_PAGE = 25;

    public function __invoke(Request $request, WorkspacePolicy $workspacePolicy): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $memberships = Membership::query()
            ->where('user_id', $user->id)
            ->with('workspace:id,name')
            ->orderBy('workspace_id')
            ->limit(100)
            ->get();
        $workspaceIds = $memberships->pluck('workspace_id')->all();

        $projects = Project::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->with('workspace:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PROJECTS_PER_PAGE, ['id', 'workspace_id', 'name'])
            ->withQueryString()
            ->through(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'workspace_name' => $project->workspace?->name,
                'overview_url' => route('projects.overview', $project),
                'keywords_url' => route('projects.keywords.index', $project),
                'imports_url' => route('projects.keyword-imports.index', $project),
            ]);

        return Inertia::render('home', [
            'workspaces' => $memberships
                ->filter(fn (Membership $membership): bool => $membership->workspace !== null)
                ->map(fn (Membership $membership): array => [
                    'id' => $membership->workspace_id,
                    'name' => $membership->workspace->name,
                    'canCreateProjects' => $workspacePolicy->manageProjects($user, $membership->workspace),
                ])
                ->values(),
            'projects' => $projects,
        ]);
    }
}
