<?php

namespace App\Support\Installer;

use Closure;
use InvalidArgumentException;
use RuntimeException;

class EnvironmentWriter
{
    private readonly string $environmentPath;

    private readonly string $examplePath;

    /** @var Closure(string, string): bool */
    private readonly Closure $replaceFile;

    /**
     * @param  (Closure(string, string): bool)|null  $replaceFile
     */
    public function __construct(
        ?string $environmentPath = null,
        ?string $examplePath = null,
        ?Closure $replaceFile = null,
    ) {
        $this->environmentPath = $environmentPath ?? base_path('.env');
        $this->examplePath = $examplePath ?? base_path('.env.example');
        $this->replaceFile = $replaceFile ?? static fn (string $temporaryPath, string $destinationPath): bool => rename($temporaryPath, $destinationPath);
    }

    /** @param array<string, int|string> $values */
    public function persist(array $values, ?string $databasePassword, bool $keepExistingPassword): void
    {
        $normalized = $this->validate($values);
        $existing = (new InstallerEnvironment($this->environmentPath))->values();

        if ($keepExistingPassword) {
            if (! array_key_exists('DB_PASSWORD', $existing)) {
                throw new InvalidArgumentException('There is no existing database password to keep.');
            }

            $databasePassword = $existing['DB_PASSWORD'];
        }

        $databasePassword ??= '';

        if ($this->containsControlCharacters($databasePassword)) {
            throw new InvalidArgumentException('Database password contains unsupported control characters.');
        }

        $key = trim((string) config('app.key', ''));

        if ($key === '' || $key === '${APP_KEY}') {
            throw new RuntimeException('Application key must be configured before persisting the environment.');
        }

        $managed = [
            'APP_ENV' => 'production',
            'APP_KEY' => $key,
            'APP_DEBUG' => false,
            'APP_URL' => $normalized['app_url'],
            'DB_CONNECTION' => $normalized['database_driver'],
            'DB_HOST' => $normalized['database_host'],
            'DB_PORT' => $normalized['database_port'],
            'DB_DATABASE' => $normalized['database_name'],
            'DB_USERNAME' => $normalized['database_username'],
            'DB_PASSWORD' => $databasePassword,
            'SESSION_DRIVER' => 'database',
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'SELF_HOST_REGISTRATION' => false,
        ];

        $sourcePath = is_file($this->environmentPath) ? $this->environmentPath : $this->examplePath;

        if (! is_file($sourcePath)) {
            throw new RuntimeException('Application environment template is missing.');
        }

        $source = file_get_contents($sourcePath);

        if ($source === false) {
            throw new RuntimeException('Unable to read application environment source.');
        }

        $contents = $this->patch($source, $managed);
        $directory = dirname($this->environmentPath);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create application environment directory.');
        }

        $temporaryPath = $this->environmentPath.'.tmp';

        try {
            $handle = fopen($temporaryPath, 'wb');

            if ($handle === false) {
                throw new RuntimeException('Unable to open temporary application environment file.');
            }

            try {
                if (fwrite($handle, $contents) === false || ! fflush($handle)) {
                    throw new RuntimeException('Unable to write temporary application environment file.');
                }
            } finally {
                fclose($handle);
            }

            @chmod($temporaryPath, 0600);

            if (! ($this->replaceFile)($temporaryPath, $this->environmentPath)) {
                throw new RuntimeException('Unable to replace application environment atomically.');
            }

            @chmod($this->environmentPath, 0600);
            $this->applyRuntimeConfig($normalized, $databasePassword, $key);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @param  array<string, int|string>  $values
     * @return array{app_url: string, database_driver: string, database_host: string, database_port: int, database_name: string, database_username: string}
     */
    private function validate(array $values): array
    {
        $appUrl = trim((string) ($values['app_url'] ?? ''));
        $driver = trim((string) ($values['database_driver'] ?? ''));
        $host = trim((string) ($values['database_host'] ?? ''));
        $port = filter_var($values['database_port'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        $database = trim((string) ($values['database_name'] ?? ''));
        $username = trim((string) ($values['database_username'] ?? ''));
        $scheme = parse_url($appUrl, PHP_URL_SCHEME);

        if (! filter_var($appUrl, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true) || strlen($appUrl) > 2048) {
            throw new InvalidArgumentException('Application URL must be a valid HTTP or HTTPS URL.');
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException('Only MySQL and MariaDB are supported by the self-host installer.');
        }

        foreach ([
            'database host' => [$host, 255],
            'database name' => [$database, 128],
            'database username' => [$username, 128],
        ] as $label => [$value, $maximumLength]) {
            if ($value === '' || strlen($value) > $maximumLength || $this->containsControlCharacters($value)) {
                throw new InvalidArgumentException(ucfirst($label).' is invalid.');
            }
        }

        if ($port === false) {
            throw new InvalidArgumentException('Database port must be between 1 and 65535.');
        }

        return [
            'app_url' => rtrim($appUrl, '/'),
            'database_driver' => $driver,
            'database_host' => $host,
            'database_port' => $port,
            'database_name' => $database,
            'database_username' => $username,
        ];
    }

    /** @param array<string, bool|int|string> $managed */
    private function patch(string $source, array $managed): string
    {
        $lines = preg_split('/\R/', $source) ?: [];
        $written = [];

        foreach ($lines as &$line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=/', $line, $matches) !== 1) {
                continue;
            }

            $key = $matches[1];

            if (! array_key_exists($key, $managed)) {
                continue;
            }

            $line = $key.'='.$this->encodeValue($managed[$key]);
            $written[$key] = true;
        }
        unset($line);

        foreach ($managed as $key => $value) {
            if (! isset($written[$key])) {
                $lines[] = $key.'='.$this->encodeValue($value);
            }
        }

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function encodeValue(bool|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (in_array($value, ['production', 'database', 'mysql', 'mariadb'], true)) {
            return $value;
        }

        return '"'.strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
        ]).'"';
    }

    private function containsControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    /** @param array{app_url: string, database_driver: string, database_host: string, database_port: int, database_name: string, database_username: string} $values */
    private function applyRuntimeConfig(array $values, string $databasePassword, string $key): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => $values['app_url'],
            'app.key' => $key,
            'database.default' => $values['database_driver'],
            "database.connections.{$values['database_driver']}.host" => $values['database_host'],
            "database.connections.{$values['database_driver']}.port" => $values['database_port'],
            "database.connections.{$values['database_driver']}.database" => $values['database_name'],
            "database.connections.{$values['database_driver']}.username" => $values['database_username'],
            "database.connections.{$values['database_driver']}.password" => $databasePassword,
        ]);
    }
}
