<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use Illuminate\Auth\Access\AuthorizationException;

class CreateProject
{
    public function __construct(private WorkspacePolicy $policy) {}

    /** @throws AuthorizationException */
    public function handle(User $actor, Workspace $workspace, string $name): Project
    {
        if (! $this->policy->manageProjects($actor, $workspace)) {
            throw new AuthorizationException;
        }

        return Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => trim($name),
        ]);
    }
}
