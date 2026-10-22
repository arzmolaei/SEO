<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

class SchedulerHeartbeat
{
    private string $path;

    public function __construct(?string $path = null, private readonly int $freshForSeconds = 180)
    {
        $this->path = $path ?? (string) config('installer.scheduler_heartbeat_path', storage_path('app/operations/scheduler-heartbeat.json'));
    }

    /** @return array{state: 'verified'|'warning'|'fail', observed_at: ?string} */
    public function status(): array
    {
        if (! $this->storageUsable()) {
            return ['state' => 'fail', 'observed_at' => null];
        }

        if (! is_file($this->path)) {
            return ['state' => 'warning', 'observed_at' => null];
        }

        $contents = @file_get_contents($this->path);
        if (! is_string($contents)) {
            return ['state' => 'fail', 'observed_at' => null];
        }

        try {
            $payload = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
            $observedAt = is_array($payload) ? ($payload['observed_at'] ?? null) : null;
            if (! is_string($observedAt) || $observedAt === '') {
                return ['state' => 'fail', 'observed_at' => null];
            }

            $observed = CarbonImmutable::parse($observedAt);
        } catch (Throwable) {
            return ['state' => 'fail', 'observed_at' => null];
        }

        $age = now()->getTimestamp() - $observed->getTimestamp();
        $state = $age >= 0 && $age <= $this->freshForSeconds ? 'verified' : 'warning';

        return [
            'state' => $state,
            'observed_at' => $observed->toIso8601String(),
        ];
    }

    public function record(): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Scheduler heartbeat directory is not writable.');
        }

        if (! is_writable($directory)) {
            throw new RuntimeException('Scheduler heartbeat directory is not writable.');
        }

        $payload = json_encode([
            'observed_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;

        $temporary = $this->path.'.tmp.'.bin2hex(random_bytes(6));

        try {
            if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
                throw new RuntimeException('Scheduler heartbeat could not be written.');
            }

            @chmod($temporary, 0600);

            if (! @rename($temporary, $this->path)) {
                throw new RuntimeException('Scheduler heartbeat could not be committed.');
            }

            @chmod($this->path, 0600);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function storageUsable(): bool
    {
        if (file_exists($this->path) && ! is_file($this->path)) {
            return false;
        }

        $candidate = dirname($this->path);
        while (! file_exists($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return false;
            }

            $candidate = $parent;
        }

        return is_dir($candidate) && is_writable($candidate);
    }
}
