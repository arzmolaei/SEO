<?php

namespace App\Support\Installer;

use Illuminate\Database\Migrations\Migrator;

class InstallerMigrationRunner
{
    private readonly string $connection;

    private readonly string $migrationPath;

    public function __construct(
        private readonly Migrator $migrator,
        ?string $connection = null,
        ?string $migrationPath = null,
    ) {
        $this->connection = $connection ?? (string) config('database.default');
        $this->migrationPath = $migrationPath ?? database_path('migrations');
    }

    /** @return array{pending: list<string>, complete: bool} */
    public function status(): array
    {
        return $this->migrator->usingConnection($this->connection, function (): array {
            $migrationNames = array_keys($this->migrator->getMigrationFiles($this->migrationPath));

            if (! $this->migrator->repositoryExists()) {
                return [
                    'pending' => $migrationNames,
                    'complete' => $migrationNames === [],
                ];
            }

            $ran = $this->migrator->getRepository()->getRan();
            $pending = array_values(array_diff($migrationNames, $ran));

            return [
                'pending' => $pending,
                'complete' => $pending === [],
            ];
        });
    }

    /** @return array{ran: list<string>, remaining: list<string>, complete: bool} */
    public function runPending(): array
    {
        return $this->migrator->usingConnection($this->connection, function (): array {
            $migrationNames = array_keys($this->migrator->getMigrationFiles($this->migrationPath));

            if (! $this->migrator->repositoryExists()) {
                $this->migrator->getRepository()->createRepository();
                $ranBefore = [];
            } else {
                $ranBefore = $this->migrator->getRepository()->getRan();
            }

            $pendingBefore = array_values(array_diff($migrationNames, $ranBefore));

            if ($pendingBefore === []) {
                return [
                    'ran' => [],
                    'remaining' => [],
                    'complete' => true,
                ];
            }

            $ranPaths = $this->migrator->run($this->migrationPath, [
                'pretend' => false,
                'step' => false,
            ]);
            $ranNow = array_values(array_map(
                static fn (string $path): string => basename($path, '.php'),
                $ranPaths,
            ));
            $ranAfter = $this->migrator->getRepository()->getRan();
            $remaining = array_values(array_diff($migrationNames, $ranAfter));

            return [
                'ran' => $ranNow,
                'remaining' => $remaining,
                'complete' => $remaining === [],
            ];
        });
    }
}
