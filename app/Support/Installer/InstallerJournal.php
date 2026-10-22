<?php

namespace App\Support\Installer;

use InvalidArgumentException;
use RuntimeException;

class InstallerJournal
{
    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (string) config('installer.journal_path');
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read installer journal.');
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('Installer journal must contain an object.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $state */
    public function replace(array $state): void
    {
        $this->assertContainsNoSecrets($state);

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create installer journal directory.');
        }

        $payload = json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
        $temporaryPath = $this->path.'.tmp';

        try {
            if (file_put_contents($temporaryPath, $payload, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write installer journal temporary file.');
            }

            @chmod($temporaryPath, 0600);

            if (! rename($temporaryPath, $this->path)) {
                throw new RuntimeException('Unable to replace installer journal atomically.');
            }

            @chmod($this->path, 0600);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function clear(): void
    {
        if (is_file($this->path) && ! unlink($this->path)) {
            throw new RuntimeException('Unable to clear installer journal.');
        }
    }

    /** @param array<mixed> $value */
    private function assertContainsNoSecrets(array $value): void
    {
        foreach ($value as $key => $nested) {
            if (is_string($key) && preg_match('/(?:password|passwd|secret|token|dsn|credential|api[_-]?key|private[_-]?key)/i', $key) === 1) {
                throw new InvalidArgumentException('Installer journal cannot contain secret-bearing fields.');
            }

            if (is_array($nested)) {
                $this->assertContainsNoSecrets($nested);

                continue;
            }

            if (is_string($nested) && $this->looksLikeCredentialBearingDsn($nested)) {
                throw new InvalidArgumentException('Installer journal cannot contain raw credential-bearing DSNs.');
            }
        }
    }

    private function looksLikeCredentialBearingDsn(string $value): bool
    {
        return preg_match('~(?:[a-z][a-z0-9+.-]*://[^\s/:@]+:[^\s@]+@|password\s*=)~i', $value) === 1;
    }
}
