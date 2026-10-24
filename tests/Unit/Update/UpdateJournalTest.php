<?php

namespace Tests\Unit\Update;

use App\Services\Update\UpdateException;
use App\Services\Update\UpdateJournal;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class UpdateJournalTest extends TestCase
{
    private string $root;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/seo-os-update-journal-'.bin2hex(random_bytes(6));
        $this->path = $this->root.'/state.json';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_upload_state_is_atomic_private_and_survives_a_new_reader(): void
    {
        $journal = new UpdateJournal($this->path);
        $state = $journal->startUpload(str_repeat('a', 32));

        $this->assertSame([
            'phase' => 'uploaded',
            'upload_id' => str_repeat('a', 32),
        ], $state);
        $this->assertFileExists($this->path);
        $this->assertFileDoesNotExist($this->path.'.tmp');
        $this->assertSame($state, (new UpdateJournal($this->path))->state());

        if (PHP_OS_FAMILY !== 'Windows') {
            clearstatcache(true, $this->root);
            clearstatcache(true, $this->path);
            $directoryMode = fileperms($this->root);
            $fileMode = fileperms($this->path);
            $this->assertIsInt($directoryMode);
            $this->assertIsInt($fileMode);
            $this->assertSame(0700, $directoryMode & 0777);
            $this->assertSame(0600, $fileMode & 0777);
        }
    }

    public function test_upload_id_must_be_private_generated_shape_and_existing_state_cannot_be_replaced(): void
    {
        foreach (['', 'abc', str_repeat('A', 32), str_repeat('a', 31), str_repeat('a', 33)] as $uploadId) {
            try {
                (new UpdateJournal($this->path))->startUpload($uploadId);
                $this->fail("Invalid upload id should be rejected: {$uploadId}");
            } catch (UpdateException $exception) {
                $this->assertSame('update_invalid_state', $exception->codeName());
            }
        }

        $journal = new UpdateJournal($this->path);
        $journal->startUpload(str_repeat('b', 32));

        $this->expectException(UpdateException::class);
        $journal->startUpload(str_repeat('c', 32));
    }

    public function test_transition_graph_accepts_only_reviewed_edges(): void
    {
        $graph = [
            'uploaded' => ['verified'],
            'verified' => ['preparing'],
            'preparing' => ['quiescing'],
            'quiescing' => ['snapshot_ready'],
            'snapshot_ready' => ['staged'],
            'staged' => ['switch_pending'],
            'switch_pending' => ['target_active', 'recovery_required'],
            'target_active' => ['migrating', 'rollback_required', 'recovery_required'],
            'migrating' => ['validating', 'rollback_required', 'recovery_required'],
            'validating' => ['complete', 'rollback_required', 'recovery_required'],
            'rollback_required' => ['migration_rollback', 'source_switch_pending', 'recovery_required'],
            'migration_rollback' => ['source_switch_pending', 'recovery_required'],
            'source_switch_pending' => ['snapshot_restore_required', 'recovery_required'],
            'snapshot_restore_required' => ['complete', 'recovery_required'],
            'complete' => [],
            'recovery_required' => [],
        ];

        foreach ($graph as $from => $allowed) {
            foreach ($allowed as $to) {
                $this->writeState(['phase' => $from, 'upload_id' => str_repeat('d', 32)]);
                $state = (new UpdateJournal($this->path))->transition($from, $to, ['error_code' => 'safe_code']);
                $this->assertSame($to, $state['phase']);
                $this->assertSame('safe_code', $state['error_code']);
            }

            foreach (array_diff(array_keys($graph), $allowed) as $to) {
                $this->writeState(['phase' => $from, 'upload_id' => str_repeat('d', 32)]);

                try {
                    (new UpdateJournal($this->path))->transition($from, $to);
                    $this->fail("Unexpected transition accepted: {$from} -> {$to}");
                } catch (UpdateException $exception) {
                    $this->assertSame('update_invalid_state', $exception->codeName());
                }
            }
        }
    }

    public function test_transition_requires_the_exact_persisted_phase(): void
    {
        $journal = new UpdateJournal($this->path);
        $journal->startUpload(str_repeat('e', 32));

        $this->expectException(UpdateException::class);
        $journal->transition('verified', 'preparing');
    }

    public function test_evidence_rejects_secrets_objects_and_resources_without_mutating_state(): void
    {
        $rejected = [
            ['app_key' => 'secret'],
            ['nested' => ['db_password' => 'secret']],
            ['credential_hint' => 'secret'],
            ['secret_value' => 'secret'],
            ['bundle_payload' => 'secret'],
            ['absolute_path' => '/private/server/path'],
            ['object' => new \stdClass],
        ];

        $stream = fopen('php://temp', 'w+b');
        $this->assertIsResource($stream);
        $rejected[] = ['resource' => $stream];

        try {
            foreach ($rejected as $evidence) {
                $this->writeState(['phase' => 'uploaded', 'upload_id' => str_repeat('f', 32)]);

                try {
                    (new UpdateJournal($this->path))->transition('uploaded', 'verified', $evidence);
                    $this->fail('Unsafe journal evidence should be rejected.');
                } catch (UpdateException $exception) {
                    $this->assertSame('update_invalid_state', $exception->codeName());
                    $this->assertSame('uploaded', (new UpdateJournal($this->path))->state()['phase']);
                }
            }
        } finally {
            fclose($stream);
        }
    }

    public function test_reader_rejects_unknown_persisted_phase_without_rewriting_evidence(): void
    {
        foreach (['unknown', 'Migrating', '', ' migration', 'uploaded '] as $phase) {
            $this->writeState(['phase' => $phase, 'upload_id' => str_repeat('a', 32)]);
            $before = file_get_contents($this->path);

            try {
                (new UpdateJournal($this->path))->state();
                $this->fail('Unknown persisted phase must require operator recovery.');
            } catch (UpdateException $exception) {
                $this->assertSame('update_invalid_state', $exception->codeName());
                $this->assertSame($before, file_get_contents($this->path));
            }
        }
    }

    public function test_clear_removes_state_and_stale_temporary_file(): void
    {
        $journal = new UpdateJournal($this->path);
        $journal->startUpload(str_repeat('1', 32));
        file_put_contents($this->path.'.tmp', 'stale');

        $journal->clear();

        $this->assertNull($journal->state());
        $this->assertFileDoesNotExist($this->path);
        $this->assertFileDoesNotExist($this->path.'.tmp');
    }

    public function test_persistent_maintenance_phase_contract_is_exact(): void
    {
        $expected = [
            'preparing',
            'quiescing',
            'snapshot_ready',
            'staged',
            'switch_pending',
            'target_active',
            'migrating',
            'validating',
            'rollback_required',
            'migration_rollback',
            'source_switch_pending',
            'snapshot_restore_required',
            'recovery_required',
        ];

        $this->assertSame($expected, UpdateJournal::maintenancePhases());

        foreach ($expected as $phase) {
            $this->assertTrue(UpdateJournal::isMaintenancePhase($phase));
        }

        foreach ([null, 'uploaded', 'verified', 'complete', 'unknown'] as $phase) {
            $this->assertFalse(UpdateJournal::isMaintenancePhase($phase));
        }
    }

    /** @param array<string, mixed> $state */
    public function test_checkpoint_preserves_phase_and_upload_identity_and_rejects_stale_or_secret_updates(): void
    {
        $this->writeState(['phase' => 'migrating', 'upload_id' => str_repeat('d', 32)]);
        $journal = new UpdateJournal($this->path);
        $state = $journal->checkpoint('migrating', ['migration_state' => ['digest_sha256' => str_repeat('a', 64)]]);
        $this->assertSame('migrating', $state['phase']);
        $this->assertSame(str_repeat('d', 32), $state['upload_id']);
        $this->assertSame($state, (new UpdateJournal($this->path))->state());
        foreach ([['validating', ['safe' => true]], ['migrating', ['APP_KEY' => 'private']], ['migrating', ['phase' => 'complete']], ['migrating', ['upload_id' => str_repeat('f', 32)]]] as [$phase, $changes]) {
            try {
                $journal->checkpoint($phase, $changes);
                $this->fail('Stale, secret or identity-changing checkpoint must be rejected.');
            } catch (UpdateException) {
                $this->assertSame($state, $journal->state());
            }
        }
    }

    private function writeState(array $state): void
    {
        if (! is_dir($this->root)) {
            mkdir($this->root, 0700, true);
        }

        file_put_contents($this->path, json_encode($state, JSON_THROW_ON_ERROR));
        @chmod($this->path, 0600);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
