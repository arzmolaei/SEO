<?php

namespace Tests\Feature\Backup;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\BackupException;
use App\Services\Backup\CleanRestoreTarget;
use App\Services\Backup\RestoreJournal;
use App\Services\Backup\RestorePreview;
use App\Support\InstallationState;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CleanRestoreTargetTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $privateUploads;

    private string $publicUploads;

    private string $journalPath;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/clean-restore-target-'.bin2hex(random_bytes(6)));
        $this->privateUploads = $this->directory.'/private/uploads';
        $this->publicUploads = $this->directory.'/public/uploads';
        $this->journalPath = $this->directory.'/lifecycle/restore/state.json';

        File::ensureDirectoryExists($this->privateUploads);
        File::ensureDirectoryExists($this->publicUploads);

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->directory.'/.installed',
            'backup.lifecycle_mutex_path' => $this->directory.'/lifecycle/lifecycle.lock',
            'backup.restore.journal_path' => $this->journalPath,
            'backup.durable_file_roots' => [
                'storage/app/private/uploads' => $this->privateUploads,
                'storage/app/public/uploads' => $this->publicUploads,
            ],
        ]);

        $this->owner = User::factory()->create([
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $this->workspace = Workspace::query()->create(['name' => 'Bootstrap']);
        Membership::query()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'role' => WorkspaceRole::Owner,
        ]);

        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => (new ProductVersion)->requireDistributable(),
            'installation_id' => str_repeat('1', 32),
            'database_fingerprint' => str_repeat('2', 64),
            'owner_user_id' => $this->owner->id,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_clean_installer_bootstrap_is_restore_ready_for_its_current_upload(): void
    {
        $uploadId = str_repeat('a', 32);
        (new RestoreJournal($this->journalPath))->startUpload($uploadId);

        $this->app->make(CleanRestoreTarget::class)->assertReady($uploadId);

        $this->addToAssertionCount(1);
    }

    public function test_domain_data_is_not_restore_ready(): void
    {
        Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Already populated',
        ]);

        $this->assertNotClean();
    }

    public function test_prior_restore_is_not_restore_ready(): void
    {
        (new InstallationState)->updateMetadata([
            'restored_from_installation_id' => str_repeat('3', 32),
            'restored_at' => '2026-10-23T10:00:00Z',
        ]);

        $this->assertNotClean();
    }

    public function test_unknown_durable_file_is_not_restore_ready(): void
    {
        File::put($this->privateUploads.'/existing.txt', 'existing');

        $this->assertNotClean();
    }

    public function test_recovery_required_state_is_not_restore_ready(): void
    {
        $uploadId = str_repeat('a', 32);
        $journal = new RestoreJournal($this->journalPath);
        $journal->startUpload($uploadId);
        $journal->markVerified(new RestorePreview(
            uploadId: $uploadId,
            digest: str_repeat('b', 64),
            sourceInstallationId: str_repeat('c', 32),
            sourceCreatedAtUtc: '2026-10-23T10:00:00Z',
            sourceProductVersion: '0.1.0-alpha.1',
            tableRecordCount: 0,
            durableFileCount: 0,
            bundleBytes: 100,
        ));
        $journal->transition('verified', 'confirmed');
        $journal->transition('confirmed', 'database_restoring');
        $journal->transition('database_restoring', 'recovery_required');

        $this->assertNotClean($uploadId);
    }

    private function assertNotClean(string $uploadId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): void
    {
        try {
            $this->app->make(CleanRestoreTarget::class)->assertReady($uploadId);
            $this->fail('Clean-target gate unexpectedly accepted a non-clean destination.');
        } catch (BackupException $exception) {
            $this->assertSame('restore_target_not_clean', $exception->codeName());
        }
    }
}
