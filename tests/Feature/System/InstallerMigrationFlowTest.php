<?php

namespace Tests\Feature\System;

use App\Contracts\SystemProbe;
use App\Support\Installer\InstallerEnvironment;
use App\Support\Installer\InstallerJournal;
use App\Support\Installer\InstallerMigrationFailed;
use App\Support\Installer\InstallerMigrationRunner;
use App\Support\Installer\InstallerMutex;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class InstallerMigrationFlowTest extends TestCase
{
    private string $directory;

    private string $environmentPath;

    private InstallerJournal $journal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-migration-flow-'.bin2hex(random_bytes(6)));
        $this->environmentPath = $this->directory.'/.env';
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->environmentPath, $this->environmentContents());

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->directory.'/.installed',
            'installer.journal_path' => $this->directory.'/journal.json',
            'installer.mutex_path' => $this->directory.'/installer.lock',
        ]);

        $this->journal = new InstallerJournal($this->directory.'/journal.json');
        $this->app->instance(InstallerJournal::class, $this->journal);
        $this->app->instance(InstallerMutex::class, new InstallerMutex($this->directory.'/installer.lock'));
        $this->app->instance(InstallerEnvironment::class, new InstallerEnvironment($this->environmentPath));
        $this->app->bind(SystemProbe::class, fn () => $this->probe());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_migrations_phase_exposes_bounded_pending_status_without_mutating_journal(): void
    {
        $this->writeVerifiedMigrationState();
        $before = $this->journal->read();
        $runner = $this->runner(
            status: [
                'pending' => [
                    '2026_10_22_000001_first',
                    '2026_10_22_000002_second',
                ],
                'complete' => false,
            ],
        );

        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('install/index')
                ->where('installer.phase', 'migrations')
                ->where('installer.actions', ['run_migrations'])
                ->where('migrations.pending_count', 2)
                ->where('migrations.pending', [
                    '2026_10_22_000001_first',
                    '2026_10_22_000002_second',
                ])
                ->where('migrations.complete', false)
            );

        $runner->shouldHaveReceived('status')->once();
        $this->assertSame($before, $this->journal->read());
    }

    public function test_get_never_advances_even_when_repository_is_already_complete(): void
    {
        $this->writeVerifiedMigrationState();
        $runner = $this->runner(status: ['pending' => [], 'complete' => true]);

        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('installer.phase', 'migrations')
                ->where('installer.actions', ['run_migrations'])
                ->where('migrations.pending_count', 0)
                ->where('migrations.complete', true)
            );

        $runner->shouldHaveReceived('status')->once();
        $this->assertSame('migrations', $this->journal->read()['phase']);
    }

    public function test_missing_or_stale_database_fingerprint_returns_to_database_before_running_migrations(): void
    {
        $runner = $this->runner(runResult: [
            'ran' => [],
            'remaining' => [],
            'complete' => true,
        ]);

        foreach ([null, hash('sha256', 'stale-database')] as $fingerprint) {
            $state = [
                'phase' => 'migrations',
                'database_facts' => $this->databaseFacts(),
                'database_verified_at' => '2026-10-22T20:00:00+00:00',
                'migrations_last_attempted_at' => '2026-10-22T20:01:00+00:00',
            ];

            if ($fingerprint !== null) {
                $state['database_fingerprint'] = $fingerprint;
            }

            $this->journal->replace($state);

            $this->from('/install')->post('/install/migrations/run')
                ->assertRedirect('/install')
                ->assertSessionHasErrors([
                    'migrations' => 'Database settings changed or are no longer verified. Verify the database again before running migrations.',
                ]);

            $journal = $this->journal->read();
            $this->assertSame('database', $journal['phase']);
            $this->assertArrayNotHasKey('database_fingerprint', $journal);
            $this->assertArrayNotHasKey('migrations_last_attempted_at', $journal);
        }

        $runner->shouldNotHaveReceived('runPending');
    }

    public function test_incomplete_run_stays_retryable_and_preserves_verified_database_evidence(): void
    {
        $this->writeVerifiedMigrationState();
        $runner = $this->runner(runResult: [
            'ran' => ['2026_10_22_000001_first'],
            'remaining' => ['2026_10_22_000002_second'],
            'complete' => false,
        ]);

        $this->post('/install/migrations/run')
            ->assertRedirect('/install');

        $runner->shouldHaveReceived('runPending')->once();
        $journal = $this->journal->read();

        $this->assertSame('migrations', $journal['phase']);
        $this->assertSame($this->currentFingerprint(), $journal['database_fingerprint']);
        $this->assertSame($this->databaseFacts(), $journal['database_facts']);
        $this->assertSame(1, $journal['migrations_pending_count']);
        $this->assertArrayHasKey('migrations_last_attempted_at', $journal);
        $this->assertArrayNotHasKey('migrations_completed_at', $journal);
    }

    public function test_complete_run_advances_to_owner_only_after_runner_reports_complete(): void
    {
        $this->writeVerifiedMigrationState();
        $runner = $this->runner(runResult: [
            'ran' => ['2026_10_22_000001_first'],
            'remaining' => [],
            'complete' => true,
        ]);

        $this->post('/install/migrations/run')
            ->assertRedirect('/install');

        $runner->shouldHaveReceived('runPending')->once();
        $journal = $this->journal->read();

        $this->assertSame('owner', $journal['phase']);
        $this->assertSame($this->currentFingerprint(), $journal['database_fingerprint']);
        $this->assertSame($this->databaseFacts(), $journal['database_facts']);
        $this->assertArrayHasKey('migrations_completed_at', $journal);
        $this->assertArrayNotHasKey('migrations_pending_count', $journal);
    }

    public function test_migration_exception_is_sanitized_and_keeps_verified_retry_state(): void
    {
        $this->writeVerifiedMigrationState();
        $runner = Mockery::mock(InstallerMigrationRunner::class);
        $runner->allows('status')->andReturn(['pending' => ['2026_10_22_000002_second'], 'complete' => false]);
        $runner->allows('runPending')->andThrow(new RuntimeException('browser-secret raw database failure'));
        $this->app->instance(InstallerMigrationRunner::class, $runner);

        $this->from('/install')->post('/install/migrations/run')
            ->assertRedirect('/install')
            ->assertSessionHasErrors([
                'migrations' => 'Database migrations could not be completed. Retrying is safe.',
            ]);

        $runner->shouldHaveReceived('runPending')->once();
        $journal = $this->journal->read();
        $serialized = json_encode($journal, JSON_THROW_ON_ERROR);

        $this->assertSame('migrations', $journal['phase']);
        $this->assertSame($this->currentFingerprint(), $journal['database_fingerprint']);
        $this->assertStringNotContainsString('browser-secret', $serialized);
        $this->assertStringNotContainsString('raw database failure', $serialized);
    }

    public function test_failed_migration_shows_only_a_sanitized_reason_and_is_reported(): void
    {
        $this->writeVerifiedMigrationState();
        $driverError = new PDOException('SQLSTATE[HY000]: Index column size too large');
        $driverError->errorInfo = ['HY000', 1709, 'Index column size too large'];
        $failure = new QueryException(
            'mariadb',
            'alter table `users` add unique `users_email_unique`(`email`) /* secret-password /home/cpaneluser */',
            ['browser-secret'],
            $driverError,
        );
        $runner = Mockery::mock(InstallerMigrationRunner::class);
        $runner->allows('status')->andReturn(['pending' => ['0001_01_01_000000_create_users_table'], 'complete' => false]);
        $runner->allows('runPending')->andThrow(new InstallerMigrationFailed('0001_01_01_000000_create_users_table', $failure));
        $this->app->instance(InstallerMigrationRunner::class, $runner);
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (InstallerMigrationFailed $exception) use (&$reported): void {
            $reported[] = $exception;
        });

        // Exactly this: the migration, the exception class and the SQL error
        // codes; no SQL, bindings, credentials, paths or driver message.
        $this->from('/install')->post('/install/migrations/run')
            ->assertRedirect('/install')
            ->assertSessionHasErrors([
                'migrations' => 'Database migrations could not be completed. Retrying is safe. Reason: '
                    .'0001_01_01_000000_create_users_table — '.QueryException::class.' — SQLSTATE HY000 — code 1709',
            ]);
        $this->assertCount(1, $reported);
        $this->assertSame('migrations', $this->journal->read()['phase']);
    }

    public function test_run_endpoint_is_phase_guarded_and_does_not_reopen_completed_work(): void
    {
        $this->journal->replace([
            'phase' => 'owner',
            'database_fingerprint' => $this->currentFingerprint(),
            'database_facts' => $this->databaseFacts(),
            'migrations_completed_at' => '2026-10-22T20:02:00+00:00',
        ]);
        $runner = $this->runner(runResult: [
            'ran' => [],
            'remaining' => [],
            'complete' => true,
        ]);

        $this->from('/install')->post('/install/migrations/run')
            ->assertRedirect('/install')
            ->assertSessionHasErrors([
                'migrations' => 'Migrations are not available in the current installer phase.',
            ]);

        $runner->shouldNotHaveReceived('runPending');
        $this->assertSame('owner', $this->journal->read()['phase']);
    }

    /**
     * @param  array{pending: list<string>, complete: bool}|null  $status
     * @param  array{ran: list<string>, remaining: list<string>, complete: bool}|null  $runResult
     */
    private function runner(?array $status = null, ?array $runResult = null): InstallerMigrationRunner
    {
        $runner = Mockery::mock(InstallerMigrationRunner::class);
        $runner->allows('status')->andReturn($status ?? ['pending' => [], 'complete' => true]);
        $runner->allows('runPending')->andReturn($runResult ?? ['ran' => [], 'remaining' => [], 'complete' => true]);
        $this->app->instance(InstallerMigrationRunner::class, $runner);

        return $runner;
    }

    private function writeVerifiedMigrationState(): void
    {
        $this->journal->replace([
            'phase' => 'migrations',
            'database_fingerprint' => $this->currentFingerprint(),
            'database_facts' => $this->databaseFacts(),
            'database_verified_at' => '2026-10-22T20:00:00+00:00',
        ]);
    }

    /** @return array{driver: string, host: string, port: int, database: string} */
    private function databaseFacts(): array
    {
        return [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'seo_os',
        ];
    }

    private function currentFingerprint(): string
    {
        return hash(
            'sha256',
            json_encode($this->databaseFacts(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    private function environmentContents(): string
    {
        return implode(PHP_EOL, [
            'APP_URL="https://seo.example.test"',
            'DB_CONNECTION=mysql',
            'DB_HOST=127.0.0.1',
            'DB_PORT=3306',
            'DB_DATABASE=seo_os',
            'DB_USERNAME=seo_user',
            'DB_PASSWORD="browser-secret"',
            '',
        ]);
    }

    private function probe(): SystemProbe
    {
        return new class implements SystemProbe
        {
            public function phpVersion(): string
            {
                return '8.3.0';
            }

            public function extensionLoaded(string $extension): bool
            {
                return true;
            }

            public function pathIsWritable(string $path): bool
            {
                return true;
            }

            public function databaseReachable(): bool
            {
                return false;
            }

            public function databaseDriver(): string
            {
                return '';
            }

            public function queueConnection(): string
            {
                return '';
            }

            public function schedulerSupported(): bool
            {
                return false;
            }

            public function freeDiskBytes(string $path): ?int
            {
                return 1024 * 1024 * 1024;
            }

            public function appKeyConfigured(): bool
            {
                return true;
            }

            public function productionAssetsPresent(): bool
            {
                return true;
            }

            public function applicationFilesPresent(): bool
            {
                return true;
            }
        };
    }
}
