<?php

namespace App\Support;

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

    public function markInstalled(): void
    {
        if ($this->assumeInstalled || is_file($this->lockPath)) {
            return;
        }

        $directory = dirname($this->lockPath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create installation state directory.');
        }

        $payload = json_encode([
            'installed_at' => now()->toIso8601String(),
            'version' => 1,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($this->lockPath, $payload.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write installation lock file.');
        }
    }
}
