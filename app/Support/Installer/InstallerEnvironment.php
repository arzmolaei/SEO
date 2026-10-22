<?php

namespace App\Support\Installer;

use RuntimeException;

class InstallerEnvironment
{
    private readonly string $environmentPath;

    public function __construct(?string $environmentPath = null)
    {
        $this->environmentPath = $environmentPath ?? base_path('.env');
    }

    /**
     * @return array{
     *   app_url: string,
     *   database_driver: string,
     *   database_host: string,
     *   database_port: int,
     *   database_name: string,
     *   database_username: string,
     *   has_database_password: bool
     * }
     */
    public function publicValues(): array
    {
        $values = $this->values();

        return [
            'app_url' => $values['APP_URL'] ?? '',
            'database_driver' => $values['DB_CONNECTION'] ?? '',
            'database_host' => $values['DB_HOST'] ?? '',
            'database_port' => (int) ($values['DB_PORT'] ?? 0),
            'database_name' => $values['DB_DATABASE'] ?? '',
            'database_username' => $values['DB_USERNAME'] ?? '',
            'has_database_password' => array_key_exists('DB_PASSWORD', $values)
                && $values['DB_PASSWORD'] !== '',
        ];
    }

    /** @return array<string, string> */
    public function values(): array
    {
        if (! is_file($this->environmentPath)) {
            return [];
        }

        $contents = file_get_contents($this->environmentPath);

        if ($contents === false) {
            throw new RuntimeException('Unable to read application environment file.');
        }

        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $values[$matches[1]] = $this->decodeValue($matches[2]);
        }

        return $values;
    }

    private function decodeValue(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            $value = substr($value, 1, -1);

            return strtr($value, [
                '\\"' => '"',
                '\\$' => '$',
                '\\\\' => '\\',
            ]);
        }

        if (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
