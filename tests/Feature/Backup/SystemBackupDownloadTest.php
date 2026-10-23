<?php

namespace Tests\Feature\Backup;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\BackupException;
use App\Services\Backup\CreateSystemBackup;
use App\Support\Backup\BackupBundleFormat;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class SystemBackupDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $privateUploads;

    private string $publicUploads;

    private string $downloadTemp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/system-backup-download-'.bin2hex(random_bytes(6)));
        $this->privateUploads = $this->directory.'/private/uploads';
        $this->publicUploads = $this->directory.'/public/uploads';
        $this->downloadTemp = $this->directory.'/lifecycle/backup';

        File::ensureDirectoryExists($this->privateUploads);
        File::ensureDirectoryExists($this->publicUploads);

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->directory.'/.installed',
            'backup.lifecycle_mutex_path' => $this->directory.'/lifecycle/lifecycle.lock',
            'backup.download_temp_path' => $this->downloadTemp,
            'backup.durable_file_roots' => [
                'storage/app/private/uploads' => $this->privateUploads,
                'storage/app/public/uploads' => $this->publicUploads,
            ],
            'app.key' => 'base64:'.base64_encode(str_repeat('b', 32)),
            'app.locale' => 'fa',
            'app.fallback_locale' => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_backup_download_requires_auth_verification_recent_password_and_exact_system_owner(): void
    {
        $this->get('/settings/backup/download')->assertRedirect('/login');

        $systemOwner = $this->member(WorkspaceRole::Owner, false);
        $this->markInstalled($systemOwner);

        $this->actingAs($systemOwner)
            ->get('/settings/backup/download')
            ->assertRedirect(route('verification.notice'));

        $systemOwner->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($systemOwner)
            ->get('/settings/backup/download')
            ->assertRedirect(route('password.confirm'));

        foreach (WorkspaceRole::cases() as $role) {
            $otherUser = $this->member($role);

            $this->actingAs($otherUser)
                ->withSession(['auth.password_confirmed_at' => time()])
                ->get('/settings/backup/download')
                ->assertForbidden();
        }
    }

    public function test_system_owner_downloads_completed_sensitive_bundle_with_private_headers_and_cleanup(): void
    {
        $systemOwner = $this->member(WorkspaceRole::Owner);
        $this->markInstalled($systemOwner);
        File::put($this->privateUploads.'/نمونه.txt', 'محتوای پایدار');

        $response = $this->actingAs($systemOwner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/settings/backup/download');

        $response->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $response->assertHeader('content-type', 'application/octet-stream');
        $cacheControl = array_map(
            'trim',
            explode(',', (string) $response->headers->get('cache-control')),
        );
        $this->assertContains('private', $cacheControl);
        $this->assertContains('no-store', $cacheControl);
        $response->assertHeader('pragma', 'no-cache');
        $response->assertHeader('x-content-type-options', 'nosniff');
        $response->assertHeader('x-seo-backup-sensitive', 'true');
        $this->assertMatchesRegularExpression(
            '/attachment; filename=seo-backup-v1-01234567-\d{8}T\d{6}Z\.seobak/',
            (string) $response->headers->get('content-disposition'),
        );

        $this->assertFalse($this->app->make(MaintenanceMode::class)->active());
        $probe = (new LifecycleMutex)->acquire('backup-download-probe');
        $probe->release();

        $body = $response->streamedContent();
        $this->assertStringStartsWith(BackupBundleFormat::MAGIC, $body);
        $this->assertStringContainsString('محتوای پایدار', $body);

        if (is_dir($this->downloadTemp)) {
            $this->assertSame([], File::allFiles($this->downloadTemp));
        }
        $this->assertSame([], File::glob($this->publicUploads.'/*.seobak'));
    }

    public function test_capture_failure_releases_mutex_leaves_maintenance_and_exposes_only_stable_backup_error(): void
    {
        $systemOwner = $this->member(WorkspaceRole::Owner);
        $this->markInstalled($systemOwner);

        $outside = $this->directory.'/outside.txt';
        File::put($outside, 'do not follow');
        if (! @symlink($outside, $this->privateUploads.'/linked.txt')) {
            $this->markTestSkipped('Filesystem does not permit symlink creation.');
        }

        try {
            $this->app->make(CreateSystemBackup::class)->handle();
            $this->fail('Backup unexpectedly succeeded with a symlink under a durable root.');
        } catch (BackupException $exception) {
            $this->assertSame('backup_integrity_failed', $exception->codeName());
            $this->assertSame('اعتبارسنجی داده‌های پشتیبان ناموفق بود.', $exception->userMessage());
        }

        $this->assertFalse($this->app->make(MaintenanceMode::class)->active());

        $probe = (new LifecycleMutex)->acquire('backup-failure-probe');
        $probe->release();
    }

    private function member(WorkspaceRole $role, bool $verified = true): User
    {
        $user = User::factory()->create([
            'email_verified_at' => $verified ? now() : null,
            'onboarded_at' => now(),
        ]);
        $workspace = Workspace::query()->create(['name' => $role->value.'-'.bin2hex(random_bytes(3))]);

        Membership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function markInstalled(User $owner): void
    {
        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => (new ProductVersion)->requireDistributable(),
            'installation_id' => '0123456789abcdef0123456789abcdef',
            'database_fingerprint' => str_repeat('d', 64),
            'owner_user_id' => $owner->id,
        ]);
    }
}
