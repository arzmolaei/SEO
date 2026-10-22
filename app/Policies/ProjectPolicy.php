<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return Membership::query()
            ->where('workspace_id', $project->workspace_id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function update(User $user, Project $project): bool
    {
        return $this->canManageProject($user, $project);
    }

    public function stageKeywordImports(User $user, Project $project): bool
    {
        return $this->canManageProject($user, $project);
    }

    public function manageKeywords(User $user, Project $project): bool
    {
        return $this->canManageProject($user, $project);
    }

    private function canManageProject(User $user, Project $project): bool
    {
        $membership = Membership::query()
            ->where('workspace_id', $project->workspace_id)
            ->where('user_id', $user->id)
            ->first();

        return $membership?->role->canManageProjects() ?? false;
    }
}
