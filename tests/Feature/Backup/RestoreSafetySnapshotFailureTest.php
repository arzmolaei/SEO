<?php

namespace Tests\Feature\Backup;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\BackupException;
use App\Services\Backup\RestoreJournal;
use App\Services\Backup\RestorePreview;
use App\Services\Backup\RestoreSystemBackup;
use App\Support\InstallationState;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RestoreSafetySnapshotFailureTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $restoreUploads;

    private string $journalPath;

    private string $installationLockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/restore-snapshot-failure-'.bin2hex(random_bytes(6)));
        $this->restoreUploads = $this->directory.'/lifecycle/restore/uploads';
        $this->journalPath = $this->directory.'/lifecycle/restore/state.json';
        $this->installationLockPath = $this->directory.'/.installed';

        File::ensureDirectoryExists($this->restoreUploads);

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->installationLockPath,
            'backup.lifecycle_mutex_path' => $this->directory.'/lifecycle/lifecycle.lock',
            'backup.restore.upload_path' => $this->restoreUploads,
            'backup.restore.journal_path' => $this->journalPath,
            'backup.durable_file_roots' => [
                'storage/app/private/uploads' => $this->directory.'/private/uploads',
                'storage/app/public/uploads' => $this->directory.'/public/uploads',
            ],
            'app.key' => 'base64:'.base64_encode(str_repeat('d', 32)),
        ]);

        File::ensureDirectoryExists($this->directory.'/private/uploads');
        File::ensureDirectoryExists($this->directory.'/public/uploads');
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_safety_snapshot_failure_before_mutation_keeps_verified_state_and_uploaded_bundle(): void
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $workspace = Workspace::query()->create(['name' => 'Bootstrap']);
        Membership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => WorkspaceRole::Owner,
        ]);

        $productVersion = (new ProductVersion)->requireDistributable();
        $installationState = new InstallationState;
        $installationState->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => $productVersion,
            'installation_id' => str_repeat('b', 32),
            'database_fingerprint' => str_repeat('d', 64),
            'owner_user_id' => $owner->id,
        ]);

        $uploadId = str_repeat('a', 32);
        $digest = str_repeat('c', 64);
        $uploadPath = $this->restoreUploads.'/'.$uploadId.'.seobak';
        File::put($uploadPath, 'verified bundle placeholder');

        $journal = new RestoreJournal($this->journalPath);
        $journal->startUpload($uploadId);
        $journal->markVerified(new RestorePreview(
            uploadId: $uploadId,
            digest: $digest,
            sourceInstallationId: str_repeat('e', 32),
            sourceCreatedAtUtc: '2026-10-23T10:00:00Z',
            sourceProductVersion: $productVersion,
            tableRecordCount: 0,
            durableFileCount: 0,
            bundleBytes: File::size($uploadPath),
        ));

        $beforeUsers = User::query()->count();
        $beforeWorkspaces = Workspace::query()->count();
        $beforeMemberships = Membership::query()->count();

        $installationState->updateMetadata([
            'product_version' => '0.0.0-snapshot-failure',
        ]);

        try {
            $this->app->make(RestoreSystemBackup::class)->handle($digest);
            $this->fail('Restore unexpectedly continued after safety snapshot capture failed.');
        } catch (BackupException $exception) {
            $this->assertSame('backup_integrity_failed', $exception->codeName());
        }

        $state = $journal->state();
        $this->assertIsArray($state);
        $this->assertSame('verified', $state['phase']);
        $this->assertSame($uploadId, $state['upload_id']);
        $this->assertSame($digest, $state['preview']['digest']);

        $this->assertFileExists($uploadPath);
        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertSame($beforeWorkspaces, Workspace::query()->count());
        $this->assertSame($beforeMemberships, Membership::query()->count());
        $this->assertFalse(File::exists($this->directory.'/lifecycle/restore/safety/'.$uploadId.'.seobak'));
    }
}
