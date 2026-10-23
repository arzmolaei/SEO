<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

class InstallationState
{
    /** @var list<string> */
    private const INITIAL_METADATA = [
        'installer_schema_version',
        'product_version',
        'installation_id',
        'database_fingerprint',
        'owner_user_id',
        'restored_from_installation_id',
        'restored_at',
    ];

    /** @var list<string> */
    private const MUTABLE_METADATA = [
        'installer_schema_version',
        'product_version',
        'owner_user_id',
        'restored_from_installation_id',
        'restored_at',
    ];

    /** @var list<string> */
    private const RECOVERY_REQUIRED_METADATA = [
        'installed_at',
        'version',
        'installer_schema_version',
        'product_version',
        'installation_id',
        'database_fingerprint',
        'owner_user_id',
    ];

    private string $lockPath;

    private bool $assumeInstalled;

    public function __construct(?string $lockPath = null, ?bool $assumeInstalled = null)
    {
        $this->lockPath = $lockPath ?? (string) config('installer.lock_path');
        $this->assumeInstalled = $assumeInstalled ?? (bool) config('installer.assume_installed', false);
    }

    public function isInstalled(): bool
    {
        return $this->assumeInstalled || is_file($this->lockPath);
    }

    /** @return array<string, mixed>|null */
    public function details(): ?array
    {
        if (! is_file($this->lockPath)) {
            return null;
        }

        $contents = file_get_contents($this->lockPath);

        if ($contents === false) {
            throw new RuntimeException('Unable to read installation lock file.');
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Installation lock file must contain an object.');
        }

        return $decoded;
    }

    /** @param array<string, int|string> $metadata */
    public function markInstalled(array $metadata = []): void
    {
        if ($this->assumeInstalled || is_file($this->lockPath)) {
            return;
        }

        $this->assertAllowedMetadata($metadata, self::INITIAL_METADATA, 'Unsupported installation lock metadata.');

        $payload = [
            'installed_at' => now()->toIso8601String(),
            'version' => 2,
        ];

        foreach (self::INITIAL_METADATA as $key) {
            if (array_key_exists($key, $metadata)) {
                $payload[$key] = $metadata[$key];
            }
        }

        $this->writePayload($payload);
    }

    /**
     * @param  array<string, int|string>  $changes
     * @return array<string, mixed>
     */
    public function updateMetadata(array $changes): array
    {
        $this->assertAllowedMetadata(
            $changes,
            self::MUTABLE_METADATA,
            'Unsupported installation lock metadata update.',
        );

        $payload = $this->details();

        if ($payload === null) {
            throw new RuntimeException('Installation lock metadata is unavailable.');
        }

        foreach ($changes as $key => $value) {
            $payload[$key] = $value;
        }

        $this->writePayload($payload);

        return $payload;
    }

    /** @param  array<string, mixed>  $snapshot */
    public function restoreRecoverySnapshot(array $snapshot): void
    {
        if ($this->assumeInstalled) {
            throw new RuntimeException('Recovery cannot rewrite an assumed installation state.');
        }

        $allowed = array_merge(['installed_at', 'version'], self::INITIAL_METADATA);
        $this->assertAllowedMetadata(
            $snapshot,
            $allowed,
            'Unsupported installation recovery snapshot metadata.',
        );

        foreach (self::RECOVERY_REQUIRED_METADATA as $key) {
            if (! array_key_exists($key, $snapshot)) {
                throw new InvalidArgumentException('Installation recovery snapshot is incomplete.');
            }
        }

        if (
            ! is_string($snapshot['installed_at'])
            || trim($snapshot['installed_at']) === ''
            || $snapshot['version'] !== 2
            || ! is_int($snapshot['installer_schema_version'])
            || $snapshot['installer_schema_version'] < 1
            || ! is_string($snapshot['product_version'])
            || trim($snapshot['product_version']) === ''
            || ! is_string($snapshot['installation_id'])
            || preg_match('/\A[a-f0-9]{32}\z/', $snapshot['installation_id']) !== 1
            || ! is_string($snapshot['database_fingerprint'])
            || preg_match('/\A[a-f0-9]{64}\z/', $snapshot['database_fingerprint']) !== 1
            || ! is_int($snapshot['owner_user_id'])
            || $snapshot['owner_user_id'] < 1
        ) {
            throw new InvalidArgumentException('Installation recovery snapshot is invalid.');
        }

        foreach (['restored_from_installation_id', 'restored_at'] as $optional) {
            if (! array_key_exists($optional, $snapshot)) {
                continue;
            }

            if (! is_string($snapshot[$optional]) || trim($snapshot[$optional]) === '') {
                throw new InvalidArgumentException('Installation recovery snapshot is invalid.');
            }
        }

        if (
            array_key_exists('restored_from_installation_id', $snapshot)
            && preg_match('/\A[a-f0-9]{32}\z/', $snapshot['restored_from_installation_id']) !== 1
        ) {
            throw new InvalidArgumentException('Installation recovery snapshot is invalid.');
        }

        $this->writePayload($snapshot);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $allowedMetadata
     */
    private function assertAllowedMetadata(array $metadata, array $allowedMetadata, string $message): void
    {
        foreach (array_keys($metadata) as $key) {
            if (! in_array($key, $allowedMetadata, true)) {
                throw new InvalidArgumentException($message);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function writePayload(array $payload): void
    {
        $directory = dirname($this->lockPath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create installation state directory.');
        }

        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
        $temporaryPath = $this->lockPath.'.tmp';

        try {
            if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write installation lock temporary file.');
            }

            @chmod($temporaryPath, 0600);

            if (! rename($temporaryPath, $this->lockPath)) {
                throw new RuntimeException('Unable to write installation lock file atomically.');
            }

            @chmod($this->lockPath, 0600);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}
