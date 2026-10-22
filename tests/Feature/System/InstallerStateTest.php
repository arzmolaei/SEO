<?php

namespace Tests\Feature\System;

use App\Support\InstallationState;
use App\Support\Installer\InstallerJournal;
use App\Support\Installer\InstallerMutex;
use App\Support\Installer\InstallerPhase;
use App\Support\Installer\InstallerStatus;
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

        $journal->clear();

        $this->assertSame([], $journal->read());
        $this->assertFileDoesNotExist($path);
    }

    public function test_journal_rejects_secret_bearing_keys_and_raw_dsn_values_recursively(): void
    {
        $journal = new InstallerJournal($this->directory.'/journal.json');

        foreach ([
            ['password' => 'secret'],
            ['db_secret' => 'secret'],
            ['nested' => ['access_token' => 'secret']],
            ['connection_dsn' => 'mysql://user:pass@localhost/seo'],
            ['diagnostic' => 'mysql://user:pass@localhost/seo'],
        ] as $payload) {
            try {
                $journal->replace($payload);
                $this->fail('Secret-bearing installer journal payload was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], $journal->read());
            }
        }
    }

    public function test_default_journal_and_mutex_paths_live_under_installer_storage(): void
    {
        $journalPath = $this->directory.'/installer/journal.json';
        $mutexPath = $this->directory.'/installer/mutex.lock';
        config([
            'installer.journal_path' => $journalPath,
            'installer.mutex_path' => $mutexPath,
        ]);

        $journal = new InstallerJournal;
        $mutex = new InstallerMutex;

        $journal->replace(['phase' => 'preflight']);
        $mutex->run(fn (): bool => true);

        $this->assertFileExists($journalPath);
        $this->assertFileExists($mutexPath);
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

    public function test_status_uses_installed_lock_as_authority_and_otherwise_exposes_known_journal_phase(): void
    {
        $journal = new InstallerJournal($this->directory.'/journal.json');
        $state = new InstallationState($this->directory.'/.installed', false);
        $status = new InstallerStatus($state, $journal);

        $this->assertSame(InstallerPhase::Preflight, $status->snapshot()['phase']);
        $this->assertFalse($status->snapshot()['installed']);

        $journal->replace(['phase' => 'database']);

        $this->assertSame(InstallerPhase::Database, $status->snapshot()['phase']);
        $this->assertSame(['phase' => 'database'], $status->snapshot()['journal']);

        $state->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => '0.1.0',
            'installation_id' => '01JARTESTINSTALLATION00000000',
        ]);

        $this->assertSame(InstallerPhase::Installed, $status->snapshot()['phase']);
        $this->assertTrue($status->snapshot()['installed']);
    }

    public function test_unknown_journal_phase_falls_back_to_preflight(): void
    {
        $journal = new InstallerJournal($this->directory.'/journal.json');
        $journal->replace(['phase' => 'future-phase']);

        $status = new InstallerStatus(
            new InstallationState($this->directory.'/.installed', false),
            $journal,
        );

        $this->assertSame(InstallerPhase::Preflight, $status->snapshot()['phase']);
    }

    public function test_installation_lock_exposes_versioned_non_secret_metadata(): void
    {
        $path = $this->directory.'/.installed';
        $state = new InstallationState($path, false);

        $this->assertNull($state->details());

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
