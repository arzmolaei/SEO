<?php

namespace App\Services\Backup;

use App\Enums\WorkspaceRole;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\LifecycleMutexHandle;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CleanRestoreTarget
{
    public function __construct(
        private readonly InstallationState $installationState,
        private readonly RestoreJournal $journal,
        private readonly LifecycleMutex $mutex,
        private readonly ApplicationTableCatalog $catalog,
    ) {}

    public function assertReady(string $uploadId): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $uploadId) !== 1) {
            throw $this->notClean();
        }

        $details = $this->installationState->details();
        if (
            $details === null
            || ! is_int($details['owner_user_id'] ?? null)
            || $details['owner_user_id'] < 1
            || ! empty($details['restored_from_installation_id'] ?? null)
            || ! empty($details['restored_at'] ?? null)
        ) {
            throw $this->notClean();
        }

        $state = $this->journal->state();
        if (
            $state !== null
            && (
                ($state['phase'] ?? null) !== 'uploaded'
                || ($state['upload_id'] ?? null) !== $uploadId
            )
        ) {
            throw $this->notClean();
        }

        $ownerId = $details['owner_user_id'];
        if (
            DB::table('users')->count() !== 1
            || DB::table('workspaces')->count() !== 1
            || DB::table('memberships')->count() !== 1
            || ! DB::table('memberships')
                ->where('user_id', $ownerId)
                ->where('role', WorkspaceRole::Owner->value)
                ->exists()
            || DB::table('passkeys')->where('user_id', '!=', $ownerId)->exists()
        ) {
            throw $this->notClean();
        }

        foreach ($this->catalog->tableNames() as $table) {
            if (in_array($table, ['users', 'passkeys', 'workspaces', 'memberships'], true)) {
                continue;
            }

            if (DB::table($table)->exists()) {
                throw $this->notClean();
            }
        }

        $this->assertDurableRootsEmpty();
        $this->assertNoOtherLifecycleOperation();
    }

    private function assertDurableRootsEmpty(): void
    {
        $roots = config('backup.durable_file_roots', []);
        if (! is_array($roots)) {
            throw $this->notClean();
        }

        foreach ($roots as $root) {
            if (! is_string($root)) {
                throw $this->notClean();
            }

            if (is_link($root)) {
                throw $this->notClean();
            }
            if (! file_exists($root)) {
                continue;
            }
            if (! is_dir($root)) {
                throw $this->notClean();
            }

            $pending = [$root];
            while ($pending !== []) {
                $directory = array_pop($pending);
                $items = scandir($directory);
                if ($items === false) {
                    throw $this->notClean();
                }

                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }

                    $path = $directory.DIRECTORY_SEPARATOR.$item;
                    if (is_link($path) || is_file($path)) {
                        throw $this->notClean();
                    }
                    if (! is_dir($path)) {
                        throw $this->notClean();
                    }

                    $pending[] = $path;
                }
            }
        }
    }

    private function assertNoOtherLifecycleOperation(): void
    {
        $handle = null;

        try {
            $handle = $this->mutex->acquire('restore-target-check');
        } catch (Throwable) {
            throw $this->notClean();
        } finally {
            if ($handle instanceof LifecycleMutexHandle) {
                $handle->release();
            }
        }
    }

    private function notClean(): BackupException
    {
        return new BackupException(
            'restore_target_not_clean',
            'این نصب برای بازیابی کامل خالی و آماده نیست.',
        );
    }
}
