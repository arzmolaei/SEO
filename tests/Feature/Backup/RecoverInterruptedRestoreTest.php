<?php

namespace Tests\Feature\Backup;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\ApplicationTableCatalog;
use App\Services\Backup\BackupException;
use App\Services\Backup\CaptureSystemState;
use App\Services\Backup\EnvironmentSettingsUpdater;
use App\Services\Backup\RecoverInterruptedRestore;
use App\Services\Backup\RestoreJournal;
use App\Services\Backup\RestoreSystemBackup;
use App\Services\Backup\VerifyRestoreBundle;
use App\Support\InstallationState;
use App\Support\Installer\InstallerEnvironment;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RecoverInterruptedRestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $environmentPath;

    private string $privateUploads;

    private string $publicUploads;

    private string $restoreUploads;

    private string $journalPath;

    private string $installationLockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/recover-interrupted-restore-'.bin2hex(random_bytes(6)));
        $this->environmentPath = $this->directory.'/.env';
        $this->privateUploads = $this->directory.'/private/uploads';
        $this->publicUploads = $this->directory.'/public/uploads';
        $this->restoreUploads = $this->directory.'/lifecycle/restore/uploads';
        $this->journalPath = $this->directory.'/lifecycle/restore/state.json';
        $this->installationLockPath = $this->directory.'/.installed';

        File::ensureDirectoryExists($this->privateUploads);
        File::ensureDirectoryExists($this->publicUploads);
        File::ensureDirectoryExists($this->restoreUploads);

        File::put($this->environmentPath, implode("\n", [
            '# destination environment',
            'APP_KEY=base64:destination-key',
            'APP_LOCALE=de',
            'APP_FALLBACK_LOCALE=en',
            'SELF_HOST_REGISTRATION=false',
            'APP_URL=https://destination.example.test',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=/srv/destination.sqlite',
            'DB_PASSWORD=destination-db-secret',
            'MAIL_PASSWORD=destination-mail-secret',
            'FILESYSTEM_DISK=local',
            'EXTERNAL_API_TOKEN=destination-external-secret',
            '',
        ]));

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->installationLockPath,
            'installer.public_registration' => false,
            'backup.lifecycle_mutex_path' => $this->directory.'/lifecycle/lifecycle.lock',
            'backup.restore.upload_path' => $this->restoreUploads,
            'backup.restore.journal_path' => $this->journalPath,
            'backup.durable_file_roots' => [
                'storage/app/private/uploads' => $this->privateUploads,
                'storage/app/public/uploads' => $this->publicUploads,
            ],
            'app.key' => 'base64:destination-key',
            'app.locale' => 'de',
            'app.fallback_locale' => 'en',
        ]);

        $this->app->instance(
            EnvironmentSettingsUpdater::class,
            new EnvironmentSettingsUpdater($this->environmentPath),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_post_database_commit_failure_requires_recovery_and_recovery_restores_exact_destination_bootstrap(): void
    {
        $sourceKey = 'base64:'.base64_encode(str_repeat('s', 32));
        $destinationKey = 'base64:'.base64_encode(str_repeat('d', 32));
        $sourceInstallationId = str_repeat('a', 32);
        $destinationInstallationId = str_repeat('b', 32);
        $destinationFingerprint = str_repeat('d', 64);

        config([
            'app.key' => $sourceKey,
            'app.locale' => 'fa',
            'app.fallback_locale' => 'en',
            'installer.public_registration' => true,
        ]);

        $sourceOwner = User::factory()->create([
            'email' => 'source-recovery-owner@example.test',
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $sourceWorkspace = Workspace::query()->create(['name' => 'فضای منبع بازیابی']);
        Membership::query()->create([
            'workspace_id' => $sourceWorkspace->id,
            'user_id' => $sourceOwner->id,
            'role' => WorkspaceRole::Owner,
        ]);
        Project::query()->create([
            'workspace_id' => $sourceWorkspace->id,
            'name' => 'پروژه منبع بازیابی',
        ]);

        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => (new ProductVersion)->requireDistributable(),
            'installation_id' => $sourceInstallationId,
            'database_fingerprint' => str_repeat('s', 64),
            'owner_user_id' => $sourceOwner->id,
        ]);

        File::put($this->privateUploads.'/source-private.txt', 'source private durable');
        File::put($this->publicUploads.'/source-public.txt', 'source public durable');

        $handle = (new LifecycleMutex)->acquire('backup');
        try {
            $captured = $this->app->make(CaptureSystemState::class)->handle($handle);
        } finally {
            $handle->release();
        }

        $bundlePath = $this->directory.'/source-recovery.seobak';
        try {
            $captured->rewind();
            $bytes = stream_get_contents($captured->stream());
            $this->assertIsString($bytes);
            File::put($bundlePath, $bytes);
        } finally {
            $captured->close();
        }

        $this->clearDurableDatabase();
        File::deleteDirectory($this->privateUploads);
        File::deleteDirectory($this->publicUploads);
        File::ensureDirectoryExists($this->privateUploads);
        File::ensureDirectoryExists($this->publicUploads);
        File::delete($this->installationLockPath);

        config([
            'app.key' => $destinationKey,
            'app.locale' => 'de',
            'app.fallback_locale' => 'en',
            'installer.public_registration' => false,
        ]);

        $destinationOwner = User::factory()->create([
            'email' => 'destination-recovery-owner@example.test',
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $destinationWorkspace = Workspace::query()->create(['name' => 'Destination recovery bootstrap']);
        Membership::query()->create([
            'workspace_id' => $destinationWorkspace->id,
            'user_id' => $destinationOwner->id,
            'role' => WorkspaceRole::Owner,
        ]);

        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => (new ProductVersion)->requireDistributable(),
            'installation_id' => $destinationInstallationId,
            'database_fingerprint' => $destinationFingerprint,
            'owner_user_id' => $destinationOwner->id,
        ]);
        $destinationInstallationState = (new InstallationState)->details();
        $this->assertIsArray($destinationInstallationState);

        $preview = $this->app->make(VerifyRestoreBundle::class)->handle(
            new UploadedFile($bundlePath, 'source-recovery.seobak', 'application/octet-stream', null, true),
        );

        $blockedEnvironmentPath = $this->environmentPath.'.blocked';
        $this->assertTrue(File::move($this->environmentPath, $blockedEnvironmentPath));

        try {
            $this->app->make(RestoreSystemBackup::class)->handle($preview->digest());
            $this->fail('Restore unexpectedly completed despite the injected post-commit environment failure.');
        } catch (BackupException $exception) {
            $this->assertSame('restore_environment_update_failed', $exception->codeName());
        }

        $journal = new RestoreJournal($this->journalPath);
        $failedState = $journal->state();
        $this->assertIsArray($failedState);
        $this->assertSame('recovery_required', $failedState['phase']);
        $this->assertSame($preview->uploadId(), $failedState['upload_id']);

        $this->assertDatabaseHas('users', ['email' => 'source-recovery-owner@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'destination-recovery-owner@example.test']);
        $this->assertDatabaseHas('projects', ['name' => 'پروژه منبع بازیابی']);
        $this->assertSame('source private durable', File::get($this->privateUploads.'/source-private.txt'));
        $this->assertSame('source public durable', File::get($this->publicUploads.'/source-public.txt'));

        $this->assertTrue(File::move($blockedEnvironmentPath, $this->environmentPath));

        $this->app->make(RecoverInterruptedRestore::class)->handle();

        $this->assertDatabaseHas('users', ['email' => 'destination-recovery-owner@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'source-recovery-owner@example.test']);
        $this->assertDatabaseMissing('projects', ['name' => 'پروژه منبع بازیابی']);
        $this->assertSame([], File::files($this->privateUploads));
        $this->assertSame([], File::files($this->publicUploads));

        $environment = (new InstallerEnvironment($this->environmentPath))->values();
        $this->assertSame($destinationKey, $environment['APP_KEY']);
        $this->assertSame('de', $environment['APP_LOCALE']);
        $this->assertSame('en', $environment['APP_FALLBACK_LOCALE']);
        $this->assertSame('false', $environment['SELF_HOST_REGISTRATION']);
        $this->assertSame('https://destination.example.test', $environment['APP_URL']);
        $this->assertSame('/srv/destination.sqlite', $environment['DB_DATABASE']);
        $this->assertSame('destination-db-secret', $environment['DB_PASSWORD']);
        $this->assertSame('destination-mail-secret', $environment['MAIL_PASSWORD']);
        $this->assertSame('destination-external-secret', $environment['EXTERNAL_API_TOKEN']);

        $this->assertSame($destinationKey, config('app.key'));
        $this->assertSame('de', config('app.locale'));
        $this->assertFalse((bool) config('installer.public_registration'));
        $this->assertSame($destinationInstallationState, (new InstallationState)->details());

        $this->assertNull($journal->state());
        $this->assertSame([], File::files($this->restoreUploads));
        $this->assertFalse(File::exists($this->directory.'/lifecycle/restore/safety'));
        $this->assertFalse(File::exists($this->directory.'/lifecycle/restore/staging'));
    }

    private function clearDurableDatabase(): void
    {
        $catalog = $this->app->make(ApplicationTableCatalog::class);

        foreach ($catalog->deleteOrder() as $table) {
            DB::table($table)->delete();
        }
    }
}
