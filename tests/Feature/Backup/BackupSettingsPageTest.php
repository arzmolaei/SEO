<?php

namespace Tests\Feature\Backup;

use App\Models\User;
use App\Support\InstallationState;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BackupSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $journalPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/backup-settings-page-'.bin2hex(random_bytes(6)));
        $this->journalPath = $this->directory.'/lifecycle/restore/state.json';

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->directory.'/.installed',
            'backup.restore.journal_path' => $this->journalPath,
            'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_only_system_owner_can_open_backup_restore_settings_page(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);
        $this->markInstalled($owner);

        $this->actingAs($otherUser)
            ->get('/settings/backup')
            ->assertForbidden();

        $this->actingAs($owner)
            ->get('/settings/backup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/backup-restore')
                ->where('restore.phase', 'idle')
                ->where('restore.recoveryRequired', false)
                ->where('restore.preview', null),
            );
    }

    public function test_settings_navigation_exposes_backup_capability_only_for_system_owner(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);
        $this->markInstalled($owner);

        $this->actingAs($owner)
            ->get('/settings/profile')
            ->assertInertia(fn (Assert $page) => $page
                ->where('systemBackup.canManage', true),
            );

        $this->actingAs($otherUser)
            ->get('/settings/profile')
            ->assertInertia(fn (Assert $page) => $page
                ->where('systemBackup.canManage', false),
            );
    }

    public function test_recovery_required_page_props_are_server_derived_and_do_not_expose_sensitive_journal_evidence(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $this->markInstalled($owner);

        File::ensureDirectoryExists(dirname($this->journalPath));
        File::put($this->journalPath, json_encode([
            'phase' => 'recovery_required',
            'upload_id' => str_repeat('a', 32),
            'safety_snapshot_path' => '/srv/private/recovery/snapshot.seobak',
            'credential' => 'must-not-leak',
            'preview' => [
                'digest' => str_repeat('b', 64),
                'source_installation_id' => str_repeat('c', 32),
                'source_created_at_utc' => '2026-10-23T12:00:00Z',
                'source_product_version' => '1.0.0',
                'table_record_count' => 12,
                'durable_file_count' => 3,
                'bundle_bytes' => 4096,
            ],
        ], JSON_THROW_ON_ERROR));

        $this->actingAs($owner)
            ->get('/settings/backup')
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/backup-restore')
                ->where('restore.phase', 'recovery_required')
                ->where('restore.recoveryRequired', true)
                ->where('restore.preview', null)
                ->missing('restore.upload_id')
                ->missing('restore.safety_snapshot_path')
                ->missing('restore.credential'),
            );
    }

    private function markInstalled(User $owner): void
    {
        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => (new ProductVersion)->requireDistributable(),
            'installation_id' => '1234567890abcdef1234567890abcdef',
            'database_fingerprint' => str_repeat('f', 64),
            'owner_user_id' => $owner->id,
        ]);
    }
}
