<?php

namespace App\Support\Installer;

use PDOException;
use RuntimeException;
use Throwable;

/**
 * A migration failed during installation. The reason is safe to show to the
 * owner: migration filename, exception class and SQLSTATE/driver code only —
 * never the message, SQL, bindings, DSN, credentials or paths.
 */
final class InstallerMigrationFailed extends RuntimeException
{
    public function __construct(public readonly string $migration, Throwable $previous)
    {
        parent::__construct("Installer migration {$migration} failed.", 0, $previous);
    }

    public function reason(): string
    {
        $previous = $this->getPrevious();
        $parts = [$this->migration, $previous === null ? 'unknown' : $previous::class];
        $errorInfo = $this->errorInfo($previous);

        if (is_string($errorInfo[0] ?? null) && preg_match('/\A[0-9A-Z]{5}\z/', $errorInfo[0]) === 1) {
            $parts[] = 'SQLSTATE '.$errorInfo[0];
        }

        if (is_int($errorInfo[1] ?? null)) {
            $parts[] = 'code '.$errorInfo[1];
        }

        return implode(' — ', $parts);
    }

    /** @return array<int, mixed> */
    private function errorInfo(?Throwable $exception): array
    {
        while ($exception !== null) {
            if ($exception instanceof PDOException && is_array($exception->errorInfo)) {
                return $exception->errorInfo;
            }

            $exception = $exception->getPrevious();
        }

        return [];
    }
}
