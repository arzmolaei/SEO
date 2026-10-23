<?php

namespace Tests\Feature\Backup;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\ApplicationTableCatalog;
use App\Services\Backup\ApplySystemStateSnapshot;
use App\Services\Backup\BackupException;
use App\Services\Backup\CaptureSystemState;
use App\Services\Backup\EnvironmentSettingsUpdater;
use App\Support\InstallationState;
use App\Support\Installer\InstallerEnvironment;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApplySystemStateSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $environmentPath;

    private string $privateUploads;

    private string $publicUploads;

    private string $installationLockPath;

    private string $bundlePath;

    private string $metadataPath;

    private string $stagingRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/apply-system-state-snapshot-'.bin2hex(random_bytes(6)));
        $this->environmentPath = $this->directory.'/.env';
        $this->privateUploads = $this->directory.'/private/uploads';
        $this->publicUploads = $this->directory.'/public/uploads';
        $this->installationLockPath = $this->directory.'/.installed';
        $this->bundlePath = $this->directory.'/safety.seobak';
        $this->metadataPath = $this->directory.'/safety.json';
        $this->stagingRoot = $this->directory.'/staging';

        File::ensureDirectoryExists($this->privateUploads);
        File::ensureDirectoryExists($this->publicUploads);

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->installationLockPath,
            'installer.public_registration' => false,
            'backup.lifecycle_mutex_path' => $this->directory.'/lifecycle/lifecycle.lock',
            'backup.durable_file_roots' => [
                'storage/app/private/uploads' => $this->privateUploads,
                'storage/app/public/uploads' => $this->publicUploads,
            ],
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

    public function test_kernel_restores_exact_system_state_under_existing_lifecycle_ownership(): void
    {
        $snapshotKey = 'base64:'.base64_encode(str_repeat('s', 32));
        $mutatedKey = 'base64:'.base64_encode(str_repeat('m', 32));
        $snapshotInstallationId = str_repeat('a', 32);
        $snapshotFingerprint = str_repeat('b', 64);

        $snapshotOwner = $this->seedSystemState(
            ownerEmail: 'snapshot-owner@example.test',
            workspaceName: 'فضای امن قبل از عملیات',
            projectName: 'پروژه امن قبل از عملیات',
            installationId: $snapshotInstallationId,
            databaseFingerprint: $snapshotFingerprint,
            appKey: $snapshotKey,
            locale: 'fa',
            registration: true,
            privateContents: 'snapshot private durable',
            publicContents: 'snapshot public durable',
        );

        $snapshotDetails = (new InstallationState)->details();
        $this->assertIsArray($snapshotDetails);
        $this->captureSnapshot($snapshotDetails);

        $this->clearDurableDatabase();
        File::deleteDirectory($this->privateUploads);
        File::deleteDirectory($this->publicUploads);
        File::ensureDirectoryExists($this->privateUploads);
        File::ensureDirectoryExists($this->publicUploads);
        File::delete($this->installationLockPath);

        $mutatedOwner = $this->seedSystemState(
            ownerEmail: 'mutated-owner@example.test',
            workspaceName: 'Mutated workspace',
            projectName: 'Mutated project',
            installationId: str_repeat('c', 32),
            databaseFingerprint: str_repeat('d', 64),
            appKey: $mutatedKey,
            locale: 'de',
            registration: false,
            privateContents: 'mutated private durable',
            publicContents: 'mutated public durable',
        );

        $ownership = (new LifecycleMutex)->acquire('snapshot-apply');
        try {
            $this->app->make(ApplySystemStateSnapshot::class)->handle(
                $this->bundlePath,
                $this->metadataPath,
                $this->stagingRoot,
                $ownership,
            );

            $ownership->assertActive();
        } finally {
            $ownership->release();
        }

        $this->assertDatabaseHas('users', [
            'id' => $snapshotOwner->id,
            'email' => 'snapshot-owner@example.test',
        ]);
        $this->assertDatabaseMissing('users', [
            'id' => $mutatedOwner->id,
            'email' => 'mutated-owner@example.test',
        ]);
        $this->assertDatabaseHas('projects', ['name' => 'پروژه امن قبل از عملیات']);
        $this->assertDatabaseMissing('projects', ['name' => 'Mutated project']);

        $this->assertSame('snapshot private durable', File::get($this->privateUploads.'/state.txt'));
        $this->assertSame('snapshot public durable', File::get($this->publicUploads.'/state.txt'));

        $environment = (new InstallerEnvironment($this->environmentPath))->values();
        $this->assertSame($snapshotKey, $environment['APP_KEY']);
        $this->assertSame('fa', $environment['APP_LOCALE']);
        $this->assertSame('en', $environment['APP_FALLBACK_LOCALE']);
        $this->assertSame('true', $environment['SELF_HOST_REGISTRATION']);
        $this->assertSame('https://local.example.test', $environment['APP_URL']);
        $this->assertSame('local-db-secret', $environment['DB_PASSWORD']);
        $this->assertSame('local-mail-secret', $environment['MAIL_PASSWORD']);
        $this->assertSame('local-external-secret', $environment['EXTERNAL_API_TOKEN']);

        $this->assertSame($snapshotKey, config('app.key'));
        $this->assertSame('fa', config('app.locale'));
        $this->assertTrue((bool) config('installer.public_registration'));
        $this->assertSame($snapshotDetails, (new InstallationState)->details());

        $this->assertFileExists($this->bundlePath);
        $this->assertFileExists($this->metadataPath);
        $this->assertDirectoryExists($this->stagingRoot);
    }

    public function test_kernel_rejects_snapshot_from_mismatched_product_version_before_mutation(): void
    {
        $details = $this->seedAndCaptureBaselineSnapshot();
        $beforeUserCount = User::query()->count();
        $fakeVersionPath = $this->directory.'/VERSION-mismatch';
        File::put($fakeVersionPath, "9.9.9\n");
        $this->app->instance(ProductVersion::class, new ProductVersion($fakeVersionPath));

        $ownership = (new LifecycleMutex)->acquire('snapshot-version-check');
        try {
            try {
                $this->app->make(ApplySystemStateSnapshot::class)->handle(
                    $this->bundlePath,
                    $this->metadataPath,
                    $this->stagingRoot,
                    $ownership,
                );
                $this->fail('Mismatched product version should reject the safety snapshot.');
            } catch (BackupException $exception) {
                $this->assertSame('restore_incompatible_schema', $exception->codeName());
            }

            $ownership->assertActive();
        } finally {
            $ownership->release();
        }

        $this->assertSame($beforeUserCount, User::query()->count());
        $this->assertSame($details, (new InstallationState)->details());
    }

    public function test_kernel_rejects_schema_mismatch_before_mutation(): void
    {
        $details = $this->seedAndCaptureBaselineSnapshot();
        $beforeUserCount = User::query()->count();

        Schema::table('users', function (Blueprint $table): void {
            $table->string('snapshot_schema_drift')->nullable();
        });

        $ownership = (new LifecycleMutex)->acquire('snapshot-schema-check');
        try {
            try {
                $this->app->make(ApplySystemStateSnapshot::class)->handle(
                    $this->bundlePath,
                    $this->metadataPath,
                    $this->stagingRoot,
                    $ownership,
                );
                $this->fail('Mismatched application schema should reject the safety snapshot.');
            } catch (BackupException $exception) {
                $this->assertSame('restore_incompatible_schema', $exception->codeName());
            }

            $ownership->assertActive();
        } finally {
            $ownership->release();
        }

        $this->assertSame($beforeUserCount, User::query()->count());
        $this->assertSame($details, (new InstallationState)->details());
    }

    /** @return array<string, mixed> */
    private function seedAndCaptureBaselineSnapshot(): array
    {
        $this->seedSystemState(
            ownerEmail: 'baseline-owner@example.test',
            workspaceName: 'Baseline workspace',
            projectName: 'Baseline project',
            installationId: str_repeat('e', 32),
            databaseFingerprint: str_repeat('f', 64),
            appKey: 'base64:'.base64_encode(str_repeat('b', 32)),
            locale: 'fa',
            registration: false,
            privateContents: 'baseline private durable',
            publicContents: 'baseline public durable',
        );

        $details = (new InstallationState)->details();
        $this->assertIsArray($details);
        $this->captureSnapshot($details);

        return $details;
    }

    private function seedSystemState(
        string $ownerEmail,
        string $workspaceName,
        string $projectName,
        string $installationId,
        string $databaseFingerprint,
        string $appKey,
        string $locale,
        bool $registration,
        string $privateContents,
        string $publicContents,
    ): User {
        config([
            'app.key' => $appKey,
            'app.locale' => $locale,
            'app.fallback_locale' => 'en',
            'installer.public_registration' => $registration,
        ]);

        File::put($this->environmentPath, implode("\n", [
            'APP_KEY='.$appKey,
            'APP_LOCALE='.$locale,
            'APP_FALLBACK_LOCALE=en',
            'SELF_HOST_REGISTRATION='.($registration ? 'true' : 'false'),
            'APP_URL=https://local.example.test',
            'DB_CONNECTION=sqlite',
            'DB_DATABASE=/srv/local.sqlite',
            'DB_PASSWORD=local-db-secret',
            'MAIL_PASSWORD=local-mail-secret',
            'FILESYSTEM_DISK=local',
            'EXTERNAL_API_TOKEN=local-external-secret',
            '',
        ]));

        $owner = User::factory()->create([
            'email' => $ownerEmail,
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $workspace = Workspace::query()->create(['name' => $workspaceName]);
        Membership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => WorkspaceRole::Owner,
        ]);
        Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $projectName,
        ]);

        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => (new ProductVersion)->requireDistributable(),
            'installation_id' => $installationId,
            'database_fingerprint' => $databaseFingerprint,
            'owner_user_id' => $owner->id,
        ]);

        File::put($this->privateUploads.'/state.txt', $privateContents);
        File::put($this->publicUploads.'/state.txt', $publicContents);

        return $owner;
    }

    /** @param array<string, mixed> $installationDetails */
    private function captureSnapshot(array $installationDetails): void
    {
        $ownership = (new LifecycleMutex)->acquire('snapshot-capture');
        try {
            $captured = $this->app->make(CaptureSystemState::class)->handle($ownership);
        } finally {
            $ownership->release();
        }

        try {
            $captured->rewind();
            $bytes = stream_get_contents($captured->stream());
            $this->assertIsString($bytes);
            File::put($this->bundlePath, $bytes);
            @chmod($this->bundlePath, 0600);
        } finally {
            $captured->close();
        }

        File::put(
            $this->metadataPath,
            json_encode(
                $installationDetails,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
        @chmod($this->metadataPath, 0600);
    }

    private function clearDurableDatabase(): void
    {
        $catalog = $this->app->make(ApplicationTableCatalog::class);

        foreach ($catalog->deleteOrder() as $table) {
            DB::table($table)->delete();
        }
    }
}
