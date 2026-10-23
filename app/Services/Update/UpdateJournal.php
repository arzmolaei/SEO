<?php

namespace App\Services\Update;

use JsonException;

final class UpdateJournal
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'uploaded' => ['verified'],
        'verified' => ['preparing'],
        'preparing' => ['quiescing'],
        'quiescing' => ['snapshot_ready'],
        'snapshot_ready' => ['staged'],
        'staged' => ['switch_pending'],
        'switch_pending' => ['target_active', 'recovery_required'],
        'target_active' => ['migrating', 'rollback_required', 'recovery_required'],
        'migrating' => ['validating', 'rollback_required', 'recovery_required'],
        'validating' => ['complete', 'rollback_required', 'recovery_required'],
        'rollback_required' => ['migration_rollback', 'source_switch_pending', 'recovery_required'],
        'migration_rollback' => ['source_switch_pending', 'recovery_required'],
        'source_switch_pending' => ['snapshot_restore_required', 'recovery_required'],
        'snapshot_restore_required' => ['complete', 'recovery_required'],
        'complete' => [],
        'recovery_required' => [],
    ];

    private const MAINTENANCE_PHASES = [
        'preparing', 'quiescing', 'snapshot_ready', 'staged', 'switch_pending',
        'target_active', 'migrating', 'validating', 'rollback_required',
        'migration_rollback', 'source_switch_pending', 'snapshot_restore_required',
        'recovery_required',
    ];

    public function __construct(private readonly ?string $path = null) {}

    /** @return array<string, mixed>|null */
    public function state(): ?array
    {
        $path = $this->resolvedPath();
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }
        if (is_link($path) || ! is_file($path)) {
            throw $this->invalidState();
        }
        $raw = file_get_contents($path);
        if (! is_string($raw) || strlen($raw) > 65536) {
            throw $this->invalidState();
        }
        try {
            $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalidState();
        }
        if (! is_array($state) || ! isset($state['phase']) || ! is_string($state['phase'])) {
            throw $this->invalidState();
        }
        return $state;
    }

    /** @return array<string, mixed> */
    public function startUpload(string $uploadId): array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $uploadId) !== 1 || $this->state() !== null) {
            throw $this->invalidState();
        }
        $state = ['phase' => 'uploaded', 'upload_id' => $uploadId];
        $this->write($state);
        return $state;
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    public function transition(string $expectedPhase, string $nextPhase, array $evidence = []): array
    {
        $state = $this->state();
        if ($state === null || $state['phase'] !== $expectedPhase || ! in_array($nextPhase, self::TRANSITIONS[$expectedPhase] ?? [], true)) {
            throw $this->invalidState();
        }
        $this->assertSafeEvidence($evidence);
        $next = array_merge($state, $evidence, ['phase' => $nextPhase]);
        $this->write($next);
        return $next;
    }

    public function clear(): void
    {
        @unlink($this->resolvedPath());
        @unlink($this->resolvedPath().'.tmp');
    }

    /** @return list<string> */
    public static function maintenancePhases(): array
    {
        return self::MAINTENANCE_PHASES;
    }

    public static function isMaintenancePhase(?string $phase): bool
    {
        return $phase !== null && in_array($phase, self::MAINTENANCE_PHASES, true);
    }

    private function resolvedPath(): string
    {
        return $this->path ?? storage_path('app/lifecycle/update/state.json');
    }

    /** @param array<string, mixed> $state */
    private function write(array $state): void
    {
        $path = $this->resolvedPath();
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw $this->invalidState();
        }
        @chmod($directory, 0700);
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw $this->invalidState();
        }
        @chmod($tmp, 0600);
        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw $this->invalidState();
        }
        @chmod($path, 0600);
    }

    /** @param array<string, mixed> $value */
    private function assertSafeEvidence(array $value): void
    {
        $walk = function (mixed $item, ?string $key = null) use (&$walk): void {
            if ($key !== null && preg_match('/(?:secret|password|credential|app_key|payload|absolute_path)/i', $key) === 1) {
                throw $this->invalidState();
            }
            if (is_object($item) || is_resource($item)) {
                throw $this->invalidState();
            }
            if (is_array($item)) {
                foreach ($item as $childKey => $child) {
                    $walk($child, is_string($childKey) ? $childKey : null);
                }
            }
        };
        $walk($value);
    }

    private function invalidState(): UpdateException
    {
        return new UpdateException('update_invalid_state', 'وضعیت به‌روزرسانی معتبر نیست.');
    }
}
