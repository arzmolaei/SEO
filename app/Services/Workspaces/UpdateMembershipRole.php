<?php

namespace App\Services\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Policies\WorkspacePolicy;
use Illuminate\Validation\ValidationException;

class UpdateMembershipRole
{
    public function __construct(private WorkspacePolicy $policy) {}

    public function handle(User $actor, Membership $membership, WorkspaceRole $role): void
    {
        $workspace = $membership->workspace()->firstOrFail();

        if (! $this->policy->manageMembers($actor, $workspace)) {
            throw ValidationException::withMessages([
                'membership' => 'You do not have permission to manage workspace members.',
            ]);
        }

        if ($membership->role === WorkspaceRole::Owner && $role !== WorkspaceRole::Owner) {
            $ownerCount = Membership::query()
                ->where('workspace_id', $membership->workspace_id)
                ->where('role', WorkspaceRole::Owner->value)
                ->count();

            if ($ownerCount <= 1) {
                throw ValidationException::withMessages([
                    'role' => 'A workspace must always have at least one owner.',
                ]);
            }
        }

        $membership->update(['role' => $role]);
    }
}
