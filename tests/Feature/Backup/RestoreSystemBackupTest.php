<?php

namespace Tests\Feature\Backup;

use App\Enums\KeywordStatus;
use App\Enums\WorkspaceRole;
use App\Models\Keyword;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\ApplicationTableCatalog;
use App\Services\Backup\BackupException;
use App\Services\Backup\CaptureSystemState;
use App\Services\Backup\EnvironmentSettingsUpdater;
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

class RestoreSystemBackupTest extends TestCase
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

        $this->directory = storage_path('framework/testing/restore-system-backup-'.bin2hex(random_bytes(6)));
        $this->environmentPath = $this->directory.'/.env';
        $this->privateUploads = $this->directory.'/private/uploads';
        $this->publicUploads = $this->directory.'/public/uploads';
        $this->restoreUploads = $this->directory.'/lifecycle/restore/uploads';
        $this->journalPath = $this->directory.'/lifecycle/restore/state.json';
        $this->installationLockPath = $this->directory.'/.installed';
        $destinationKey = 'base64:'.base64_encode(str_repeat('d', 32));

        File::ensureDirectoryExists($this->privateUploads);
        File::ensureDirectoryExists($this->publicUploads);
        File::ensureDirectoryExists($this->restoreUploads);

        File::put($this->environmentPath, implode("\n", [
            '# destination environment',
            "APP_KEY={$destinationKey}",
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
            'app.key' => $destinationKey,
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

    public function test_restore_confirm_and_recovery_endpoints_require_authentication(): void
    {
        (new InstallationState)->markInstalled();

        $this->post('/settings/backup/restore/confirm')->assertRedirect('/login');
        $this->post('/settings/backup/restore/recover')->assertRedirect('/login');
    }

    public function test_environment_updater_changes_only_restore_allowlist_and_refreshes_runtime_config(): void
    {
        $sourceKey = 'base64:'.base64_encode(str_repeat('s', 32));

        (new EnvironmentSettingsUpdater($this->environmentPath))->apply([
            'APP_KEY' => $sourceKey,
            'APP_LOCALE' => 'fa',
            'APP_FALLBACK_LOCALE' => 'en',
            'SELF_HOST_REGISTRATION' => true,
        ]);

        $values = (new InstallerEnvironment($this->environmentPath))->values();

        $this->assertSame($sourceKey, $values['APP_KEY']);
        $this->assertSame('fa', $values['APP_LOCALE']);
        $this->assertSame('en', $values['APP_FALLBACK_LOCALE']);
        $this->assertSame('true', $values['SELF_HOST_REGISTRATION']);
        $this->assertSame('https://destination.example.test', $values['APP_URL']);
        $this->assertSame('sqlite', $values['DB_CONNECTION']);
        $this->assertSame('/srv/destination.sqlite', $values['DB_DATABASE']);
        $this->assertSame('destination-db-secret', $values['DB_PASSWORD']);
        $this->assertSame('destination-mail-secret', $values['MAIL_PASSWORD']);
        $this->assertSame('local', $values['FILESYSTEM_DISK']);
        $this->assertSame('destination-external-secret', $values['EXTERNAL_API_TOKEN']);

        $contents = (string) File::get($this->environmentPath);
        $this->assertStringContainsString('# destination environment', $contents);
        $this->assertFileDoesNotExist($this->environmentPath.'.tmp');

        $this->assertSame($sourceKey, config('app.key'));
        $this->assertSame('fa', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
        $this->assertTrue((bool) config('installer.public_registration'));
    }

    public function test_restore_round_trip_replaces_durable_state_but_preserves_destination_identity_and_infrastructure_secrets(): void
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
            'email' => 'source-owner@example.test',
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $sourceWorkspace = Workspace::query()->create(['name' => 'فضای منبع']);
        Membership::query()->create([
            'workspace_id' => $sourceWorkspace->id,
            'user_id' => $sourceOwner->id,
            'role' => WorkspaceRole::Owner,
        ]);
        $sourceProject = Project::query()->create([
            'workspace_id' => $sourceWorkspace->id,
            'name' => 'پروژه بازیابی',
        ]);
        Keyword::query()->create([
            'project_id' => $sourceProject->id,
            'display_text' => 'کلمه بازیابی',
            'normalized_text' => 'کلمه بازیابی',
            'status' => KeywordStatus::Ready,
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

        $bundlePath = $this->directory.'/source.seobak';
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
            'email' => 'destination-owner@example.test',
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $destinationWorkspace = Workspace::query()->create(['name' => 'Destination bootstrap']);
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

        $preview = $this->app->make(VerifyRestoreBundle::class)->handle(
            new UploadedFile($bundlePath, 'source.seobak', 'application/octet-stream', null, true),
        );

        $wrongDigest = str_repeat('f', 64);
        $this->actingAs($destinationOwner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/settings/backup/restore/confirm', [
                'content_digest_sha256' => $wrongDigest,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'restore_digest_mismatch');
        $this->assertAuthenticatedAs($destinationOwner);

        $response = $this->actingAs($destinationOwner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/settings/backup/restore/confirm', [
                'content_digest_sha256' => $preview->digest(),
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('login_required', true)
            ->assertHeader('pragma', 'no-cache')
            ->assertHeader('x-content-type-options', 'nosniff');
        $cacheControl = array_map(
            'trim',
            explode(',', (string) $response->headers->get('cache-control')),
        );
        $this->assertContains('no-cache', $cacheControl);
        $this->assertContains('private', $cacheControl);
        $this->assertContains('no-store', $cacheControl);
        $this->assertGuest();

        $this->assertDatabaseHas('users', ['email' => 'source-owner@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'destination-owner@example.test']);
        $this->assertDatabaseHas('projects', ['name' => 'پروژه بازیابی']);
        $this->assertDatabaseHas('keywords', ['display_text' => 'کلمه بازیابی']);
        $this->assertSame('source private durable', File::get($this->privateUploads.'/source-private.txt'));
        $this->assertSame('source public durable', File::get($this->publicUploads.'/source-public.txt'));

        $environment = (new InstallerEnvironment($this->environmentPath))->values();
        $this->assertSame($sourceKey, $environment['APP_KEY']);
        $this->assertSame('fa', $environment['APP_LOCALE']);
        $this->assertSame('en', $environment['APP_FALLBACK_LOCALE']);
        $this->assertSame('true', $environment['SELF_HOST_REGISTRATION']);
        $this->assertSame('https://destination.example.test', $environment['APP_URL']);
        $this->assertSame('sqlite', $environment['DB_CONNECTION']);
        $this->assertSame('/srv/destination.sqlite', $environment['DB_DATABASE']);
        $this->assertSame('destination-db-secret', $environment['DB_PASSWORD']);
        $this->assertSame('destination-mail-secret', $environment['MAIL_PASSWORD']);
        $this->assertSame('local', $environment['FILESYSTEM_DISK']);
        $this->assertSame('destination-external-secret', $environment['EXTERNAL_API_TOKEN']);

        $details = (new InstallationState)->details();
        $this->assertIsArray($details);
        $this->assertSame($destinationInstallationId, $details['installation_id']);
        $this->assertSame($destinationFingerprint, $details['database_fingerprint']);
        $this->assertSame($sourceOwner->id, $details['owner_user_id']);
        $this->assertSame($sourceInstallationId, $details['restored_from_installation_id']);
        $this->assertIsString($details['restored_at']);
        $this->assertNotSame('', $details['restored_at']);

        try {
            $this->app->make(RestoreSystemBackup::class)->handle($preview->digest());
            $this->fail('A second restore unexpectedly succeeded.');
        } catch (BackupException $exception) {
            $this->assertSame('restore_digest_mismatch', $exception->codeName());
        }

        $restoredOwner = User::query()->where('email', 'source-owner@example.test')->firstOrFail();
        $this->actingAs($restoredOwner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/settings/backup/restore/recover')
            ->assertStatus(422)
            ->assertJsonPath('code', 'restore_recovery_invalid_state');
    }

    private function clearDurableDatabase(): void
    {
        $catalog = $this->app->make(ApplicationTableCatalog::class);

        foreach ($catalog->deleteOrder() as $table) {
            DB::table($table)->delete();
        }
    }
}
