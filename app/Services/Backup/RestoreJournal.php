<?php

namespace App\Services\Backup;

use JsonException;
use Throwable;

final class RestoreJournal
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'uploaded' => ['verified'],
        'verified' => ['confirmed'],
        'confirmed' => ['database_restoring'],
        'database_restoring' => ['database_committed', 'recovery_required'],
        'database_committed' => ['files_restoring', 'recovery_required'],
        'files_restoring' => ['key_rotating', 'recovery_required'],
        'key_rotating' => ['finalizing', 'recovery_required'],
        'finalizing' => ['complete', 'recovery_required'],
    ];

    public function __construct(private readonly ?string $journalPath = null) {}

    /** @return array<string, mixed>|null */
    public function state(): ?array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw)) {
            throw $this->invalidState();
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BackupException(
                'restore_invalid_state',
                'وضعیت بازیابی معتبر نیست و ادامه عملیات متوقف شد.',
                $exception,
            );
        }

        if (! is_array($decoded) || ! is_string($decoded['phase'] ?? null)) {
            throw $this->invalidState();
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    public function startUpload(string $uploadId): array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $uploadId) !== 1 || $this->state() !== null) {
            throw $this->invalidState();
        }

        $state = [
            'phase' => 'uploaded',
            'upload_id' => $uploadId,
        ];
        $this->write($state);

        return $state;
    }

    /** @return array<string, mixed> */
    public function markVerified(RestorePreview $preview): array
    {
        $state = $this->state();
        if (
            $state === null
            || ($state['phase'] ?? null) !== 'uploaded'
            || ($state['upload_id'] ?? null) !== $preview->uploadId()
        ) {
            throw $this->invalidState();
        }

        return $this->transition('uploaded', 'verified', [
            'preview' => $preview->toArray(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    public function transition(string $expectedPhase, string $nextPhase, array $evidence = []): array
    {
        $state = $this->state();
        if (
            $state === null
            || ($state['phase'] ?? null) !== $expectedPhase
            || ! in_array($nextPhase, self::TRANSITIONS[$expectedPhase] ?? [], true)
        ) {
            throw $this->invalidState();
        }

        $this->assertNonSecretEvidence($evidence);

        $next = [
            ...$state,
            ...$evidence,
            'phase' => $nextPhase,
        ];
        $this->write($next);

        return $next;
    }

    public function clear(): void
    {
        $path = $this->path();
        @unlink($path.'.tmp');
        @unlink($path);
    }

    /** @param array<string, mixed> $state */
    private function write(array $state): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw $this->invalidState();
        }
        @chmod($directory, 0700);

        try {
            $json = json_encode(
                $state,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new BackupException(
                'restore_invalid_state',
                'وضعیت بازیابی معتبر نیست و ادامه عملیات متوقف شد.',
                $exception,
            );
        }

        $temporary = $path.'.tmp';
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) === false) {
                throw $this->invalidState();
            }
            if (! @chmod($temporary, 0600) || ! @rename($temporary, $path) || ! @chmod($path, 0600)) {
                throw $this->invalidState();
            }
        } catch (Throwable $exception) {
            @unlink($temporary);

            if ($exception instanceof BackupException) {
                throw $exception;
            }

            throw new BackupException(
                'restore_invalid_state',
                'وضعیت بازیابی معتبر نیست و ادامه عملیات متوقف شد.',
                $exception,
            );
        }
    }

    /** @param array<string, mixed> $evidence */
    private function assertNonSecretEvidence(array $evidence): void
    {
        foreach ($evidence as $key => $value) {
            $normalized = strtolower((string) $key);
            if (
                str_contains($normalized, 'app_key')
                || str_contains($normalized, 'password')
                || str_contains($normalized, 'credential')
                || str_contains($normalized, 'secret')
                || str_contains($normalized, 'bundle_payload')
            ) {
                throw $this->invalidState();
            }

            if (is_array($value)) {
                $this->assertNonSecretEvidence($value);
            } elseif (is_object($value) || is_resource($value)) {
                throw $this->invalidState();
            }
        }
    }

    private function path(): string
    {
        return $this->journalPath
            ?? (string) config('backup.restore.journal_path', storage_path('app/lifecycle/restore/state.json'));
    }

    private function invalidState(): BackupException
    {
        return new BackupException(
            'restore_invalid_state',
            'وضعیت بازیابی معتبر نیست و ادامه عملیات متوقف شد.',
        );
    }
}
