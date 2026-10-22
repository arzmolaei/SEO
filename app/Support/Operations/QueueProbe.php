<?php

namespace App\Support\Operations;

use App\Jobs\RecordQueueProbe;
use Closure;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

class QueueProbe
{
    private string $directory;

    /** @var Closure(string): void */
    private Closure $dispatcher;

    public function __construct(?string $directory = null, ?Closure $dispatcher = null, private readonly int $expiresAfterSeconds = 300)
    {
        $this->directory = $directory ?? (string) config('installer.queue_probe_path', storage_path('app/operations/queue-probes'));
        $this->dispatcher = $dispatcher ?? static function (string $id): void {
            $connection = (string) config('installer.original_queue_connection', 'database');
            Queue::connection($connection)->push(new RecordQueueProbe($id));
        };
    }

    public function dispatch(): string
    {
        $this->ensureDirectory();

        $id = bin2hex(random_bytes(16));
        $record = [
            'id' => $id,
            'dispatched_at' => now()->toIso8601String(),
            'expires_at' => now()->addSeconds($this->expiresAfterSeconds)->toIso8601String(),
            'completed_at' => null,
            'failed_at' => null,
        ];

        $this->writeJson($this->recordPath($id), $record);
        $this->writeJson($this->latestPath(), ['id' => $id]);

        try {
            ($this->dispatcher)($id);
        } catch (Throwable $exception) {
            $record['failed_at'] = now()->toIso8601String();
            $this->writeJson($this->recordPath($id), $record);

            throw new RuntimeException('Queue probe could not be dispatched.', 0, $exception);
        }

        return $id;
    }

    public function latestId(): ?string
    {
        if (! $this->storageUsable() || ! is_file($this->latestPath())) {
            return null;
        }

        $payload = $this->readJson($this->latestPath());
        $id = $payload['id'] ?? null;

        return is_string($id) && $this->validId($id) ? $id : null;
    }

    /** @return array{state: 'verified'|'warning'|'fail', completed_at: ?string} */
    public function status(string $id): array
    {
        if (! $this->storageUsable() || ! $this->validId($id)) {
            return ['state' => 'fail', 'completed_at' => null];
        }

        $path = $this->recordPath($id);
        if (! is_file($path)) {
            return ['state' => 'warning', 'completed_at' => null];
        }

        $record = $this->readJson($path);
        if ($record === [] || is_string($record['failed_at'] ?? null)) {
            return ['state' => 'fail', 'completed_at' => null];
        }

        $expiresAt = $record['expires_at'] ?? null;
        if (! is_string($expiresAt)) {
            return ['state' => 'fail', 'completed_at' => null];
        }

        $expiresTimestamp = strtotime($expiresAt);
        if ($expiresTimestamp === false) {
            return ['state' => 'fail', 'completed_at' => null];
        }

        $completedAt = $record['completed_at'] ?? null;
        if (now()->getTimestamp() > $expiresTimestamp) {
            return ['state' => 'warning', 'completed_at' => is_string($completedAt) ? $completedAt : null];
        }

        if (is_string($completedAt) && $completedAt !== '') {
            return ['state' => 'verified', 'completed_at' => $completedAt];
        }

        return ['state' => 'warning', 'completed_at' => null];
    }

    public function complete(string $id): void
    {
        if (! $this->validId($id)) {
            return;
        }

        $path = $this->recordPath($id);
        if (! is_file($path)) {
            return;
        }

        $record = $this->readJson($path);
        if ($record === [] || is_string($record['failed_at'] ?? null)) {
            return;
        }

        $record['completed_at'] = now()->toIso8601String();
        $this->writeJson($path, $record);
    }

    /** @return 'warning'|'fail' */
    public function storageState(): string
    {
        return $this->storageUsable() ? 'warning' : 'fail';
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Queue probe directory is not writable.');
        }

        if (! is_writable($this->directory)) {
            throw new RuntimeException('Queue probe directory is not writable.');
        }
    }

    private function storageUsable(): bool
    {
        if (file_exists($this->directory)) {
            return is_dir($this->directory) && is_writable($this->directory);
        }

        $candidate = dirname($this->directory);
        while (! file_exists($candidate)) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return false;
            }

            $candidate = $parent;
        }

        return is_dir($candidate) && is_writable($candidate);
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        $this->ensureDirectory();

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));

        try {
            if (@file_put_contents($temporary, $encoded, LOCK_EX) === false) {
                throw new RuntimeException('Queue probe state could not be written.');
            }

            @chmod($temporary, 0600);

            if (! @rename($temporary, $path)) {
                throw new RuntimeException('Queue probe state could not be committed.');
            }

            @chmod($path, 0600);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = @file_get_contents($path);
        if (! is_string($contents)) {
            return [];
        }

        try {
            $payload = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    private function recordPath(string $id): string
    {
        return $this->directory.'/'.$id.'.json';
    }

    private function latestPath(): string
    {
        return $this->directory.'/latest.json';
    }

    private function validId(string $id): bool
    {
        return preg_match('/\A[a-f0-9]{32}\z/', $id) === 1;
    }
}
