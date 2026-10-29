<?php

namespace Tests\Feature\System;

use App\Contracts\SystemProbe;
use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\InstallationState;
use App\Support\Installer\DatabaseVerifier;
use App\Support\Installer\InstallerEnvironment;
use App\Support\Installer\InstallerJournal;
use App\Support\Installer\InstallerMigrationRunner;
use App\Support\Lifecycle\ProductVersion;
use App\Support\Operations\OperationalHealth;
use App\Support\Operations\QueueProbe;
use App\Support\Operations\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class InstallerFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private const APP_KEY = 'base64:YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXphYmNkZWY=';

    private string $directory;

    private string $lockPath;

    private string $environmentPath;

    private string $runtimeKeyPath;

    private InstallerJournal $journal;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-finalize-'.bin2hex(random_bytes(6)));
        $this->lockPath = $this->directory.'/.installed';
        $this->environmentPath = $this->directory.'/.env';
        $this->runtimeKeyPath = $this->directory.'/runtime-app-key';
        File::ensureDirectoryExists($this->directory);
        File::put($this->directory.'/VERSION', "0.1.0-test\n");

        config([
            'app.key' => self::APP_KEY,
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->lockPath,
            'installer.journal_path' => $this->directory.'/journal.json',
            'installer.mutex_path' => $this->directory.'/installer.lock',
            'installer.runtime_key_path' => $this->runtimeKeyPath,
            'installer.scheduler_heartbeat_path' => $this->directory.'/scheduler-heartbeat.json',
            'installer.queue_probe_path' => $this->directory.'/queue-probes',
        ]);

        $this->app->bind(SystemProbe::class, fn () => $this->probe());
        $this->app->instance(ProductVersion::class, new ProductVersion($this->directory.'/VERSION'));
        $this->writeEnvironment(self::APP_KEY);
        $this->app->instance(InstallerEnvironment::class, new InstallerEnvironment($this->environmentPath));

        $databaseVerifier = new DatabaseVerifier(
            static fn (array $connection): null => null,
            static fn (string $extension): bool => true,
        );
        $this->app->instance(DatabaseVerifier::class, $databaseVerifier);

        $heartbeat = new SchedulerHeartbeat($this->directory.'/scheduler-heartbeat.json');
        $heartbeat->record();
        $queueProbe = new QueueProbe(
            $this->directory.'/queue-probes',
            static fn (string $id): null => null,
        );
        $probeId = $queueProbe->dispatch();
        $queueProbe->complete($probeId);
        $this->app->instance(OperationalHealth::class, new OperationalHealth($heartbeat, $queueProbe));

        File::put($this->runtimeKeyPath, self::APP_KEY.PHP_EOL);

        $this->owner = User::factory()->create([
            'email' => 'owner@example.test',
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $workspace = Workspace::query()->create(['name' => 'فضای کاری اصلی']);
        Membership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $this->owner->id,
            'role' => WorkspaceRole::Owner,
        ]);

        $this->journal = new InstallerJournal($this->directory.'/journal.json');
        $this->app->instance(InstallerJournal::class, $this->journal);
        $this->journal->replace([
            'phase' => 'finalize',
            'database_fingerprint' => $databaseVerifier->fingerprint($this->databaseConnection()),
            'database_verified_at' => now()->toIso8601String(),
            'migrations_completed_at' => now()->toIso8601String(),
            'owner_user_id' => $this->owner->id,
            'owner_created_at' => now()->toIso8601String(),
            'operations_checked_at' => now()->toIso8601String(),
            'operations_scheduler_state' => 'verified',
            'operations_queue_state' => 'verified',
            'operations_verified_at' => now()->toIso8601String(),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_finalize_phase_exposes_only_real_finalize_action(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('install/index')
                ->where('installer.phase', 'finalize')
                ->where('installer.actions', ['finalize_installation']));
    }

    public function test_failed_preflight_cannot_write_installed_lock(): void
    {
        $this->app->bind(SystemProbe::class, fn () => $this->probe(phpVersion: '8.2.0'));

        $this->finalizeRequest()
            ->assertRedirect('/install')
            ->assertSessionHasErrors('finalize');

        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_persistent_environment_key_mismatch_cannot_write_installed_lock(): void
    {
        $this->writeEnvironment('base64:bm90LXRoZS1ydW50aW1lLWtleQ==');

        $this->finalizeRequest()
            ->assertRedirect('/install')
            ->assertSessionHasErrors('finalize');

        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_database_fingerprint_mismatch_cannot_write_installed_lock(): void
    {
        $state = $this->journal->read();
        $state['database_fingerprint'] = str_repeat('f', 64);
        $this->journal->replace($state);

        $this->finalizeRequest()
            ->assertRedirect('/install')
            ->assertSessionHasErrors('finalize');

        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_pending_migrations_cannot_write_installed_lock(): void
    {
        $this->mock(InstallerMigrationRunner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')->andReturn([
                'pending' => ['2099_01_01_000000_future_migration'],
                'complete' => false,
            ]);
        });

        $this->finalizeRequest()
            ->assertRedirect('/install')
            ->assertSessionHasErrors('finalize');

        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_invalid_owner_membership_cannot_write_installed_lock(): void
    {
        Membership::query()->where('user_id', $this->owner->id)->update([
            'role' => WorkspaceRole::Viewer->value,
        ]);

        $this->finalizeRequest()
            ->assertRedirect('/install')
            ->assertSessionHasErrors('finalize');

        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_blocking_operations_failure_cannot_be_acknowledged_into_success(): void
    {
        $this->mock(OperationalHealth::class, function (MockInterface $mock): void {
            $mock->shouldReceive('snapshot')->andReturn([
                'scheduler' => ['state' => 'fail', 'observed_at' => null],
                'queue' => ['state' => 'verified', 'completed_at' => now()->toIso8601String()],
                'instructions' => ['scheduler' => 'schedule', 'worker' => 'worker'],
                'blocking' => true,
            ]);
        });

        $this->finalizeRequest()
            ->assertRedirect('/install')
            ->assertSessionHasErrors('finalize');

        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_operational_warning_without_recorded_acknowledgement_cannot_finalize(): void
    {
        $this->mock(OperationalHealth::class, function (MockInterface $mock): void {
            $mock->shouldReceive('snapshot')->andReturn([
                'scheduler' => ['state' => 'warning', 'observed_at' => null],
                'queue' => ['state' => 'warning', 'completed_at' => null],
                'instructions' => ['scheduler' => 'schedule', 'worker' => 'worker'],
                'blocking' => false,
            ]);
        });
        $state = $this->journal->read();
        unset($state['operations_verified_at'], $state['operations_warnings_acknowledged_at']);
        $state['operations_scheduler_state'] = 'warning';
        $state['operations_queue_state'] = 'warning';
        $this->journal->replace($state);

        $this->finalizeRequest()
            ->assertRedirect('/install')
            ->assertSessionHasErrors('finalize');

        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_successful_finalization_writes_lock_authenticates_owner_and_cleans_transient_state(): void
    {
        $this->finalizeRequest()
            ->assertRedirect('/dashboard')
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($this->owner);
        $this->assertFileExists($this->lockPath);
        $this->assertFileDoesNotExist($this->runtimeKeyPath);
        $this->assertSame([], $this->journal->read());

        $details = (new InstallationState($this->lockPath, false))->details();
        $this->assertIsArray($details);
        $this->assertSame(2, $details['installer_schema_version']);
        $this->assertSame('0.1.0-test', $details['product_version']);
        $this->assertSame($this->owner->id, $details['owner_user_id']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $details['installation_id']);
    }

    public function test_lock_write_failure_keeps_installation_recoverable_and_does_not_authenticate(): void
    {
        $blockedParent = $this->directory.'/blocked-parent';
        File::put($blockedParent, 'not-a-directory');
        config(['installer.lock_path' => $blockedParent.'/.installed']);

        $this->finalizeRequest()
            ->assertRedirect('/install')
            ->assertSessionHasErrors('finalize');

        $this->assertGuest();
        $this->assertFileExists($this->runtimeKeyPath);
        $this->assertSame('finalize', $this->journal->read()['phase']);
    }

    public function test_duplicate_finalization_is_safely_locked_out_after_first_success(): void
    {
        $this->finalizeRequest()->assertRedirect('/dashboard');
        $details = (new InstallationState($this->lockPath, false))->details();

        $this->post('/install/finalize')->assertRedirect('/');

        $this->assertSame(
            $details['installation_id'],
            (new InstallationState($this->lockPath, false))->details()['installation_id'],
        );
    }

    public function test_every_installer_route_is_inaccessible_after_installed_lock_exists(): void
    {
        (new InstallationState($this->lockPath, false))->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => '0.1.0-test',
            'installation_id' => str_repeat('a', 32),
        ]);

        $this->get('/install')->assertRedirect('/');

        foreach ([
            '/install/environment',
            '/install/database/verify',
            '/install/migrations/run',
            '/install/owner',
            '/install/operations/probe',
            '/install/operations/continue',
            '/install/finalize',
        ] as $route) {
            $this->post($route)->assertRedirect('/');
        }
    }

    private function finalizeRequest()
    {
        return $this->from('/install')->post('/install/finalize');
    }

    private function writeEnvironment(string $key): void
    {
        File::put($this->environmentPath, implode(PHP_EOL, [
            'APP_ENV=production',
            'APP_KEY="'.$key.'"',
            'APP_DEBUG=false',
            'APP_URL="https://seo.example.test"',
            'DB_CONNECTION=mysql',
            'DB_HOST="127.0.0.1"',
            'DB_PORT=3306',
            'DB_DATABASE="seo_os"',
            'DB_USERNAME="seo_os"',
            'DB_PASSWORD="secret"',
            'SESSION_DRIVER=database',
            'CACHE_STORE=database',
            'QUEUE_CONNECTION=database',
            'SELF_HOST_REGISTRATION=false',
            '',
        ]));
    }

    /** @return array{driver: string, host: string, port: int, database: string, username: string, password: string} */
    private function databaseConnection(): array
    {
        return [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'seo_os',
            'username' => 'seo_os',
            'password' => 'secret',
        ];
    }

    private function probe(string $phpVersion = '8.3.0'): SystemProbe
    {
        return new class($phpVersion) implements SystemProbe
        {
            public function __construct(private string $phpVersion) {}

            public function phpVersion(): string
            {
                return $this->phpVersion;
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
                return true;
            }

            public function databaseDriver(): string
            {
                return 'mysql';
            }

            public function queueConnection(): string
            {
                return 'database';
            }

            public function schedulerSupported(): bool
            {
                return true;
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
