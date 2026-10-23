<?php

namespace Tests\Feature\Update;

use App\Services\Update\UpdateJournal;
use App\Support\Update\ActiveReleasePointer;
use App\Support\Lifecycle\LifecycleMutex;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TargetBootstrapFailureTest extends TestCase
{
    private string $directory;

    private string $installationRoot;

    private string $releaseRoot;

    private string $journalPath;

    private UpdateJournal $journal;

    private ActiveReleasePointer $pointer;

    private string $targetDigest;

    private string $targetReleaseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/target-bootstrap-failure-'.bin2hex(random_bytes(6)));
        $this->installationRoot = $this->directory.'/installation';
        $this->releaseRoot = $this->installationRoot.'/storage/app/lifecycle/update/releases';
        $this->journalPath = $this->installationRoot.'/storage/app/lifecycle/update/state.json';
        $this->targetDigest = str_repeat('b', 64);
        $this->targetReleaseId = '0.1.0-alpha.2-'.substr($this->targetDigest, 0, 16);

        File::ensureDirectoryExists($this->installationRoot.'/bootstrap');
        File::ensureDirectoryExists($this->installationRoot.'/public');
        File::put($this->installationRoot.'/VERSION', "0.1.0-alpha.1\n");
        File::copy(base_path('bootstrap/runtime-release.php'), $this->installationRoot.'/bootstrap/runtime-release.php');
        File::copy(base_path('public/index.php'), $this->installationRoot.'/public/index.php');

        $this->journal = new UpdateJournal($this->journalPath);
        $this->pointer = new ActiveReleasePointer($this->installationRoot, null, $this->releaseRoot);

        $this->seedSlot(
            $this->targetReleaseId,
            '0.1.0-alpha.2',
            $this->targetDigest,
            brokenBootstrap: true,
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_broken_target_before_migration_reverts_exactly_to_root_source_and_records_sanitized_recovery(): void
    {
        $this->seedJournal('root', null, '0.1.0-alpha.1');
        $this->pointer->activateSlot($this->targetReleaseId, '0.1.0-alpha.2', $this->targetDigest);

        [$exitCode, $output] = $this->runPublicLauncher();

        $this->assertSame(1, $exitCode);
        $this->assertSame('Application update recovery is required.', trim($output));
        $this->assertSame('root', $this->pointer->current()['kind']);
        $this->assertSame('0.1.0-alpha.1', $this->pointer->current()['product_version']);

        $state = $this->journal->state();
        $this->assertIsArray($state);
        $this->assertSame('recovery_required', $state['phase']);
        $this->assertSame('update_target_bootstrap_failed', $state['recovery_code'] ?? null);
        $this->assertTrue($state['recovery_reverted_to_source'] ?? false);
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('BOOTSTRAP_SECRET', $encoded);
        $this->assertStringNotContainsString($this->directory, $encoded);
        $this->assertStringNotContainsString('BOOTSTRAP_SECRET', $output);
        $this->assertStringNotContainsString($this->directory, $output);
    }

    public function test_broken_target_before_migration_reverts_to_exact_prior_slot_source(): void
    {
        $sourceDigest = str_repeat('a', 64);
        $sourceReleaseId = '0.1.0-alpha.1-'.substr($sourceDigest, 0, 16);
        $this->seedSlot($sourceReleaseId, '0.1.0-alpha.1', $sourceDigest);
        $this->seedJournal('slot', $sourceReleaseId, '0.1.0-alpha.1');
        $this->pointer->activateSlot($this->targetReleaseId, '0.1.0-alpha.2', $this->targetDigest);

        [$exitCode, $output] = $this->runPublicLauncher();

        $this->assertSame(1, $exitCode);
        $this->assertSame('Application update recovery is required.', trim($output));
        $current = $this->pointer->current();
        $this->assertSame('slot', $current['kind']);
        $this->assertSame($sourceReleaseId, $current['release_id']);
        $this->assertSame('0.1.0-alpha.1', $current['product_version']);
        $this->assertSame($sourceDigest, $current['package_digest_sha256']);

        $state = $this->journal->state();
        $this->assertIsArray($state);
        $this->assertSame('recovery_required', $state['phase']);
        $this->assertTrue($state['recovery_reverted_to_source'] ?? false);
    }

    public function test_broken_target_after_migration_started_never_reverts_pointer(): void
    {
        $this->seedJournal('root', null, '0.1.0-alpha.1');
        $this->journal->transition('target_active', 'migrating', [
            'migration_started' => true,
        ]);
        $this->pointer->activateSlot($this->targetReleaseId, '0.1.0-alpha.2', $this->targetDigest);

        [$exitCode, $output] = $this->runPublicLauncher();

        $this->assertSame(1, $exitCode);
        $this->assertSame('Application update recovery is required.', trim($output));
        $current = $this->pointer->current();
        $this->assertSame('slot', $current['kind']);
        $this->assertSame($this->targetReleaseId, $current['release_id']);
        $this->assertSame('0.1.0-alpha.2', $current['product_version']);

        $state = $this->journal->state();
        $this->assertIsArray($state);
        $this->assertSame('recovery_required', $state['phase']);
        $this->assertSame('update_target_bootstrap_failed', $state['recovery_code'] ?? null);
        $this->assertFalse($state['recovery_reverted_to_source'] ?? true);
        $this->assertTrue($state['migration_started'] ?? false);
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('BOOTSTRAP_SECRET', $encoded);
        $this->assertStringNotContainsString($this->directory, $encoded);
    }

    public function test_fatal_target_bootstrap_has_sanitized_shutdown_recovery(): void
    {
        $this->seedJournal('root', null, '0.1.0-alpha.1');
        File::put(
            $this->releaseRoot.'/'.$this->targetReleaseId.'/vendor/autoload.php',
            "<?php ini_set('memory_limit', '16M'); str_repeat('x', 32 * 1024 * 1024);",
        );
        $this->pointer->activateSlot($this->targetReleaseId, '0.1.0-alpha.2', $this->targetDigest);
        [$exitCode, $output] = $this->runPublicLauncher();

        $this->assertSame(1, $exitCode);
        $this->assertSame('Application update recovery is required.', trim($output));
        $this->assertSame('root', $this->pointer->current()['kind']);
        $this->assertStringNotContainsString($this->directory, $output);
    }

    public function test_target_journal_mismatch_never_reverts_an_unproven_release(): void
    {
        $this->seedJournal('root', null, '0.1.0-alpha.1');
        $state = $this->journal->state();
        $state['target_release_id'] = 'unrelated-release';
        File::put($this->journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        $this->pointer->activateSlot($this->targetReleaseId, '0.1.0-alpha.2', $this->targetDigest);
        $this->runPublicLauncher();

        $this->assertSame($this->targetReleaseId, $this->pointer->current()['release_id']);
        $this->assertSame('recovery_required', $this->journal->state()['phase']);
        $this->assertFalse($this->journal->state()['recovery_reverted_to_source']);
    }

    public function test_custom_mutex_configuration_requires_manual_recovery_instead_of_using_another_lock(): void
    {
        $this->seedJournal('root', null, '0.1.0-alpha.1');
        $state = $this->journal->state();
        $state['lifecycle_mutex_path_sha256'] = hash('sha256', '/custom/lifecycle.lock');
        File::put($this->journalPath, json_encode($state, JSON_THROW_ON_ERROR));
        $this->pointer->activateSlot($this->targetReleaseId, '0.1.0-alpha.2', $this->targetDigest);
        $this->runPublicLauncher();

        $this->assertSame($this->targetReleaseId, $this->pointer->current()['release_id']);
        $this->assertSame('target_active', $this->journal->state()['phase']);
    }

    public function test_another_lifecycle_owner_blocks_pointer_and_journal_recovery_writes(): void
    {
        $this->seedJournal('root', null, '0.1.0-alpha.1');
        $this->pointer->activateSlot($this->targetReleaseId, '0.1.0-alpha.2', $this->targetDigest);
        $ownership = (new LifecycleMutex($this->installationRoot.'/storage/app/lifecycle/lifecycle.lock'))->acquire('restore');
        try {
            $this->runPublicLauncher();
            $this->assertSame($this->targetReleaseId, $this->pointer->current()['release_id']);
            $this->assertSame('target_active', $this->journal->state()['phase']);
        } finally {
            $ownership->release();
        }
    }

    private function seedJournal(string $sourceKind, ?string $sourceReleaseId, string $sourceVersion): void
    {
        $uploadId = str_repeat('c', 32);
        $this->journal->startUpload($uploadId);
        $this->journal->transition('uploaded', 'verified', [
            'package_digest_sha256' => $this->targetDigest,
            'target_product_version' => '0.1.0-alpha.2',
        ]);
        $this->journal->transition('verified', 'preparing', [
            'lifecycle_mutex_path_sha256' => hash('sha256', $this->installationRoot.'/storage/app/lifecycle/lifecycle.lock'),
            'source_release_kind' => $sourceKind,
            'source_release_id' => $sourceReleaseId,
            'source_product_version' => $sourceVersion,
            'source_schema_fingerprint' => str_repeat('d', 64),
        ]);
        $this->journal->transition('preparing', 'quiescing');
        $this->journal->transition('quiescing', 'snapshot_ready', [
            'safety_snapshot_id' => $uploadId,
            'pre_update_migration_history_sha256' => str_repeat('e', 64),
        ]);
        $this->journal->transition('snapshot_ready', 'staged', [
            'target_release_id' => $this->targetReleaseId,
            'target_product_version' => '0.1.0-alpha.2',
            'target_schema_fingerprint' => str_repeat('f', 64),
        ]);
        $this->journal->transition('staged', 'switch_pending');
        $this->journal->transition('switch_pending', 'target_active', [
            'migration_started' => false,
        ]);
    }

    private function seedSlot(
        string $releaseId,
        string $version,
        string $digest,
        bool $brokenBootstrap = false,
    ): void {
        $slot = $this->releaseRoot.'/'.$releaseId;
        File::ensureDirectoryExists($slot.'/vendor');
        File::ensureDirectoryExists($slot.'/bootstrap');
        File::put($slot.'/VERSION', $version."\n");
        File::put($slot.'/release.json', json_encode([
            'schema' => 'seo-release-slot/1',
            'release_id' => $releaseId,
            'product_version' => $version,
            'package_digest_sha256' => $digest,
            'launcher_schema' => 'seo-launcher/1',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        @chmod($slot.'/release.json', 0600);

        if ($brokenBootstrap) {
            File::put(
                $slot.'/vendor/autoload.php',
                "<?php\nthrow new RuntimeException('BOOTSTRAP_SECRET {$this->directory}');\n",
            );
        } else {
            File::put($slot.'/vendor/autoload.php', "<?php\n");
        }
        File::put($slot.'/bootstrap/app.php', "<?php\nreturn null;\n");
    }

    /** @return array{0: ?int, 1: string} */
    private function runPublicLauncher(): array
    {
        $wrapper = $this->directory.'/run-public.php';
        File::put(
            $wrapper,
            "<?php\n\$_SERVER['REQUEST_URI'] = '/settings/update/continue';\nrequire ".var_export($this->installationRoot.'/public/index.php', true).";\n",
        );

        $process = new Process([PHP_BINARY, $wrapper], $this->installationRoot, timeout: 10);
        $process->run();

        return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    }
}
