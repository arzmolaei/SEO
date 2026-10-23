<?php

namespace Tests\Feature\Update;

use App\Services\Update\UpdateJournal;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class UpdateMaintenanceGateTest extends TestCase
{
    private string $root;

    private string $journalPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/seo-os-update-maintenance-'.bin2hex(random_bytes(6));
        $this->journalPath = $this->root.'/storage/app/lifecycle/update/state.json';
        mkdir($this->root, 0755, true);
        file_put_contents($this->root.'/VERSION', "0.1.0-alpha.1\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_no_journal_or_non_destructive_phase_leaves_http_and_cli_available(): void
    {
        $gate = $this->gate();

        $this->assertFalse($gate->shouldBlockHttp('/dashboard'));
        $this->assertFalse($gate->shouldBlockCli());

        $this->writeJournal(['phase' => 'verified', 'upload_id' => str_repeat('a', 32)]);
        $gate = $this->gate();

        $this->assertFalse($gate->shouldBlockHttp('/dashboard'));
        $this->assertFalse($gate->shouldBlockCli());
    }

    public function test_destructive_phase_blocks_ordinary_http_but_allows_narrow_update_and_auth_continuation_paths(): void
    {
        $this->writeJournal(['phase' => 'migrating', 'upload_id' => str_repeat('b', 32)]);
        $gate = $this->gate();

        foreach (['/', '/dashboard', '/projects/1', '/settings/profile', '/settings/update-evil'] as $path) {
            $this->assertTrue($gate->shouldBlockHttp($path), "Ordinary path should be blocked: {$path}");
        }

        foreach ([
            '/settings/update',
            '/settings/update?phase=migrating',
            '/settings/update/continue',
            '/settings/update/recovery/status',
            '/login',
            '/logout',
            '/confirm-password',
        ] as $path) {
            $this->assertFalse($gate->shouldBlockHttp($path), "Recovery path should remain reachable: {$path}");
        }

        $this->assertTrue($gate->shouldBlockCli());
    }

    public function test_corrupt_or_unsafe_journal_fails_closed_even_for_recovery_paths(): void
    {
        $this->ensureDirectory(dirname($this->journalPath));
        file_put_contents($this->journalPath, '{not-json');
        @chmod($this->journalPath, 0600);

        $gate = $this->gate();

        $this->assertTrue($gate->shouldBlockHttp('/dashboard'));
        $this->assertTrue($gate->shouldBlockHttp('/settings/update'));
        $this->assertTrue($gate->shouldBlockCli());
    }

    public function test_unknown_persisted_phase_fails_closed_for_http_recovery_and_cli(): void
    {
        foreach (['unknown', 'Migrating', '', ' migration', 'uploaded '] as $phase) {
            $this->writeJournal(['phase' => $phase, 'upload_id' => str_repeat('c', 32)]);
            $gate = $this->gate();

            $this->assertTrue($gate->shouldBlockHttp('/dashboard'));
            $this->assertTrue($gate->shouldBlockHttp('/settings/update'));
            $this->assertTrue($gate->shouldBlockCli());
        }
    }

    public function test_launcher_maintenance_phases_cannot_drift_from_application_journal(): void
    {
        require_once dirname(__DIR__, 3).'/bootstrap/runtime-release.php';

        $this->assertSame(UpdateJournal::maintenancePhases(), \SeoOsUpdateMaintenanceGate::maintenancePhases());
    }

    public function test_public_and_cli_launchers_gate_before_resolving_or_booting_a_release(): void
    {
        $public = file_get_contents(dirname(__DIR__, 3).'/public/index.php');
        $artisan = file_get_contents(dirname(__DIR__, 3).'/artisan');
        $this->assertIsString($public);
        $this->assertIsString($artisan);

        $publicGate = strpos($public, 'SeoOsUpdateMaintenanceGate');
        $publicResolve = strpos($public, '->resolve()');
        $artisanGate = strpos($artisan, 'SeoOsUpdateMaintenanceGate');
        $artisanResolve = strpos($artisan, '->resolve()');

        $this->assertNotFalse($publicGate);
        $this->assertNotFalse($publicResolve);
        $this->assertLessThan($publicResolve, $publicGate);
        $this->assertStringContainsString('http_response_code(503)', $public);

        $this->assertNotFalse($artisanGate);
        $this->assertNotFalse($artisanResolve);
        $this->assertLessThan($artisanResolve, $artisanGate);
        $this->assertStringContainsString('shouldBlockCli()', $artisan);
    }

    private function gate(): object
    {
        require_once dirname(__DIR__, 3).'/bootstrap/runtime-release.php';
        $this->assertTrue(class_exists('SeoOsUpdateMaintenanceGate'));

        return new \SeoOsUpdateMaintenanceGate($this->root);
    }

    /** @param array<string, mixed> $state */
    private function writeJournal(array $state): void
    {
        $this->ensureDirectory(dirname($this->journalPath));
        file_put_contents($this->journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        @chmod($this->journalPath, 0600);
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0700, true);
        }
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
