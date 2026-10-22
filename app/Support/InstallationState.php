<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

class InstallationState
{
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

    /** @return array<string, mixed> */
    public function details(): array
    {
        if (! is_file($this->lockPath)) {
            return [];
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

        $allowedMetadata = ['installer_schema_version', 'product_version', 'installation_id'];

        foreach (array_keys($metadata) as $key) {
            if (! in_array($key, $allowedMetadata, true)) {
                throw new InvalidArgumentException('Unsupported installation lock metadata.');
            }
        }

        $directory = dirname($this->lockPath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create installation state directory.');
        }

        $payload = [
            'installed_at' => now()->toIso8601String(),
            'version' => 2,
        ];

        foreach ($allowedMetadata as $key) {
            if (array_key_exists($key, $metadata)) {
                $payload[$key] = $metadata[$key];
            }
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
