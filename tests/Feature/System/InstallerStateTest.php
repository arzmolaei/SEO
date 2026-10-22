<?php

namespace Tests\Feature\System;

use App\Support\InstallationState;
use App\Support\Installer\InstallerJournal;
use App\Support\Installer\InstallerMutex;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class InstallerStateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-state-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_journal_starts_empty_and_replaces_non_secret_state_atomically(): void
    {
        $path = $this->directory.'/journal.json';
        $journal = new InstallerJournal($path);

        $this->assertSame([], $journal->read());

        $journal->replace([
            'phase' => 'database',
            'database_fingerprint' => hash('sha256', 'mysql|127.0.0.1|3306|seo'),
            'updated_at' => '2026-10-22T18:00:00+00:00',
        ]);

        $this->assertSame('database', $journal->read()['phase']);
        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($path.'.tmp');
    }

    public function test_journal_rejects_secret_bearing_keys_recursively(): void
    {
        $journal = new InstallerJournal($this->directory.'/journal.json');

        foreach ([
            ['password' => 'secret'],
            ['db_secret' => 'secret'],
            ['nested' => ['access_token' => 'secret']],
            ['connection_dsn' => 'mysql://user:pass@localhost/seo'],
        ] as $payload) {
            try {
                $journal->replace($payload);
                $this->fail('Secret-bearing installer journal payload was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], $journal->read());
            }
        }
    }

    public function test_mutex_is_reentrant_for_the_current_process_and_releases_after_callback(): void
    {
        $mutex = new InstallerMutex($this->directory.'/installer.lock');
        $events = [];

        $result = $mutex->run(function () use ($mutex, &$events): string {
            $events[] = 'outer';

            $mutex->run(function () use (&$events): void {
                $events[] = 'inner';
            });

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(['outer', 'inner'], $events);
        $this->assertFileExists($this->directory.'/installer.lock');
    }

    public function test_installation_lock_exposes_versioned_non_secret_metadata(): void
    {
        $path = $this->directory.'/.installed';
        $state = new InstallationState($path, false);

        $state->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => '0.1.0',
            'installation_id' => '01JARTESTINSTALLATION00000000',
        ]);

        $this->assertTrue($state->isInstalled());
        $this->assertSame([
            'installed_at' => $state->details()['installed_at'],
            'version' => 2,
            'installer_schema_version' => 2,
            'product_version' => '0.1.0',
            'installation_id' => '01JARTESTINSTALLATION00000000',
        ], $state->details());

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('password', $decoded);
        $this->assertArrayNotHasKey('secret', $decoded);
    }
}
