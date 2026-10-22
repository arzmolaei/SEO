<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;

class UpdateProject
{
    public function __construct(private ProjectPolicy $policy) {}

    /** @throws AuthorizationException */
    public function handle(User $actor, Project $project, string $name): Project
    {
        if (! $this->policy->update($actor, $project)) {
            throw new AuthorizationException;
        }

        $project->update([
            'name' => trim($name),
        ]);

        return $project->fresh() ?? $project;
    }
}
