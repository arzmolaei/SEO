<?php

namespace Tests\Unit\Backup;

use App\Services\Backup\BackupException;
use App\Services\Backup\RestoreJournal;
use App\Services\Backup\RestorePreview;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RestoreJournalTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/restore-journal-'.bin2hex(random_bytes(6)));
        $this->path = $this->directory.'/state.json';
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_journal_persists_atomic_non_secret_uploaded_and_verified_state(): void
    {
        $journal = new RestoreJournal($this->path);
        $uploadId = str_repeat('a', 32);

        $uploaded = $journal->startUpload($uploadId);

        $this->assertSame('uploaded', $uploaded['phase']);
        $this->assertSame($uploadId, $uploaded['upload_id']);
        $this->assertFileExists($this->path);
        $this->assertSame(0600, fileperms($this->path) & 0777);
        $this->assertFileDoesNotExist($this->path.'.tmp');

        $preview = new RestorePreview(
            uploadId: $uploadId,
            digest: str_repeat('b', 64),
            sourceInstallationId: str_repeat('c', 32),
            sourceCreatedAtUtc: '2026-10-23T10:00:00Z',
            sourceProductVersion: '0.1.0-alpha.1',
            tableRecordCount: 12,
            durableFileCount: 2,
            bundleBytes: 4096,
        );

        $verified = $journal->markVerified($preview);

        $this->assertSame('verified', $verified['phase']);
        $this->assertSame($preview->toArray(), $verified['preview']);
        $this->assertSame($verified, $journal->state());

        $raw = (string) file_get_contents($this->path);
        $this->assertStringNotContainsString('APP_KEY', $raw);
        $this->assertStringNotContainsString('password', strtolower($raw));
        $this->assertStringNotContainsString('credential', strtolower($raw));
        $this->assertStringNotContainsString('bundle_payload', strtolower($raw));
    }

    public function test_journal_enforces_phase_transitions_and_rejects_secret_evidence(): void
    {
        $journal = new RestoreJournal($this->path);
        $journal->startUpload(str_repeat('a', 32));

        try {
            $journal->transition('uploaded', 'confirmed');
            $this->fail('Journal unexpectedly allowed skipping the verified phase.');
        } catch (BackupException $exception) {
            $this->assertSame('restore_invalid_state', $exception->codeName());
        }

        $preview = new RestorePreview(
            uploadId: str_repeat('a', 32),
            digest: str_repeat('b', 64),
            sourceInstallationId: str_repeat('c', 32),
            sourceCreatedAtUtc: '2026-10-23T10:00:00Z',
            sourceProductVersion: '0.1.0-alpha.1',
            tableRecordCount: 0,
            durableFileCount: 0,
            bundleBytes: 100,
        );
        $journal->markVerified($preview);

        $this->expectException(BackupException::class);
        $journal->transition('verified', 'confirmed', ['APP_KEY' => 'secret']);
    }
}
