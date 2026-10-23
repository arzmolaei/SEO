<?php

namespace Tests\Feature\Backup;

use App\Enums\KeywordStatus;
use App\Enums\WorkspaceRole;
use App\Models\Keyword;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\BackupException;
use App\Services\Backup\CaptureSystemState;
use App\Support\Backup\BackupBundleReader;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CaptureSystemStateTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $privateUploads;

    private string $publicUploads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/backup-capture-'.bin2hex(random_bytes(6)));
        $this->privateUploads = $this->directory.'/private/uploads';
        $this->publicUploads = $this->directory.'/public/uploads';
        File::ensureDirectoryExists($this->privateUploads);
        File::ensureDirectoryExists($this->publicUploads);

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->directory.'/.installed',
            'installer.public_registration' => true,
            'backup.lifecycle_mutex_path' => $this->directory.'/lifecycle/lifecycle.lock',
            'backup.durable_file_roots' => [
                'storage/app/private/uploads' => $this->privateUploads,
                'storage/app/public/uploads' => $this->publicUploads,
            ],
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.locale' => 'fa',
            'app.fallback_locale' => 'en',
            'database.connections.sqlite.password' => 'database-secret-must-not-leak',
            'mail.mailers.smtp.password' => 'mail-secret-must-not-leak',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_capture_contains_durable_database_settings_and_files_but_excludes_volatile_and_credentials(): void
    {
        $owner = User::factory()->create(['name' => 'مالک']);
        $workspace = Workspace::query()->create(['name' => 'فضای فارسی']);
        Membership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => WorkspaceRole::Owner,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'پروژه نمونه',
        ]);
        Keyword::query()->create([
            'project_id' => $project->id,
            'display_text' => 'کلمه‌ی کلیدی',
            'normalized_text' => 'کلمه‌ی کلیدی',
            'status' => KeywordStatus::Ready,
        ]);

        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => '0.1.0-alpha.1',
            'installation_id' => '0123456789abcdef0123456789abcdef',
            'database_fingerprint' => str_repeat('d', 64),
            'owner_user_id' => $owner->id,
        ]);

        File::put($this->privateUploads.'/persian.txt', 'فایل پایدار');
        File::put($this->publicUploads.'/public.txt', 'public durable');

        $handle = (new LifecycleMutex)->acquire('backup');

        try {
            $captured = $this->app->make(CaptureSystemState::class)->handle($handle);
        } finally {
            $handle->release();
        }

        try {
            $this->assertSame(0, DB::connection()->transactionLevel());
            $captured->rewind();
            $entries = [];
            $manifest = (new BackupBundleReader)->read(
                $captured->stream(),
                function (string $path, string $chunk) use (&$entries): void {
                    $entries[$path] = ($entries[$path] ?? '').$chunk;
                },
            );

            $this->assertSame($captured->manifest()->canonicalJson(), $manifest->canonicalJson());
            $this->assertArrayHasKey('database/users.ndjson', $entries);
            $this->assertArrayHasKey('database/keywords.ndjson', $entries);
            $this->assertStringContainsString('کلمه‌ی کلیدی', $entries['database/keywords.ndjson']);
            $this->assertArrayNotHasKey('database/sessions.ndjson', $entries);
            $this->assertArrayNotHasKey('database/jobs.ndjson', $entries);

            $runtime = json_decode($entries['settings/runtime.json'], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(config('app.key'), $runtime['APP_KEY']);
            $this->assertSame('fa', $runtime['APP_LOCALE']);
            $this->assertSame('en', $runtime['APP_FALLBACK_LOCALE']);
            $this->assertTrue($runtime['SELF_HOST_REGISTRATION']);
            $this->assertStringNotContainsString('database-secret-must-not-leak', $entries['settings/runtime.json']);
            $this->assertStringNotContainsString('mail-secret-must-not-leak', $entries['settings/runtime.json']);

            $this->assertSame('فایل پایدار', $entries['storage/app/private/uploads/persian.txt']);
            $this->assertSame('public durable', $entries['storage/app/public/uploads/public.txt']);
        } finally {
            $captured->close();
            $captured->close();
        }
    }

    public function test_capture_fails_closed_on_symlink_and_leaves_no_open_database_transaction(): void
    {
        $owner = User::factory()->create();
        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => '0.1.0-alpha.1',
            'installation_id' => '0123456789abcdef0123456789abcdef',
            'database_fingerprint' => str_repeat('d', 64),
            'owner_user_id' => $owner->id,
        ]);

        $outside = $this->directory.'/outside.txt';
        File::put($outside, 'do not archive through symlink');
        if (! @symlink($outside, $this->privateUploads.'/linked.txt')) {
            $this->markTestSkipped('Filesystem does not permit symlink creation.');
        }

        $handle = (new LifecycleMutex)->acquire('backup');

        try {
            try {
                $this->app->make(CaptureSystemState::class)->handle($handle);
                $this->fail('Capture followed or ignored a symlink under a durable root.');
            } catch (BackupException $exception) {
                $this->assertSame('backup_integrity_failed', $exception->codeName());
            }
        } finally {
            $handle->release();
        }

        $this->assertSame(0, DB::connection()->transactionLevel());
    }
}
