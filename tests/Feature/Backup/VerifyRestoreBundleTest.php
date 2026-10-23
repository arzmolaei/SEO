<?php

namespace Tests\Feature\Backup;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\ApplicationTableCatalog;
use App\Services\Backup\BackupException;
use App\Services\Backup\RestoreJournal;
use App\Services\Backup\VerifyRestoreBundle;
use App\Support\Backup\BackupBundleWriter;
use App\Support\InstallationState;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VerifyRestoreBundleTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $restoreUploads;

    private string $journalPath;

    private string $privateUploads;

    private string $publicUploads;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/verify-restore-bundle-'.bin2hex(random_bytes(6)));
        $this->restoreUploads = $this->directory.'/lifecycle/restore/uploads';
        $this->journalPath = $this->directory.'/lifecycle/restore/state.json';
        $this->privateUploads = $this->directory.'/private/uploads';
        $this->publicUploads = $this->directory.'/public/uploads';

        File::ensureDirectoryExists($this->restoreUploads);
        File::ensureDirectoryExists($this->privateUploads);
        File::ensureDirectoryExists($this->publicUploads);

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->directory.'/.installed',
            'backup.lifecycle_mutex_path' => $this->directory.'/lifecycle/lifecycle.lock',
            'backup.restore.upload_path' => $this->restoreUploads,
            'backup.restore.journal_path' => $this->journalPath,
            'backup.durable_file_roots' => [
                'storage/app/private/uploads' => $this->privateUploads,
                'storage/app/public/uploads' => $this->publicUploads,
            ],
            'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
        ]);

        $this->owner = User::factory()->create([
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $workspace = Workspace::query()->create(['name' => 'Bootstrap']);
        Membership::query()->create([
            'workspace_id' => $workspace->id,
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

    public function test_valid_bundle_is_fully_verified_and_persists_only_non_secret_preview_evidence(): void
    {
        $bundlePath = $this->makeBundle();

        $preview = $this->app->make(VerifyRestoreBundle::class)->handle(
            new UploadedFile($bundlePath, 'backup.seobak', 'application/octet-stream', null, true),
        );

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $preview->uploadId());
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $preview->digest());
        $this->assertSame(str_repeat('3', 32), $preview->sourceInstallationId());
        $this->assertSame('0.1.0-alpha.1', $preview->sourceProductVersion());
        $this->assertSame(0, $preview->tableRecordCount());
        $this->assertSame(0, $preview->durableFileCount());

        $journal = new RestoreJournal($this->journalPath);
        $state = $journal->state();
        $this->assertIsArray($state);
        $this->assertSame('verified', $state['phase']);
        $this->assertSame($preview->toArray(), $state['preview']);

        $stored = $this->restoreUploads.'/'.$preview->uploadId().'.seobak';
        $this->assertFileExists($stored);
        $this->assertSame(0600, fileperms($stored) & 0777);
        $journalRaw = (string) file_get_contents($this->journalPath);
        $this->assertStringNotContainsString('APP_KEY', $journalRaw);
        $this->assertStringNotContainsString('base64:', $journalRaw);
    }

    public function test_invalid_magic_checksum_schema_version_fingerprint_catalog_path_and_trailing_bytes_fail_before_db_mutation(): void
    {
        $cases = [
            'magic' => function (string $path): void {
                $bytes = (string) file_get_contents($path);
                $bytes[0] = 'X';
                file_put_contents($path, $bytes);
            },
            'checksum' => function (string $path): void {
                $bytes = (string) file_get_contents($path);
                $last = strlen($bytes) - 1;
                $bytes[$last] = $bytes[$last] === 'x' ? 'y' : 'x';
                file_put_contents($path, $bytes);
            },
            'schema' => function (string $path): void {
                $bytes = (string) file_get_contents($path);
                $this->assertStringContainsString('seo-backup/1', $bytes);
                file_put_contents($path, str_replace('seo-backup/1', 'seo-backup/9', $bytes));
            },
            'path' => function (string $path): void {
                $bytes = (string) file_get_contents($path);
                $needle = 'database/users.ndjson';
                $replacement = '../'.str_repeat('x', strlen($needle) - 3);
                $this->assertSame(strlen($needle), strlen($replacement));
                $position = strpos($bytes, $needle, strpos($bytes, $needle) + 1);
                $this->assertNotFalse($position);
                $bytes = substr_replace($bytes, $replacement, $position, strlen($needle));
                file_put_contents($path, $bytes);
            },
            'trailing' => fn (string $path) => file_put_contents($path, 'x', FILE_APPEND),
        ];

        foreach ($cases as $name => $tamper) {
            $bundlePath = $this->makeBundle();
            $tamper($bundlePath);
            $before = $this->durableBootstrapCounts();

            try {
                $this->app->make(VerifyRestoreBundle::class)->handle(
                    new UploadedFile($bundlePath, "{$name}.seobak", 'application/octet-stream', null, true),
                );
                $this->fail("Verifier unexpectedly accepted {$name} tampering.");
            } catch (BackupException $exception) {
                $this->assertContains($exception->codeName(), [
                    'restore_invalid_bundle',
                    'restore_checksum_mismatch',
                    'restore_incompatible_schema',
                    'restore_path_rejected',
                ], $name);
            }

            $this->assertSame($before, $this->durableBootstrapCounts(), $name);
            $this->assertSame([], File::files($this->restoreUploads), $name);
            (new RestoreJournal($this->journalPath))->clear();
        }

        foreach ([
            'version' => ['product_version' => '9.9.9-alpha.9'],
            'fingerprint' => ['application_schema_fingerprint' => str_repeat('f', 64)],
            'catalog' => ['tamper_catalog' => true],
        ] as $name => $overrides) {
            $bundlePath = $this->makeBundle($overrides);
            $before = $this->durableBootstrapCounts();

            try {
                $this->app->make(VerifyRestoreBundle::class)->handle(
                    new UploadedFile($bundlePath, "{$name}.seobak", 'application/octet-stream', null, true),
                );
                $this->fail("Verifier unexpectedly accepted {$name} incompatibility.");
            } catch (BackupException $exception) {
                $this->assertSame('restore_incompatible_schema', $exception->codeName(), $name);
            }

            $this->assertSame($before, $this->durableBootstrapCounts(), $name);
            $this->assertSame([], File::files($this->restoreUploads), $name);
            (new RestoreJournal($this->journalPath))->clear();
        }
    }

    public function test_verify_endpoint_enforces_owner_recent_password_throttle_and_failed_upload_cleanup(): void
    {
        $this->post('/settings/backup/restore/verify')->assertRedirect('/login');

        $other = User::factory()->create([
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $otherWorkspace = Workspace::query()->create(['name' => 'Other']);
        Membership::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'user_id' => $other->id,
            'role' => WorkspaceRole::Owner,
        ]);

        $this->actingAs($other)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/settings/backup/restore/verify', [
                'backup' => UploadedFile::fake()->createWithContent('bad.seobak', 'bad'),
            ])
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->withSession(['auth.password_confirmed_at' => 0])
            ->post('/settings/backup/restore/verify', [
                'backup' => UploadedFile::fake()->createWithContent('bad.seobak', 'bad'),
            ])
            ->assertRedirect(route('password.confirm'));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->actingAs($this->owner)
                ->withSession(['auth.password_confirmed_at' => time()])
                ->post('/settings/backup/restore/verify', [
                    'backup' => UploadedFile::fake()->createWithContent("bad-{$attempt}.seobak", 'bad'),
                ])
                ->assertStatus(422);
        }

        $this->actingAs($this->owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/settings/backup/restore/verify', [
                'backup' => UploadedFile::fake()->createWithContent('bad-4.seobak', 'bad'),
            ])
            ->assertTooManyRequests();

        $this->assertSame([], File::files($this->restoreUploads));
    }

    /** @param array<string, mixed> $overrides */
    private function makeBundle(array $overrides = []): string
    {
        $catalog = $this->app->make(ApplicationTableCatalog::class);
        $tables = $catalog->metadata(DB::connection());

        if (($overrides['tamper_catalog'] ?? false) === true) {
            $tables[0]['columns'][0]['type'] = 'tampered_type';
        }

        $streams = [];
        $entries = [];

        foreach ($catalog->tableNames() as $table) {
            $stream = fopen('php://temp', 'w+b');
            $this->assertIsResource($stream);
            $streams[] = $stream;
            $entries[] = [
                'path' => $catalog->entryPath($table),
                'stream' => $stream,
                'media_type' => 'application/x-ndjson',
                'record_count' => 0,
            ];
        }

        $settings = fopen('php://temp', 'w+b');
        $this->assertIsResource($settings);
        fwrite($settings, json_encode([
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'APP_LOCALE' => 'fa',
            'APP_FALLBACK_LOCALE' => 'en',
            'SELF_HOST_REGISTRATION' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rewind($settings);
        $streams[] = $settings;
        $entries[] = [
            'path' => 'settings/runtime.json',
            'stream' => $settings,
            'media_type' => 'application/json',
        ];

        $output = fopen('php://temp', 'w+b');
        $this->assertIsResource($output);

        $this->app->make(BackupBundleWriter::class)->write($output, [
            'schema' => 'seo-backup/1',
            'created_at_utc' => '2026-10-23T10:00:00Z',
            'product_version' => $overrides['product_version'] ?? (new ProductVersion)->requireDistributable(),
            'installer_schema_version' => 2,
            'source_installation_id' => str_repeat('3', 32),
            'source_owner_user_id' => $this->owner->id,
            'source_database_driver' => DB::connection()->getDriverName(),
            'source_database_server_version' => 'test',
            'application_schema_fingerprint' => $overrides['application_schema_fingerprint']
                ?? $this->app->make(ApplicationSchemaFingerprint::class)->forConnection(DB::connection()),
            'tables' => $tables,
            'excluded_runtime_tables' => $catalog->excludedTables(),
        ], $entries);

        $path = $this->directory.'/source-'.bin2hex(random_bytes(6)).'.seobak';
        File::put($path, stream_get_contents($output));

        fclose($output);
        foreach ($streams as $stream) {
            fclose($stream);
        }

        return $path;
    }

    /** @return array<string, int> */
    private function durableBootstrapCounts(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'workspaces' => DB::table('workspaces')->count(),
            'memberships' => DB::table('memberships')->count(),
            'projects' => DB::table('projects')->count(),
        ];
    }
}
