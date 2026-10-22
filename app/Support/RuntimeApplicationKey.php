<?php

namespace App\Support;

use RuntimeException;

class RuntimeApplicationKey
{
    public function ensureConfigured(): void
    {
        if (trim((string) config('app.key', '')) !== '') {
            return;
        }

        $path = $this->path();
        $key = is_file($path) ? $this->readFallback($path) : '';

        if ($key === '') {
            $directory = dirname($path);

            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create installer key directory.');
            }

            $key = 'base64:'.base64_encode(random_bytes(32));

            if (file_put_contents($path, $key.PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('Unable to persist installer application key.');
            }

            @chmod($path, 0600);
        }

        config(['app.key' => $key]);
    }

    public function path(): string
    {
        return (string) config(
            'installer.runtime_key_path',
            storage_path('app/installer/runtime-app-key'),
        );
    }

    public function persistentKeyIsCompatible(string $persistentKey): bool
    {
        $persistentKey = trim($persistentKey);
        if ($persistentKey === '') {
            return false;
        }

        $path = $this->path();
        if (! is_file($path)) {
            return true;
        }

        $fallback = $this->readFallback($path);

        return $fallback !== '' && hash_equals($fallback, $persistentKey);
    }

    public function discardFallback(string $persistentKey): void
    {
        $path = $this->path();
        if (! is_file($path)) {
            return;
        }

        $fallback = $this->readFallback($path);
        if ($fallback === '' || ! hash_equals($fallback, trim($persistentKey))) {
            throw new RuntimeException('Installer runtime application key does not match the persistent key.');
        }

        if (! @unlink($path)) {
            throw new RuntimeException('Unable to remove installer runtime application key.');
        }
    }

    private function readFallback(string $path): string
    {
        $contents = @file_get_contents($path);
        if (! is_string($contents)) {
            return '';
        }

        return trim($contents);
    }
}