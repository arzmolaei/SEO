<?php

namespace Tests\Feature\Update;

use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\RestoreJournal;
use App\Services\Update\UpdateException;
use App\Services\Update\UpdateJournal;
use App\Services\Update\VerifyUpdatePackage;
use App\Support\Lifecycle\ProductVersion;
use App\Support\Update\UpdatePackageWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VerifyUpdatePackageTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $uploadDirectory;

    private string $updateJournalPath;

    private string $restoreJournalPath;

    private UpdateJournal $updateJournal;

    private RestoreJournal $restoreJournal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/verify-update-package-'.bin2hex(random_bytes(6)));
        $this->uploadDirectory = $this->directory.'/lifecycle/update/uploads';
        $this->updateJournalPath = $this->directory.'/lifecycle/update/state.json';
        $this->restoreJournalPath = $this->directory.'/lifecycle/restore/state.json';

        File::ensureDirectoryExists($this->uploadDirectory);

        config([
            'update.upload_path' => $this->uploadDirectory,
            'backup.restore.journal_path' => $this->restoreJournalPath,
        ]);

        $this->updateJournal = new UpdateJournal($this->updateJournalPath);
        $this->restoreJournal = new RestoreJournal($this->restoreJournalPath);
        $this->app->instance(UpdateJournal::class, $this->updateJournal);
        $this->app->instance(RestoreJournal::class, $this->restoreJournal);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_valid_package_is_fully_verified_privately_and_exposes_only_safe_preview(): void
    {
        $packagePath = $this->makePackage();
        $expectedDigest = hash_file('sha256', $packagePath);
        $this->assertIsString($expectedDigest);

        $preview = $this->app->make(VerifyUpdatePackage::class)->handle(
            new UploadedFile($packagePath, 'seo-os.seoupdate', 'application/octet-stream', null, true),
        );

        $data = $preview->toArray();
        $this->assertSame($expectedDigest, $data['package_digest_sha256']);
        $this->assertSame((new ProductVersion)->requireDistributable(), $data['source_product_version']);
        $this->assertSame(self::nextVersion(), $data['target_product_version']);
        $this->assertSame('development', $data['channel']);
        $this->assertSame('8.3.0', $data['minimum_php_version']);
        $this->assertSame(['ctype', 'json', 'pdo'], $data['required_php_extensions']);
        $this->assertSame(0, $data['migration_count']);
        $this->assertSame(str_repeat('3', 64), $data['migration_tree_sha256']);
        $this->assertSame([
            'source_version' => 'compatible',
            'source_schema' => 'compatible',
            'launcher' => 'compatible',
            'php_runtime' => 'compatible',
            'required_extensions' => 'compatible',
        ], $data['compatibility']);

        $this->assertSame([
            'upload_id',
            'package_digest_sha256',
            'source_product_version',
            'target_product_version',
            'channel',
            'minimum_php_version',
            'required_php_extensions',
            'migration_count',
            'migration_tree_sha256',
            'compatibility',
        ], array_keys($data));

        $state = $this->updateJournal->state();
        $this->assertIsArray($state);
        $this->assertSame('verified', $state['phase']);
        $this->assertSame($expectedDigest, $state['package_digest_sha256']);
        $this->assertSame($data, $state['preview']);

        $stored = $this->uploadDirectory.'/'.$data['upload_id'].'.seoupdate';
        $this->assertFileExists($stored);
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0600, fileperms($stored) & 0777);
        }

        $serialized = json_encode($data, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('.env', $serialized);
        $this->assertStringNotContainsString('APP_KEY', $serialized);
        $this->assertStringNotContainsString('password', strtolower($serialized));
        $this->assertStringNotContainsString($this->directory, $serialized);
    }

    public function test_incompatible_runtime_source_schema_and_version_fail_without_retaining_partial_upload(): void
    {
        $cases = [
            'source-version' => [
                'allowed_source_product_versions' => ['9.9.9'],
                'expected' => 'update_source_mismatch',
            ],
            'source-schema' => [
                'source_schema_fingerprint' => str_repeat('f', 64),
                'expected' => 'update_schema_mismatch',
            ],
            'same-version' => [
                'target_product_version' => (new ProductVersion)->requireDistributable(),
                'expected' => 'update_version_not_newer',
            ],
            'downgrade' => [
                'target_product_version' => '0.0.9',
                'expected' => 'update_version_not_newer',
            ],
            'php-floor' => [
                'minimum_php_version' => '99.0.0',
                'expected' => 'update_runtime_incompatible',
            ],
            'extension' => [
                'required_php_extensions' => ['ctype', 'json', 'pdo', 'zzzz_missing_extension'],
                'expected' => 'update_runtime_incompatible',
            ],
        ];

        foreach ($cases as $label => $overrides) {
            $expected = $overrides['expected'];
            unset($overrides['expected']);
            $packagePath = $this->makePackage($overrides);

            try {
                $this->verifyPath($packagePath, $label.'.seoupdate');
                $this->fail("Verifier unexpectedly accepted {$label} incompatibility.");
            } catch (UpdateException $exception) {
                $this->assertSame($expected, $exception->codeName(), $label);
            }

            $this->assertSame([], File::files($this->uploadDirectory), $label);
            $this->assertNull($this->updateJournal->state(), $label);
        }
    }

    public function test_corrupt_magic_launcher_trailing_bytes_and_checksum_are_rejected_and_cleaned(): void
    {
        $cases = [
            'magic' => function (string $path): void {
                $bytes = (string) file_get_contents($path);
                $bytes[0] = 'X';
                file_put_contents($path, $bytes);
            },
            'launcher' => function (string $path): void {
                $bytes = (string) file_get_contents($path);
                $this->assertStringContainsString('seo-launcher/2', $bytes);
                file_put_contents($path, str_replace('seo-launcher/2', 'seo-launcher/9', $bytes));
            },
            'trailing' => fn (string $path) => file_put_contents($path, 'x', FILE_APPEND),
            'checksum' => function (string $path): void {
                $bytes = (string) file_get_contents($path);
                $last = strlen($bytes) - 1;
                $bytes[$last] = $bytes[$last] === 'x' ? 'y' : 'x';
                file_put_contents($path, $bytes);
            },
        ];

        foreach ($cases as $label => $tamper) {
            $packagePath = $this->makePackage();
            $tamper($packagePath);

            try {
                $this->verifyPath($packagePath, $label.'.seoupdate');
                $this->fail("Verifier unexpectedly accepted {$label} tampering.");
            } catch (UpdateException $exception) {
                $this->assertSame('update_invalid_package', $exception->codeName(), $label);
            }

            $this->assertSame([], File::files($this->uploadDirectory), $label);
            $this->assertNull($this->updateJournal->state(), $label);
        }
    }

    public function test_restore_recovery_or_existing_update_state_blocks_new_upload_without_destroying_existing_state(): void
    {
        File::ensureDirectoryExists(dirname($this->restoreJournalPath));
        File::put($this->restoreJournalPath, json_encode([
            'phase' => 'recovery_required',
            'upload_id' => str_repeat('a', 32),
        ], JSON_THROW_ON_ERROR));
        @chmod($this->restoreJournalPath, 0600);

        try {
            $this->verifyPath($this->makePackage(), 'restore-conflict.seoupdate');
            $this->fail('Restore recovery must block update verification.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_lifecycle_conflict', $exception->codeName());
        }
        $this->assertSame([], File::files($this->uploadDirectory));
        $this->assertSame('recovery_required', $this->restoreJournal->state()['phase'] ?? null);

        $this->restoreJournal->clear();
        $existingId = str_repeat('b', 32);
        $this->updateJournal->startUpload($existingId);

        try {
            $this->verifyPath($this->makePackage(), 'update-conflict.seoupdate');
            $this->fail('Existing update state must block a second upload.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_lifecycle_conflict', $exception->codeName());
        }

        $this->assertSame('uploaded', $this->updateJournal->state()['phase'] ?? null);
        $this->assertSame($existingId, $this->updateJournal->state()['upload_id'] ?? null);
        $this->assertSame([], File::files($this->uploadDirectory));
    }

    public function test_a_completed_restore_does_not_block_later_updates(): void
    {
        // A finished restore keeps its terminal journal (it refuses a second
        // restore into a non-clean target); it is not an active operation.
        File::ensureDirectoryExists(dirname($this->restoreJournalPath));
        File::put($this->restoreJournalPath, json_encode([
            'phase' => 'complete',
            'upload_id' => str_repeat('a', 32),
            'restored_at' => '2026-10-28T00:00:00Z',
        ], JSON_THROW_ON_ERROR));
        @chmod($this->restoreJournalPath, 0600);

        $this->verifyPath($this->makePackage(), 'after-restore.seoupdate');

        $this->assertSame('verified', $this->updateJournal->state()['phase'] ?? null);
        $this->assertSame('complete', $this->restoreJournal->state()['phase'] ?? null);
    }

    /** @param array<string, mixed> $overrides */
    private function makePackage(array $overrides = []): string
    {
        $sourceVersion = (new ProductVersion)->requireDistributable();
        $sourceSchema = $this->app->make(ApplicationSchemaFingerprint::class)->forConnection(DB::connection());
        $version = fopen('php://temp', 'w+b');
        $this->assertIsResource($version);
        fwrite($version, self::nextVersion()."\n");

        $output = fopen('php://temp', 'w+b');
        $this->assertIsResource($output);

        try {
            $this->app->make(UpdatePackageWriter::class)->write($output, [
                'schema' => 'seo-update/1',
                'created_at_utc' => '2026-10-23T16:30:00Z',
                'target_product_version' => $overrides['target_product_version'] ?? self::nextVersion(),
                'allowed_source_product_versions' => $overrides['allowed_source_product_versions'] ?? [$sourceVersion],
                'channel' => $overrides['channel'] ?? 'development',
                'launcher_schema' => $overrides['launcher_schema'] ?? 'seo-launcher/2',
                'minimum_php_version' => $overrides['minimum_php_version'] ?? '8.3.0',
                'required_php_extensions' => $overrides['required_php_extensions'] ?? ['ctype', 'json', 'pdo'],
                'source_schema_fingerprint' => $overrides['source_schema_fingerprint'] ?? $sourceSchema,
                'target_schema_fingerprint' => $overrides['target_schema_fingerprint'] ?? $sourceSchema,
                'migration_tree_sha256' => $overrides['migration_tree_sha256'] ?? str_repeat('3', 64),
            ], [[
                'path' => 'VERSION',
                'stream' => $version,
                'media_type' => 'text/plain',
            ]]);

            rewind($output);
            $bytes = stream_get_contents($output);
            $this->assertIsString($bytes);
        } finally {
            fclose($version);
            fclose($output);
        }

        $path = $this->directory.'/source-'.bin2hex(random_bytes(6)).'.seoupdate';
        File::put($path, $bytes);

        return $path;
    }

    private function verifyPath(string $path, string $name): void
    {
        $this->app->make(VerifyUpdatePackage::class)->handle(
            new UploadedFile($path, $name, 'application/octet-stream', null, true),
        );
    }

    /** A target always newer than the checked-out VERSION. */
    private static function nextVersion(): string
    {
        return (string) preg_replace_callback(
            '/(\d+)\z/',
            static fn (array $match): string => (string) ((int) $match[1] + 1),
            (new ProductVersion)->requireDistributable(),
        );
    }
}
