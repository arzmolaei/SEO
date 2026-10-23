<?php

namespace Tests\Feature\Update;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\RestoreJournal;
use App\Services\Update\PreparedSystemUpdate;
use App\Services\Update\PrepareSystemUpdate;
use App\Services\Update\UpdateException;
use App\Services\Update\UpdateJournal;
use App\Services\Update\UpdateReleaseStager;
use App\Services\Update\VerifyUpdatePackage;
use App\Support\InstallationState;
use App\Support\Lifecycle\ProductVersion;
use App\Support\ReleasePackageContract;
use App\Support\Update\ActiveReleasePointer;
use App\Support\Update\UpdatePackageReader;
use App\Support\Update\UpdatePackageWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PrepareSystemUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $installationRoot;

    private string $releaseRoot;

    private string $publicRoot;

    private string $uploadRoot;

    private string $safetyRoot;

    private string $updateJournalPath;

    private string $restoreJournalPath;

    private string $privateDurableRoot;

    private string $publicDurableRoot;

    private UpdateJournal $journal;

    private RestoreJournal $restoreJournal;

    private ProductVersion $productVersion;

    private ActiveReleasePointer $pointer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/prepare-system-update-'.bin2hex(random_bytes(6)));
        $this->installationRoot = $this->directory.'/installation';
        $this->releaseRoot = $this->installationRoot.'/storage/app/lifecycle/update/releases';
        $this->publicRoot = $this->installationRoot.'/public';
        $this->uploadRoot = $this->installationRoot.'/storage/app/lifecycle/update/uploads';
        $this->safetyRoot = $this->installationRoot.'/storage/app/lifecycle/update/safety';
        $this->updateJournalPath = $this->installationRoot.'/storage/app/lifecycle/update/state.json';
        $this->restoreJournalPath = $this->installationRoot.'/storage/app/lifecycle/restore/state.json';
        $this->privateDurableRoot = $this->directory.'/durable/private';
        $this->publicDurableRoot = $this->directory.'/durable/public';

        foreach ([
            $this->installationRoot.'/bootstrap',
            $this->publicRoot.'/build/assets',
            $this->privateDurableRoot,
            $this->publicDurableRoot,
        ] as $path) {
            File::ensureDirectoryExists($path);
        }

        File::put($this->installationRoot.'/VERSION', "0.1.0-alpha.1\n");
        File::copy(base_path('bootstrap/runtime-release.php'), $this->installationRoot.'/bootstrap/runtime-release.php');
        File::put($this->publicRoot.'/build/assets/old-source-hash.js', 'old source asset');
        File::put($this->privateDurableRoot.'/source.txt', 'source private durable');
        File::put($this->publicDurableRoot.'/source.txt', 'source public durable');

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->installationRoot.'/storage/app/installer/.installed',
            'installer.public_registration' => false,
            'backup.lifecycle_mutex_path' => $this->installationRoot.'/storage/app/lifecycle/lifecycle.lock',
            'backup.restore.journal_path' => $this->restoreJournalPath,
            'backup.durable_file_roots' => [
                'storage/app/private/uploads' => $this->privateDurableRoot,
                'storage/app/public/uploads' => $this->publicDurableRoot,
            ],
            'update.upload_path' => $this->uploadRoot,
            'update.safety_path' => $this->safetyRoot,
            'queue.default' => 'sync',
            'cache.default' => 'array',
        ]);

        $this->journal = new UpdateJournal($this->updateJournalPath);
        $this->restoreJournal = new RestoreJournal($this->restoreJournalPath);
        $this->productVersion = new ProductVersion($this->installationRoot.'/VERSION');
        $this->pointer = new ActiveReleasePointer($this->installationRoot, null, $this->releaseRoot);

        $this->app->instance(UpdateJournal::class, $this->journal);
        $this->app->instance(RestoreJournal::class, $this->restoreJournal);
        $this->app->instance(ProductVersion::class, $this->productVersion);
        $this->app->instance(ActiveReleasePointer::class, $this->pointer);
        $this->app->instance(UpdateReleaseStager::class, new UpdateReleaseStager(
            $this->app->make(UpdatePackageReader::class),
            $this->app->make(ReleasePackageContract::class),
            $this->installationRoot,
            $this->releaseRoot,
            $this->publicRoot,
        ));

        $this->seedInstalledSource('0.1.0-alpha.1');
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_prepare_captures_source_then_stages_private_release_and_public_assets_without_switching_root_source(): void
    {
        [$preview, $packagePath] = $this->verifyPackage('0.1.0-alpha.2');
        $before = $this->pointer->current();
        $this->assertSame('root', $before['kind']);

        $prepared = $this->app->make(PrepareSystemUpdate::class)->handle($preview->packageDigestSha256);

        $this->assertInstanceOf(PreparedSystemUpdate::class, $prepared);
        $this->assertSame($preview->packageDigestSha256, $prepared->packageDigestSha256);
        $this->assertSame('root', $prepared->sourceReleaseKind);
        $this->assertNull($prepared->sourceReleaseId);
        $this->assertSame('0.1.0-alpha.1', $prepared->sourceProductVersion);
        $this->assertSame('0.1.0-alpha.2', $prepared->targetProductVersion);
        $this->assertSame($before, $this->pointer->current());

        $state = $this->journal->state();
        $this->assertIsArray($state);
        $this->assertSame('staged', $state['phase']);
        $this->assertSame('root', $state['source_release_kind']);
        $this->assertNull($state['source_release_id']);
        $this->assertSame($prepared->targetReleaseId, $state['target_release_id']);
        $this->assertSame($preview->packageDigestSha256, $state['package_digest_sha256']);
        $this->assertArrayHasKey('pre_update_migration_history_sha256', $state);
        $this->assertSame(hash('sha256', $this->installationRoot.'/storage/app/lifecycle/lifecycle.lock'), $state['lifecycle_mutex_path_sha256']);
        $this->assertArrayNotHasKey('target_release_root', $state);
        $this->assertStringNotContainsString($this->directory, json_encode($state, JSON_THROW_ON_ERROR));

        $safetyBundle = $this->safetyRoot.'/'.$preview->uploadId.'.seobak';
        $safetyMetadata = $this->safetyRoot.'/'.$preview->uploadId.'.json';
        $this->assertFileExists($safetyBundle);
        $this->assertFileExists($safetyMetadata);
        $this->assertGreaterThan(0, filesize($safetyBundle));
        $metadata = json_decode((string) File::get($safetyMetadata), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('0.1.0-alpha.1', $metadata['product_version']);
        $this->assertSame(1, $metadata['owner_user_id']);

        $slot = $this->releaseRoot.'/'.$prepared->targetReleaseId;
        $this->assertDirectoryExists($slot);
        $this->assertDirectoryExists($slot.'/bootstrap/cache');
        $this->assertSame("0.1.0-alpha.2\n", File::get($slot.'/VERSION'));
        $marker = json_decode((string) File::get($slot.'/release.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'schema' => 'seo-release-slot/1',
            'release_id' => $prepared->targetReleaseId,
            'product_version' => '0.1.0-alpha.2',
            'package_digest_sha256' => $preview->packageDigestSha256,
            'launcher_schema' => 'seo-launcher/1',
        ], $marker);
        $this->assertTrue($this->app->make(ReleasePackageContract::class)->inspect($slot)['valid']);

        $this->assertSame('old source asset', File::get($this->publicRoot.'/build/assets/old-source-hash.js'));
        $this->assertSame('target hashed asset', File::get($this->publicRoot.'/build/assets/app-target-hash.js'));
        $this->assertFileExists($packagePath);
    }

    public function test_reserved_database_work_blocks_before_safety_snapshot_or_release_staging(): void
    {
        [$preview] = $this->verifyPackage('0.1.0-alpha.2');
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => null,
            'queue.connections.database.table' => 'jobs',
        ]);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => time(),
            'available_at' => time(),
            'created_at' => time(),
        ]);

        try {
            $this->app->make(PrepareSystemUpdate::class)->handle($preview->packageDigestSha256);
            $this->fail('Reserved work must block update preparation before safety capture.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_quiescence_required', $exception->codeName());
        }

        $this->assertSame('quiescing', $this->journal->state()['phase'] ?? null);
        $this->assertDirectoryDoesNotExist($this->safetyRoot);
        $this->assertDirectoryDoesNotExist($this->releaseRoot);
        $this->assertSame('root', $this->pointer->current()['kind']);
    }

    public function test_prepare_rechecks_package_digest_and_source_version_and_schema_under_mutex_before_snapshot(): void
    {
        foreach (['package', 'version', 'schema'] as $case) {
            $this->resetUpdateArtifacts();
            [$preview] = $this->verifyPackage('0.1.0-alpha.2');

            if ($case === 'package') {
                File::append($this->uploadRoot.'/'.$preview->uploadId.'.seoupdate', 'tamper');
            } elseif ($case === 'version') {
                File::put($this->installationRoot.'/VERSION', "0.1.0-alpha.9\n");
            } else {
                Schema::table('users', function (Blueprint $table): void {
                    $table->string('update_prepare_schema_drift')->nullable();
                });
            }

            try {
                $this->app->make(PrepareSystemUpdate::class)->handle($preview->packageDigestSha256);
                $this->fail("Prepare unexpectedly accepted {$case} drift.");
            } catch (UpdateException $exception) {
                $this->assertContains($exception->codeName(), [
                    'update_package_changed',
                    'update_source_mismatch',
                    'update_schema_mismatch',
                ]);
            }

            $this->assertDirectoryDoesNotExist($this->safetyRoot, $case);
            $this->assertDirectoryDoesNotExist($this->releaseRoot, $case);
            $this->assertSame('root', $this->pointer->current()['kind'], $case);

            if ($case === 'version') {
                File::put($this->installationRoot.'/VERSION', "0.1.0-alpha.1\n");
            }
        }
    }

    public function test_prepare_records_existing_slot_as_source_without_switching_it(): void
    {
        $sourceDigest = str_repeat('a', 64);
        $sourceReleaseId = '0.1.0-alpha.2-'.substr($sourceDigest, 0, 16);
        $sourceSlot = $this->releaseRoot.'/'.$sourceReleaseId;
        File::ensureDirectoryExists($sourceSlot);
        File::put($sourceSlot.'/VERSION', "0.1.0-alpha.2\n");
        File::put($sourceSlot.'/release.json', json_encode([
            'schema' => 'seo-release-slot/1',
            'release_id' => $sourceReleaseId,
            'product_version' => '0.1.0-alpha.2',
            'package_digest_sha256' => $sourceDigest,
            'launcher_schema' => 'seo-launcher/1',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        @chmod($sourceSlot.'/release.json', 0600);
        $this->pointer->activateSlot($sourceReleaseId, '0.1.0-alpha.2', $sourceDigest);

        $this->productVersion = new ProductVersion($sourceSlot.'/VERSION');
        $this->app->instance(ProductVersion::class, $this->productVersion);
        (new InstallationState)->updateMetadata(['product_version' => '0.1.0-alpha.2']);
        $this->resetUpdateArtifacts(keepReleases: true);

        [$preview] = $this->verifyPackage('0.1.0-alpha.3');
        $before = $this->pointer->current();
        $this->assertSame($sourceReleaseId, $before['release_id']);

        $prepared = $this->app->make(PrepareSystemUpdate::class)->handle($preview->packageDigestSha256);

        $this->assertSame('slot', $prepared->sourceReleaseKind);
        $this->assertSame($sourceReleaseId, $prepared->sourceReleaseId);
        $this->assertSame('0.1.0-alpha.2', $prepared->sourceProductVersion);
        $this->assertSame($before, $this->pointer->current());
        $this->assertSame($sourceReleaseId, $this->journal->state()['source_release_id'] ?? null);
    }

    private function seedInstalledSource(string $version): void
    {
        $owner = User::factory()->create([
            'id' => 1,
            'email' => 'source-owner@example.test',
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $workspace = Workspace::query()->create(['name' => 'Source workspace']);
        Membership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => WorkspaceRole::Owner,
        ]);
        Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Source project',
        ]);

        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => $version,
            'installation_id' => str_repeat('1', 32),
            'database_fingerprint' => str_repeat('2', 64),
            'owner_user_id' => $owner->id,
        ]);
    }

    /** @return array{0: object, 1: string} */
    private function verifyPackage(string $targetVersion): array
    {
        $sourceVersion = $this->productVersion->requireDistributable();
        $sourceSchema = $this->app->make(ApplicationSchemaFingerprint::class)->forConnection(DB::connection());
        $packagePath = $this->makePackage($sourceVersion, $targetVersion, $sourceSchema);
        $preview = $this->app->make(VerifyUpdatePackage::class)->handle(
            new UploadedFile($packagePath, 'release.seoupdate', 'application/octet-stream', null, true),
        );

        return [$preview, $packagePath];
    }

    private function makePackage(string $sourceVersion, string $targetVersion, string $schema): string
    {
        $entryContents = [
            'VERSION' => $targetVersion."\n",
            'artisan' => "#!/usr/bin/env php\n<?php\n",
            'bootstrap/app.php' => "<?php\nreturn null;\n",
            'bootstrap/runtime-release.php' => (string) File::get(base_path('bootstrap/runtime-release.php')),
            'vendor/autoload.php' => "<?php\n",
            'public/build/manifest.json' => "{}\n",
            'public/build/assets/app-target-hash.js' => 'target hashed asset',
            'public/fonts/open/vazirmatn/Vazirmatn[wght].woff2' => 'font fixture',
            'resources/views/app.blade.php' => '<!doctype html>',
            '.env.example' => "APP_ENV=production\n",
        ];
        ksort($entryContents, SORT_STRING);

        $entries = [];
        $streams = [];
        try {
            foreach ($entryContents as $path => $contents) {
                $stream = fopen('php://temp', 'w+b');
                $this->assertIsResource($stream);
                fwrite($stream, $contents);
                rewind($stream);
                $streams[] = $stream;
                $entries[] = [
                    'path' => $path,
                    'stream' => $stream,
                    'media_type' => str_ends_with($path, '.json') ? 'application/json' : 'application/octet-stream',
                ];
            }

            $output = fopen('php://temp', 'w+b');
            $this->assertIsResource($output);
            $this->app->make(UpdatePackageWriter::class)->write($output, [
                'schema' => 'seo-update/1',
                'created_at_utc' => '2026-10-23T17:30:00Z',
                'target_product_version' => $targetVersion,
                'allowed_source_product_versions' => [$sourceVersion],
                'channel' => 'development',
                'launcher_schema' => 'seo-launcher/1',
                'minimum_php_version' => '8.4.1',
                'required_php_extensions' => ['ctype', 'json', 'pdo'],
                'source_schema_fingerprint' => $schema,
                'target_schema_fingerprint' => $schema,
                'migration_tree_sha256' => hash('sha256', '[]'),
            ], $entries);
            rewind($output);
            $bytes = stream_get_contents($output);
            $this->assertIsString($bytes);
            fclose($output);
        } finally {
            foreach ($streams as $stream) {
                fclose($stream);
            }
        }

        $path = $this->directory.'/package-'.bin2hex(random_bytes(6)).'.seoupdate';
        File::put($path, $bytes);

        return $path;
    }

    private function resetUpdateArtifacts(bool $keepReleases = false): void
    {
        $this->journal->clear();
        File::deleteDirectory($this->uploadRoot);
        File::deleteDirectory($this->safetyRoot);
        if (! $keepReleases) {
            File::deleteDirectory($this->releaseRoot);
        }
    }
}
