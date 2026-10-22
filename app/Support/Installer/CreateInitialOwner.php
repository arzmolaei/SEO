<?php

namespace App\Support\Installer;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class CreateInitialOwner
{
    public function __construct(
        private readonly InstallerPasswordRules $passwordRules,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'workspace_name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules->rules(),
        ])->validate();

        $normalized = [
            'name' => trim((string) $validated['name']),
            'email' => Str::lower(trim((string) $validated['email'])),
            'workspace_name' => trim((string) $validated['workspace_name']),
            'password' => (string) $validated['password'],
        ];

        return DB::transaction(function () use ($normalized): User {
            if (User::query()->count() === 0) {
                return $this->createFreshOwner($normalized);
            }

            return $this->recoverExistingOwner($normalized);
        });
    }

    /**
     * @param  array{name: string, email: string, workspace_name: string, password: string}  $input
     */
    private function createFreshOwner(array $input): User
    {
        if (Workspace::query()->exists() || Membership::query()->exists()) {
            throw new RuntimeException('Unexpected ownership data already exists.');
        }

        $user = new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
        $user->forceFill([
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $user->save();

        $workspace = Workspace::query()->create([
            'name' => $input['workspace_name'],
        ]);

        Membership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceRole::Owner,
        ]);

        return $user;
    }

    /**
     * Resolve the only safe response-loss retry shape without adopting unrelated users.
     *
     * @param  array{name: string, email: string, workspace_name: string, password: string}  $input
     */
    private function recoverExistingOwner(array $input): User
    {
        if (
            User::query()->count() !== 1
            || Workspace::query()->count() !== 1
            || Membership::query()->count() !== 1
        ) {
            throw new RuntimeException('Unexpected users or ownership data already exist.');
        }

        $user = User::query()->sole();
        $workspace = Workspace::query()->sole();
        $membership = Membership::query()->sole();

        $matchesBootstrap = $user->name === $input['name']
            && Str::lower($user->email) === $input['email']
            && $user->email_verified_at !== null
            && $workspace->name === $input['workspace_name']
            && $membership->workspace_id === $workspace->id
            && $membership->user_id === $user->id
            && $membership->role === WorkspaceRole::Owner
            && Hash::check($input['password'], $user->password);

        if (! $matchesBootstrap) {
            throw new RuntimeException('Existing ownership data does not match this installer request.');
        }

        if ($user->onboarded_at === null) {
            $user->forceFill(['onboarded_at' => now()])->save();
        }

        return $user;
    }
}