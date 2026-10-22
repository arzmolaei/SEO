<?php

namespace Tests\Feature\System;

use App\Support\Installer\InstallerMigrationRunner;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Mockery;
use RuntimeException;
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

    public function test_run_creates_repository_when_needed_and_executes_only_pending_migrations(): void
    {
        $files = [
            '2026_01_01_000000_first' => '/migrations/2026_01_01_000000_first.php',
            '2026_01_01_000001_second' => '/migrations/2026_01_01_000001_second.php',
        ];
        $repository = Mockery::mock(MigrationRepositoryInterface::class);
        $migrator = Mockery::mock(Migrator::class);

        $migrator->shouldReceive('usingConnection')
            ->twice()
            ->with('installer', Mockery::type('callable'))
            ->andReturnUsing(static fn (string $connection, callable $callback): mixed => $callback());
        $migrator->shouldReceive('getMigrationFiles')->twice()->with('/migrations')->andReturn($files);
        $migrator->shouldReceive('repositoryExists')->twice()->andReturn(false, true);
        $migrator->shouldReceive('getRepository')->times(3)->andReturn($repository);
        $repository->shouldReceive('createRepository')->once();
        $repository->shouldReceive('getRan')->once()->andReturn(['2026_01_01_000000_first', '2026_01_01_000001_second']);
        $migrator->shouldReceive('run')
            ->once()
            ->with('/migrations', ['pretend' => false, 'step' => false])
            ->andReturn(array_values($files));

        $runner = new InstallerMigrationRunner($migrator, 'installer', '/migrations');

        $this->assertSame([
            'ran' => ['2026_01_01_000000_first', '2026_01_01_000001_second'],
            'remaining' => [],
            'complete' => true,
        ], $runner->runPending());
    }

    public function test_interrupted_run_leaves_completed_migration_recorded_and_retry_status_incomplete(): void
    {
        $files = [
            '2026_01_01_000000_first' => '/migrations/2026_01_01_000000_first.php',
            '2026_01_01_000001_second' => '/migrations/2026_01_01_000001_second.php',
        ];
        $repository = Mockery::mock(MigrationRepositoryInterface::class);
        $migrator = Mockery::mock(Migrator::class);

        $migrator->shouldReceive('usingConnection')
            ->twice()
            ->andReturnUsing(static fn (string $connection, callable $callback): mixed => $callback());
        $migrator->shouldReceive('repositoryExists')->twice()->andReturn(true);
        $migrator->shouldReceive('getRepository')->once()->andReturn($repository);
        $migrator->shouldReceive('getMigrationFiles')->once()->with('/migrations')->andReturn($files);
        $repository->shouldReceive('getRan')->once()->andReturn(['2026_01_01_000000_first']);
        $migrator->shouldReceive('run')
            ->once()
            ->andThrow(new RuntimeException('simulated interruption'));

        $runner = new InstallerMigrationRunner($migrator, 'installer', '/migrations');

        try {
            $runner->runPending();
            $this->fail('Expected simulated migration interruption.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated interruption', $exception->getMessage());
        }

        $this->assertSame([
            'pending' => ['2026_01_01_000001_second'],
            'complete' => false,
        ], $runner->status());
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
     * @return array{InstallerMigrationRunner, Migrator&\Mockery\MockInterface, MigrationRepositoryInterface&\Mockery\MockInterface}
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
