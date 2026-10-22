<?php

namespace App\Support\Installer;

use Closure;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Throwable;

class DatabaseVerifier
{
    /** @var Closure(array<string, mixed>): mixed */
    private readonly Closure $probe;

    /** @var Closure(string): bool */
    private readonly Closure $extensionLoaded;

    /**
     * @param  (Closure(array<string, mixed>): mixed)|null  $probe
     * @param  (Closure(string): bool)|null  $extensionLoaded
     */
    public function __construct(?Closure $probe = null, ?Closure $extensionLoaded = null)
    {
        $this->probe = $probe ?? fn (array $connection): mixed => $this->nativeProbe($connection);
        $this->extensionLoaded = $extensionLoaded ?? static fn (string $extension): bool => extension_loaded($extension);
    }

    /**
     * @param  array{driver: string, host: string, port: int, database: string, username: string, password: string}  $connection
     */
    public function verify(array $connection): DatabaseVerificationResult
    {
        $facts = $this->facts($connection);
        $driver = $facts['driver'];

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return DatabaseVerificationResult::failure(
                'unsupported_driver',
                'Only MySQL and MariaDB are supported by this installer.',
                $facts,
            );
        }

        if (! ($this->extensionLoaded)('pdo_mysql')) {
            return DatabaseVerificationResult::failure(
                'missing_driver',
                'The pdo_mysql PHP extension is required for this database.',
                $facts,
            );
        }

        try {
            ($this->probe)($connection);
        } catch (Throwable $exception) {
            [$code, $message] = $this->classify($exception);

            return DatabaseVerificationResult::failure($code, $message, $facts);
        }

        $fingerprint = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return DatabaseVerificationResult::success($fingerprint, $facts);
    }

    /**
     * @param  array{driver: string, host: string, port: int, database: string, username: string, password: string}  $connection
     * @return array{driver: string, host: string, port: int, database: string}
     */
    private function facts(array $connection): array
    {
        return [
            'driver' => $connection['driver'],
            'host' => $connection['host'],
            'port' => $connection['port'],
            'database' => $connection['database'],
        ];
    }

    /** @return array{0: string, 1: string} */
    private function classify(Throwable $exception): array
    {
        if (! $exception instanceof PDOException) {
            return ['unknown', 'Database verification failed unexpectedly.'];
        }

        $sqlState = is_array($exception->errorInfo) && isset($exception->errorInfo[0])
            ? (string) $exception->errorInfo[0]
            : '';
        $driverCode = is_array($exception->errorInfo) && isset($exception->errorInfo[1])
            ? (int) $exception->errorInfo[1]
            : 0;

        if ($driverCode === 1045 || $sqlState === '28000') {
            return ['authentication_failed', 'Database authentication failed. Check the username and password.'];
        }

        if ($driverCode === 1049) {
            return ['database_missing', 'The configured database does not exist or cannot be selected.'];
        }

        if (in_array($driverCode, [1044, 1142, 1227], true)) {
            return ['insufficient_privileges', 'The database user does not have the privileges required by the installer.'];
        }

        if (in_array($driverCode, [2002, 2003, 2005, 2006], true)) {
            return ['unreachable', 'The database server could not be reached. Check host and port settings.'];
        }

        return ['unknown', 'Database verification failed unexpectedly.'];
    }

    /** @param array<string, mixed> $candidate */
    private function nativeProbe(array $candidate): void
    {
        $driver = (string) ($candidate['driver'] ?? '');
        $base = config("database.connections.{$driver}");

        if (! is_array($base)) {
            throw new RuntimeException('Database driver configuration is unavailable.');
        }

        $name = 'installer_probe';
        $previous = config("database.connections.{$name}");
        $configuration = array_replace($base, [
            'driver' => $driver,
            'url' => null,
            'host' => (string) ($candidate['host'] ?? ''),
            'port' => (int) ($candidate['port'] ?? 0),
            'database' => (string) ($candidate['database'] ?? ''),
            'username' => (string) ($candidate['username'] ?? ''),
            'password' => (string) ($candidate['password'] ?? ''),
        ]);

        config(["database.connections.{$name}" => $configuration]);
        DB::purge($name);

        try {
            $database = DB::connection($name);
            $database->getPdo();

            $table = 'seo_os_installer_probe_'.bin2hex(random_bytes(6));
            $quotedTable = '`'.$table.'`';
            $created = false;

            try {
                $database->statement("CREATE TEMPORARY TABLE {$quotedTable} (id INT PRIMARY KEY, value VARCHAR(32) NOT NULL)");
                $created = true;
                $database->insert("INSERT INTO {$quotedTable} (id, value) VALUES (?, ?)", [1, 'verified']);
                $value = $database->scalar("SELECT value FROM {$quotedTable} WHERE id = ?", [1]);

                if ($value !== 'verified') {
                    throw new RuntimeException('Database capability probe returned an unexpected value.');
                }
            } finally {
                if ($created) {
                    $database->statement("DROP TEMPORARY TABLE IF EXISTS {$quotedTable}");
                }
            }
        } finally {
            DB::disconnect($name);
            DB::purge($name);
            config(["database.connections.{$name}" => $previous]);
        }
    }
}
