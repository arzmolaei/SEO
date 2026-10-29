<?php

namespace Tests\Feature\System;

use App\Contracts\SystemProbe;
use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\InstallationState;
use App\Support\Installer\EnvironmentWriter;
use App\Support\Installer\InstallerEnvironment;
use App\Support\Installer\InstallerJournal;
use App\Support\Installer\InstallerMutex;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SelfHostInstallerAcceptanceTest extends TestCase
{
    private string $directory;

    private string $environmentPath;

    private string $examplePath;

    private string $lockPath;

    private InstallerJournal $journal;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('SELF_HOST_INSTALLER_CONNECTED') !== '1') {
            $this->markTestSkipped('Connected self-host installer acceptance runs only in the MariaDB CI gate.');
        }

        $this->directory = storage_path('framework/testing/self-host-installer-acceptance-'.bin2hex(random_bytes(6)));
        $this->environmentPath = $this->directory.'/.env';
        $this->examplePath = $this->directory.'/.env.example';
        $this->lockPath = $this->directory.'/.installed';
        File::ensureDirectoryExists($this->directory);
        File::copy(base_path('.env.example'), $this->examplePath);

        $this->configureConnectedDatabase();
        $this->resetConnectedDatabase();

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->lockPath,
            'installer.journal_path' => $this->directory.'/journal.json',
            'installer.mutex_path' => $this->directory.'/installer.lock',
            'installer.runtime_key_path' => $this->directory.'/runtime-app-key',
            'installer.scheduler_heartbeat_path' => $this->directory.'/scheduler-heartbeat.json',
            'installer.queue_probe_path' => $this->directory.'/queue-probes',
        ]);

        $this->journal = new InstallerJournal($this->directory.'/journal.json');
        $this->app->instance(InstallerJournal::class, $this->journal);
        $this->app->instance(InstallerMutex::class, new InstallerMutex($this->directory.'/installer.lock'));
        $this->app->instance(InstallerEnvironment::class, new InstallerEnvironment($this->environmentPath));
        $this->app->instance(EnvironmentWriter::class, new EnvironmentWriter($this->environmentPath, $this->examplePath));
        $this->app->bind(SystemProbe::class, fn () => $this->healthyProbe());
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            try {
                $this->resetConnectedDatabase();
            } catch (\Throwable) {
                // Preserve the original test failure if database cleanup is unavailable.
            }

            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_clean_mariadb_install_survives_browser_refreshes_and_response_loss_then_locks_installer(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('install/index')
                ->where('installer.phase', 'environment'));

        $environment = $this->environmentPayload();

        $this->post('/install/environment', $environment)
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();
        $this->assertSame('database', $this->journal->read()['phase']);

        // A lost response / repeated submit of the same environment remains safe.
        $this->post('/install/environment', $environment)
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();
        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('installer.phase', 'database'));

        $this->post('/install/database/verify')
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();
        $this->assertSame('migrations', $this->journal->read()['phase']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $this->journal->read()['database_fingerprint']);

        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('installer.phase', 'migrations')
                ->where('migrations.available', true));

        // Simulate a browser interruption after the migration action: durable state must be enough to resume.
        $this->post('/install/migrations/run')
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();
        $this->assertSame('owner', $this->journal->read()['phase']);
        $this->assertTrue(DB::connection('mariadb')->getSchemaBuilder()->hasTable('users'));
        $this->assertTrue(DB::connection('mariadb')->getSchemaBuilder()->hasTable('jobs'));
        $this->assertTrue(DB::connection('mariadb')->getSchemaBuilder()->hasTable('sessions'));

        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('installer.phase', 'owner'));

        $ownerPayload = [
            'name' => 'مالک پذیرش',
            'email' => 'acceptance-owner@example.test',
            'workspace_name' => 'فضای کاری پذیرش',
            'password' => 'Strong!Connected123',
            'password_confirmation' => 'Strong!Connected123',
        ];

        $this->post('/install/owner', $ownerPayload)
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();

        // Lost owner response is explicitly idempotent after the phase has advanced.
        $this->post('/install/owner', $ownerPayload)
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();

        $owner = User::query()->sole();
        $workspace = Workspace::query()->sole();
        $membership = Membership::query()->sole();
        $this->assertNotNull($owner->email_verified_at);
        $this->assertNotNull($owner->onboarded_at);
        $this->assertSame($workspace->id, $membership->workspace_id);
        $this->assertSame($owner->id, $membership->user_id);
        $this->assertSame(WorkspaceRole::Owner, $membership->role);
        $this->assertSame('operations', $this->journal->read()['phase']);

        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('installer.phase', 'operations')
                ->where('operations.scheduler.state', 'warning')
                ->where('operations.queue.state', 'warning')
                ->where('operations.blocking', false));

        // Shared-host path: cron commands have been configured externally but have not yet produced fresh evidence.
        $this->post('/install/operations/continue', ['acknowledge_warnings' => true])
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();
        $this->assertSame('finalize', $this->journal->read()['phase']);
        $this->assertArrayHasKey('operations_warnings_acknowledged_at', $this->journal->read());

        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('installer.phase', 'finalize')
                ->where('installer.actions', ['finalize_installation']));

        $this->post('/install/finalize')
            ->assertRedirect('/dashboard')
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($owner);
        $this->assertFileExists($this->lockPath);
        $this->assertSame([], $this->journal->read());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('memberships', 1);

        $details = (new InstallationState($this->lockPath, false))->details();
        $this->assertIsArray($details);
        $this->assertSame((new ProductVersion)->current(), $details['product_version']);
        $this->assertSame(2, $details['installer_schema_version']);
        $this->assertSame($owner->id, $details['owner_user_id']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', (string) $details['installation_id']);

        $this->get('/dashboard')->assertOk();
        $this->get('/install')->assertRedirect('/');

        // A repeated finalization request cannot create a second installation identity.
        $installationId = $details['installation_id'];
        $this->post('/install/finalize')->assertRedirect('/');
        $this->assertSame(
            $installationId,
            (new InstallationState($this->lockPath, false))->details()['installation_id'],
        );

        $journalText = is_file($this->directory.'/journal.json')
            ? (string) file_get_contents($this->directory.'/journal.json')
            : '';
        $this->assertStringNotContainsString((string) $this->connectedValue('PASSWORD'), $journalText);
        $this->assertStringNotContainsString('Strong!Connected123', $journalText);
    }

    /** @return array<string, int|string|bool> */
    private function environmentPayload(): array
    {
        return [
            'app_url' => 'http://127.0.0.1',
            'database_driver' => 'mariadb',
            'database_host' => (string) $this->connectedValue('HOST'),
            'database_port' => (int) $this->connectedValue('PORT'),
            'database_name' => (string) $this->connectedValue('DATABASE'),
            'database_username' => (string) $this->connectedValue('USERNAME'),
            'database_password' => (string) $this->connectedValue('PASSWORD'),
            'keep_database_password' => false,
        ];
    }

    private function configureConnectedDatabase(): void
    {
        config([
            'database.default' => 'mariadb',
            'database.connections.mariadb.host' => (string) $this->connectedValue('HOST'),
            'database.connections.mariadb.port' => (int) $this->connectedValue('PORT'),
            'database.connections.mariadb.database' => (string) $this->connectedValue('DATABASE'),
            'database.connections.mariadb.username' => (string) $this->connectedValue('USERNAME'),
            'database.connections.mariadb.password' => (string) $this->connectedValue('PASSWORD'),
            'database.connections.mariadb.url' => null,
            'session.driver' => 'file',
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ]);

        DB::purge('mariadb');
        DB::setDefaultConnection('mariadb');
    }

    private function resetConnectedDatabase(): void
    {
        if (config('database.default') !== 'mariadb') {
            return;
        }

        $schema = DB::connection('mariadb')->getSchemaBuilder();
        $schema->disableForeignKeyConstraints();

        foreach ($schema->getTableListing() as $table) {
            if (str_contains((string) $table, '.')) {
                $table = substr((string) $table, strrpos((string) $table, '.') + 1);
            }

            $schema->drop((string) $table);
        }

        $schema->enableForeignKeyConstraints();
    }

    private function connectedValue(string $key): string
    {
        $value = (string) getenv('INSTALLER_CONNECTED_DB_'.$key);

        if ($value === '') {
            $this->fail("Missing INSTALLER_CONNECTED_DB_{$key} for connected installer acceptance.");
        }

        return $value;
    }

    private function healthyProbe(): SystemProbe
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
                return true;
            }

            public function databaseDriver(): string
            {
                return 'mariadb';
            }

            public function queueConnection(): string
            {
                return 'sync';
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
