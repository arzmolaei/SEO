<?php

namespace Tests\Feature\System;

use App\Support\InstallationState;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class BackupInstallationStateTest extends TestCase
{
    private string $directory;

    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/backup-installation-state-'.bin2hex(random_bytes(6)));
        $this->lockPath = $this->directory.'/.installed';
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_initial_lock_accepts_system_owner_and_restore_provenance_metadata(): void
    {
        $state = new InstallationState($this->lockPath, false);

        $state->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => '0.1.0-alpha.1',
            'installation_id' => 'destination-installation',
            'database_fingerprint' => str_repeat('a', 64),
            'owner_user_id' => 17,
            'restored_from_installation_id' => 'source-installation',
            'restored_at' => '2026-10-23T11:00:00+00:00',
        ]);

        $details = $state->details();

        $this->assertIsArray($details);
        $this->assertSame(17, $details['owner_user_id']);
        $this->assertSame('source-installation', $details['restored_from_installation_id']);
        $this->assertSame('2026-10-23T11:00:00+00:00', $details['restored_at']);
    }

    public function test_metadata_rewrite_is_atomic_and_preserves_destination_identity(): void
    {
        $state = new InstallationState($this->lockPath, false);
        $state->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => '0.1.0-alpha.1',
            'installation_id' => 'destination-installation',
            'database_fingerprint' => str_repeat('b', 64),
        ]);
        $before = $state->details();
        $this->assertIsArray($before);

        $result = $state->updateMetadata([
            'owner_user_id' => 23,
            'restored_from_installation_id' => 'source-installation',
            'restored_at' => '2026-10-23T11:30:00+00:00',
        ]);

        $this->assertSame('destination-installation', $result['installation_id']);
        $this->assertSame(str_repeat('b', 64), $result['database_fingerprint']);
        $this->assertSame($before['installed_at'], $result['installed_at']);
        $this->assertSame(23, $result['owner_user_id']);
        $this->assertSame($result, $state->details());
        $this->assertFileDoesNotExist($this->lockPath.'.tmp');
        $this->assertSame(0600, fileperms($this->lockPath) & 0777);
    }

    public function test_metadata_rewrite_cannot_replace_local_installation_identity(): void
    {
        $state = new InstallationState($this->lockPath, false);
        $state->markInstalled([
            'installation_id' => 'destination-installation',
            'database_fingerprint' => str_repeat('c', 64),
        ]);

        foreach ([
            ['installation_id' => 'attacker-source'],
            ['database_fingerprint' => str_repeat('d', 64)],
            ['installed_at' => '2000-01-01T00:00:00+00:00'],
            ['version' => 999],
        ] as $changes) {
            try {
                $state->updateMetadata($changes);
                $this->fail('Immutable local installation identity was overwritten.');
            } catch (InvalidArgumentException) {
                $details = $state->details();
                $this->assertIsArray($details);
                $this->assertSame('destination-installation', $details['installation_id']);
                $this->assertSame(str_repeat('c', 64), $details['database_fingerprint']);
            }
        }
    }
}
