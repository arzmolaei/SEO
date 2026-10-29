<?php

namespace App\Support\Installer;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

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

    /**
     * Runs pending migrations in a bounded batch so one request stays well
     * inside shared-host PHP time limits; the installer asks again for the rest.
     *
     * @return array{ran: list<string>, remaining: list<string>, complete: bool}
     */
    public function runPending(int $maxMigrations = 50, float $timeBudgetSeconds = 15.0): array
    {
        return $this->migrator->usingConnection($this->connection, function () use ($maxMigrations, $timeBudgetSeconds): array {
            $files = $this->migrator->getMigrationFiles($this->migrationPath);

            if (! $this->migrator->repositoryExists()) {
                $this->migrator->getRepository()->createRepository();
                $ranBefore = [];
            } else {
                $ranBefore = $this->migrator->getRepository()->getRan();
            }

            $pending = array_diff_key($files, array_flip($ranBefore));
            $ranNow = [];
            $started = microtime(true);

            foreach (array_slice($pending, 0, max(1, $maxMigrations), true) as $name => $path) {
                $this->runOne((string) $name, $path);
                $ranNow[] = (string) $name;

                if (microtime(true) - $started >= $timeBudgetSeconds) {
                    break;
                }
            }

            $remaining = array_values(array_diff(
                array_map('strval', array_keys($files)),
                $this->migrator->getRepository()->getRan(),
            ));

            return [
                'ran' => $ranNow,
                'remaining' => $remaining,
                'complete' => $remaining === [],
            ];
        });
    }

    /**
     * MySQL/MariaDB commit DDL immediately, so a migration that fails halfway
     * would leave its new tables behind and every retry would then fail with
     * "table already exists". Only tables that did not exist before this
     * attempt are removed; nothing that was already there is touched.
     */
    private function runOne(string $name, string $path): void
    {
        $schema = $this->migrator->resolveConnection($this->connection)->getSchemaBuilder();
        $tablesBefore = $schema->getTableListing(schemaQualified: false);

        try {
            $this->migrator->runPending([$path], ['pretend' => false, 'step' => false]);
        } catch (Throwable $exception) {
            $created = array_diff($schema->getTableListing(schemaQualified: false), $tablesBefore);

            if ($created !== []) {
                try {
                    $schema->withoutForeignKeyConstraints(static function () use ($schema, $created): void {
                        foreach ($created as $table) {
                            $schema->dropIfExists($table);
                        }
                    });
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }

            throw new InstallerMigrationFailed($name, $exception);
        }
    }
}
