<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return Membership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        $membership = $this->membership($user, $workspace);

        return $membership?->role->canManageMembers() ?? false;
    }

    public function manageProjects(User $user, Workspace $workspace): bool
    {
        $membership = $this->membership($user, $workspace);

        return $membership?->role->canManageProjects() ?? false;
    }

    private function membership(User $user, Workspace $workspace): ?Membership
    {
        return Membership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->first();
    }
}
