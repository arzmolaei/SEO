<?php

namespace App\Services\Update;

use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\ProductVersion;
use App\Support\Update\ActiveReleasePointer;

/**
 * Abandons an update that has not reached the release switch.
 *
 * Phases up to `staged` never change the active release pointer, the database
 * or installation identity, so returning to `ready` is provably safe: the
 * operator gets the "retryable operator state" the design requires instead of
 * a permanent maintenance gate. Staged release slots are immutable and reused
 * by a later identical package, so they are left in place.
 */
final class CancelPendingUpdate
{
    private const CANCELLABLE_PHASES = [
        'uploaded',
        'verified',
        'preparing',
        'quiescing',
        'snapshot_ready',
        'staged',
    ];

    public function __construct(
        private readonly UpdateJournal $journal,
        private readonly LifecycleMutex $mutex,
        private readonly ActiveReleasePointer $pointer,
        private readonly ProductVersion $productVersion,
    ) {}

    /** @return array{status: string} */
    public function handle(): array
    {
        $ownership = $this->mutex->acquire('update-cancel');
        try {
            $ownership->assertActive();
            $state = $this->journal->state();
            if ($state === null) {
                return ['status' => 'ready'];
            }
            if (! in_array($state['phase'] ?? null, self::CANCELLABLE_PHASES, true)) {
                throw $this->invalidState();
            }
            $this->assertSourceStillActive($state);

            $uploadId = $state['upload_id'] ?? null;
            if (! is_string($uploadId) || preg_match('/\A[a-f0-9]{32}\z/', $uploadId) !== 1) {
                throw $this->invalidState();
            }
            // The journal goes last: until it is cleared, maintenance stays on.
            foreach ([
                $this->path('update.safety_path', 'app/lifecycle/update/safety', $uploadId.'.seobak'),
                $this->path('update.safety_path', 'app/lifecycle/update/safety', $uploadId.'.json'),
                $this->path('update.upload_path', 'app/lifecycle/update/uploads', $uploadId.'.seoupdate'),
            ] as $artifact) {
                if (is_link($artifact) || (file_exists($artifact) && ! @unlink($artifact))) {
                    throw $this->invalidState();
                }
            }
            $this->journal->clear();

            return ['status' => 'ready'];
        } finally {
            $ownership->release();
        }
    }

    /** @param array<string, mixed> $state */
    private function assertSourceStillActive(array $state): void
    {
        $current = $this->pointer->current();
        if (($current['product_version'] ?? null) !== $this->productVersion->requireDistributable()) {
            throw $this->invalidState();
        }
        $recordedKind = $state['source_release_kind'] ?? null;
        if ($recordedKind !== null && (
            $current['kind'] !== $recordedKind
            || ($current['release_id'] ?? null) !== ($state['source_release_id'] ?? null)
        )) {
            throw $this->invalidState();
        }
        $target = $state['target_release_id'] ?? null;
        if (is_string($target) && ($current['release_id'] ?? null) === $target) {
            throw $this->invalidState();
        }
    }

    private function path(string $config, string $default, string $file): string
    {
        return rtrim((string) config($config, storage_path($default)), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;
    }

    private function invalidState(): UpdateException
    {
        return new UpdateException('update_invalid_state', 'وضعیت ایمن به‌روزرسانی معتبر نیست.');
    }
}
