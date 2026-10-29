<?php

namespace Tests\Feature\System;

use App\Support\Installer\InstallerMigrationRunner;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class InstallerMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_status_derives_pending_migrations_from_repository_state(): void
    {
        [$runner] = $this->runner(
            files: [
                '2026_01_01_000000_first' => '/migrations/2026_01_01_000000_first.php',
                '2026_01_01_000001_second' => '/migrations/2026_01_01_000001_second.php',
            ],
            ran: ['2026_01_01_000000_first'],
        );

        $this->assertSame([
            'pending' => ['2026_01_01_000001_second'],
            'complete' => false,
        ], $runner->status());
    }

    public function test_missing_repository_reports_every_migration_pending_without_creating_state(): void
    {
        [$runner, $migrator, $repository] = $this->runner(
            files: [
                '2026_01_01_000000_first' => '/migrations/2026_01_01_000000_first.php',
            ],
            repositoryExists: false,
        );

        $repository->shouldNotHaveReceived('createRepository');
        $migrator->shouldNotHaveReceived('run');
        $this->assertSame([
            'pending' => ['2026_01_01_000000_first'],
            'complete' => false,
        ], $runner->status());
        $repository->shouldNotHaveReceived('createRepository');
    }

    public function test_complete_repository_is_idempotent_and_does_not_rerun_migrations(): void
    {
        $files = [
            '2026_01_01_000000_first' => '/migrations/2026_01_01_000000_first.php',
        ];
        [$runner, $migrator] = $this->runner(
            files: $files,
            ran: ['2026_01_01_000000_first'],
        );

        $migrator->shouldNotHaveReceived('run');
        $this->assertSame([
            'pending' => [],
            'complete' => true,
        ], $runner->status());
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<string>  $ran
     * @return array{InstallerMigrationRunner, Migrator&MockInterface, MigrationRepositoryInterface&MockInterface}
     */
    private function runner(array $files, array $ran = [], bool $repositoryExists = true): array
    {
        $repository = Mockery::mock(MigrationRepositoryInterface::class);
        $migrator = Mockery::mock(Migrator::class);

        $migrator->shouldReceive('usingConnection')
            ->andReturnUsing(static fn (string $connection, callable $callback): mixed => $callback());
        $migrator->shouldReceive('getMigrationFiles')->with('/migrations')->andReturn($files);
        $migrator->shouldReceive('repositoryExists')->andReturn($repositoryExists);
        $migrator->shouldReceive('getRepository')->andReturn($repository);

        if ($repositoryExists) {
            $repository->shouldReceive('getRan')->andReturn($ran);
        }

        return [
            new InstallerMigrationRunner($migrator, 'installer', '/migrations'),
            $migrator,
            $repository,
        ];
    }
}
