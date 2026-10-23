<?php

namespace App\Support;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use RuntimeException;

final class SystemOwner
{
    public function __construct(private readonly InstallationState $installationState) {}

    public function resolve(): User
    {
        $metadata = $this->installationState->details();

        if ($metadata === null) {
            throw new RuntimeException('System owner identity is unavailable.');
        }

        $persistedOwnerId = filter_var($metadata['owner_user_id'] ?? null, FILTER_VALIDATE_INT);

        if (is_int($persistedOwnerId) && $persistedOwnerId > 0) {
            $owner = User::query()->find($persistedOwnerId);

            if ($owner === null) {
                throw new RuntimeException('System owner account is unavailable.');
            }

            return $owner;
        }

        $ownerIds = Membership::query()
            ->where('role', WorkspaceRole::Owner->value)
            ->select('user_id')
            ->distinct()
            ->orderBy('user_id')
            ->limit(2)
            ->pluck('user_id')
            ->all();

        if ($ownerIds === []) {
            throw new RuntimeException('System owner identity is unavailable.');
        }

        if (count($ownerIds) !== 1) {
            throw new RuntimeException('System owner identity is ambiguous.');
        }

        $ownerId = (int) $ownerIds[0];
        $owner = User::query()->find($ownerId);

        if ($owner === null) {
            throw new RuntimeException('System owner account is unavailable.');
        }

        $this->installationState->updateMetadata([
            'owner_user_id' => $ownerId,
        ]);

        return $owner;
    }

    public function is(User $user): bool
    {
        return $this->resolve()->getKey() === $user->getKey();
    }
}
