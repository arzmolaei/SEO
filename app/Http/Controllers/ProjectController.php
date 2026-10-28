<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\ProjectPolicy;
use App\Policies\WorkspacePolicy;
use App\Services\Projects\CreateProject;
use App\Services\Projects\UpdateProject;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function __construct(
        private WorkspacePolicy $workspacePolicy,
        private ProjectPolicy $projectPolicy,
    ) {}

    /** @throws AuthorizationException */
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $user = $this->user($request);

        if (! $this->workspacePolicy->view($user, $workspace)) {
            throw new AuthorizationException;
        }

        $projects = Project::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'name']);

        return response()->json(['data' => $projects]);
    }

    /** @throws AuthorizationException */
    public function store(Request $request, Workspace $workspace, CreateProject $createProject): JsonResponse|RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);
        if ($request->string('name')->trim()->isEmpty()) {
            throw ValidationException::withMessages(['name' => 'نام پروژه را وارد کنید.']);
        }

        $project = $createProject->handle(
            actor: $this->user($request),
            workspace: $workspace,
            name: $request->string('name')->trim()->toString(),
        );

        if (! $request->expectsJson()) {
            return redirect()->route('projects.overview', $project);
        }

        return response()->json(['data' => $this->projectData($project)], 201);
    }

    /** @throws AuthorizationException */
    public function show(Request $request, Project $project): JsonResponse
    {
        if (! $this->projectPolicy->view($this->user($request), $project)) {
            throw new AuthorizationException;
        }

        return response()->json(['data' => $this->projectData($project)]);
    }

    /** @throws AuthorizationException */
    public function update(Request $request, Project $project, UpdateProject $updateProject): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $project = $updateProject->handle(
            actor: $this->user($request),
            project: $project,
            name: $request->string('name')->trim()->toString(),
        );

        return response()->json(['data' => $this->projectData($project)]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /** @return array{id: int, workspace_id: int, name: string} */
    private function projectData(Project $project): array
    {
        return [
            'id' => $project->id,
            'workspace_id' => $project->workspace_id,
            'name' => $project->name,
        ];
    }
}
