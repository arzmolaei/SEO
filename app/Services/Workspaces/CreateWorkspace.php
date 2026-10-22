<?php

namespace App\Services\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class CreateWorkspace
{
    public function handle(User $creator, string $name): Workspace
    {
        return DB::transaction(function () use ($creator, $name): Workspace {
            $workspace = Workspace::query()->create([
                'name' => trim($name),
            ]);

            $workspace->memberships()->create([
                'user_id' => $creator->id,
                'role' => WorkspaceRole::Owner,
            ]);

            return $workspace;
        });
    }
}
