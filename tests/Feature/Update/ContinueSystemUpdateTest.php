<?php

namespace Tests\Feature\Update;

use App\Models\User;
use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\CaptureSystemState;
use App\Services\Update\ContinueSystemUpdate;
use App\Services\Update\UpdateException;
use App\Services\Update\UpdateJournal;
use App\Services\Update\UpdateMigrationRunner;
use App\Services\Update\UpdateMigrationState;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\UpdateTargetTestCase;

class ContinueSystemUpdateTest extends UpdateTargetTestCase
{
    protected bool $withSafetySnapshot = true;

    private UpdateMigrationRunner $runner;

    private UpdateMigrationState $before;

    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists($this->targetRoot.'/database/migrations', 0700);
        foreach (['a', 'b'] as $suffix) {
            $column = 'update_probe_'.$suffix;
            $name = $suffix === 'a' ? '2099_01_01_000001_first' : '2099_01_01_000002_second';
            File::put($this->targetRoot.'/database/migrations/'.$name.'.php', "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public \$withinTransaction = false; public function up(): void { Schema::table('projects', fn (Blueprint \$table) => \$table->string('{$column}')->nullable()); } public function down(): void { Schema::table('projects', fn (Blueprint \$table) => \$table->dropColumn('{$column}')); } };\n");
        }
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('update_probe_a')->nullable();
            $table->string('update_probe_b')->nullable();
        });
        $targetSchema = $this->app->make(ApplicationSchemaFingerprint::class)->forConnection(DB::connection());
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('update_probe_b'));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('update_probe_a'));
        $this->runner = $this->app->make(UpdateMigrationRunner::class);
        $this->before = $this->runner->snapshot();
        $this->evidence = [
            ...$this->evidence,
            'phase' => 'target_active', 'migration_started' => false,
            'source_release_kind' => 'root', 'source_release_id' => null,
            'source_schema_fingerprint' => $this->app->make(ApplicationSchemaFingerprint::class)->forConnection(DB::connection()),
            'target_schema_fingerprint' => $targetSchema,
            'safety_snapshot_id' => str_repeat('c', 32),
            'safety_snapshot_sha256' => hash_file('sha256', $this->safetyPath()),
            'lifecycle_mutex_path_sha256' => hash('sha256', (string) realpath((string) config('backup.lifecycle_mutex_path'))),
            'pre_update_migration_history_sha256' => $this->before->digestSha256,
            'pre_update_migration_state' => $this->before->toArray(),
        ];
        $this->writeState($this->evidence);
    }

    protected function tearDown(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            foreach (['update_probe_b', 'update_probe_a'] as $column) {
                if (Schema::hasColumn('projects', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        parent::tearDown();
    }

    public function test_fresh_target_migrates_finalizes_only_version_and_preserves_local_state_sessions_and_pending_work(): void
    {
        $details = (new InstallationState)->details();
        $environment = File::get($this->stableRoot.'/.env');
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'queued payload', 'attempts' => 0, 'reserved_at' => null, 'available_at' => 123, 'created_at' => 120]);
        DB::table('sessions')->insert(['id' => 'source-session', 'user_id' => $this->owner->id, 'ip_address' => null, 'user_agent' => null, 'payload' => 'source session', 'last_activity' => time()]);
        $result = $this->app->make(ContinueSystemUpdate::class)->handle();
        $this->assertSame(['status' => 'complete', 'product_version' => '0.1.0-alpha.2'], $result);
        $this->assertTrue(Schema::hasColumn('projects', 'update_probe_a'));
        $this->assertTrue(Schema::hasColumn('projects', 'update_probe_b'));
        $this->assertSame([...$details, 'product_version' => '0.1.0-alpha.2'], (new InstallationState)->details());
        $this->assertSame($environment, File::get($this->stableRoot.'/.env'));
        $this->assertSame('queued payload', DB::table('jobs')->value('payload'));
        $this->assertSame('source session', DB::table('sessions')->value('payload'));
        $this->assertTrue(User::query()->whereKey($this->owner->id)->exists());
        $this->assertSame('source durable content', File::get($this->stableRoot.'/storage/app/private/uploads/source.txt'));
        $this->assertNull($this->app->make(UpdateJournal::class)->state());
        $this->assertFileDoesNotExist($this->uploadPath());
        $this->assertFileExists($this->safetyPath());
        $this->assertFileExists($this->safetyMetadataPath());
        $retention = json_decode(File::get((string) config('update.retention_path')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('target-probe', $retention['target_release_id']);
        $this->assertSame('root', $retention['source_release_kind']);
        $this->assertStringNotContainsString($this->directory, json_encode($retention, JSON_THROW_ON_ERROR));
        $this->assertSame($result, $this->app->make(ContinueSystemUpdate::class)->handle());
    }

    public function test_source_process_cannot_continue_target_migrations_in_the_same_request_graph(): void
    {
        $this->app->setBasePath($this->stableRoot);
        try {
            $this->app->make(ContinueSystemUpdate::class)->handle();
            $this->fail('The source process must reload into the target before migration.');
        } catch (RuntimeException) {
            $this->assertFalse(Schema::hasColumn('projects', 'update_probe_a'));
            $this->assertSame('target_active', $this->app->make(UpdateJournal::class)->state()['phase']);
        }
    }

    public function test_target_health_failure_retains_target_and_exact_suffix_for_the_recovery_coordinator(): void
    {
        File::delete($this->stableRoot.'/public/build/assets/target-hash.js');
        try {
            $this->app->make(ContinueSystemUpdate::class)->handle();
            $this->fail('Missing target assets must not finalize the installation.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_health_failed', $exception->codeName());
        }
        $state = $this->app->make(UpdateJournal::class)->state();
        $this->assertSame('rollback_required', $state['phase']);
        $this->assertCount(2, $state['migration_state']['applied_suffix']);
        $this->assertSame('target-probe', $this->pointer->current()['release_id']);
        $this->assertSame('0.1.0-alpha.1', (new InstallationState)->details()['product_version']);
        $this->assertFileExists($this->safetyPath());
        $this->assertFileExists($this->uploadPath());
    }

    public function test_history_drift_fails_closed_without_source_switch(): void
    {
        DB::table('migrations')->insert(['migration' => 'unexpected_manual_migration', 'batch' => 99]);
        try {
            $this->app->make(ContinueSystemUpdate::class)->handle();
            $this->fail('History drift must not run target migrations.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertSame('recovery_required', $this->app->make(UpdateJournal::class)->state()['phase']);
        $this->assertFalse(Schema::hasColumn('projects', 'update_probe_a'));
        $this->assertSame('target-probe', $this->pointer->current()['release_id']);
        $this->assertFileExists($this->safetyPath());
    }

    public function test_unpublished_completed_checkpoint_is_republished_before_running_the_remaining_migration(): void
    {
        try {
            $this->runner->runPending($this->before, static function (): void {
                throw new RuntimeException('process interrupted before journal publication');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('process interrupted before journal publication', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('projects', 'update_probe_a'));
        $this->assertFalse(Schema::hasColumn('projects', 'update_probe_b'));
        $this->writeState([...$this->evidence, 'phase' => 'migrating', 'migration_started' => true, 'migration_state' => $this->before->toArray()]);
        $this->assertSame('complete', $this->app->make(ContinueSystemUpdate::class)->handle()['status']);
        $this->assertTrue(Schema::hasColumn('projects', 'update_probe_b'));
    }

    public function test_finalization_resumes_after_version_metadata_was_written_but_journal_was_not_completed(): void
    {
        $after = $this->runner->runPending($this->before, static function (): void {});
        (new InstallationState)->updateMetadata(['product_version' => '0.1.0-alpha.2']);
        $this->writeState([...$this->evidence, 'phase' => 'validating', 'migration_started' => true, 'migration_state' => $after->toArray()]);
        $this->assertSame('complete', $this->app->make(ContinueSystemUpdate::class)->handle()['status']);
        $this->assertNull($this->app->make(UpdateJournal::class)->state());
    }

    public function test_missing_or_corrupt_safety_bundle_blocks_before_any_migration(): void
    {
        File::put($this->safetyPath(), 'corrupt safety bundle');
        try {
            $this->app->make(ContinueSystemUpdate::class)->handle();
            $this->fail('Unusable rollback evidence must block target mutation.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertFalse(Schema::hasColumn('projects', 'update_probe_a'));
        $this->assertSame('recovery_required', $this->app->make(UpdateJournal::class)->state()['phase']);
    }

    public function test_unlogged_partial_target_ddl_retains_the_attempt_and_requires_recovery(): void
    {
        $path = $this->targetRoot.'/database/migrations/2099_01_01_000001_first.php';
        $migration = File::get($path);
        File::put($path, str_replace('} public function down()', "throw new \\RuntimeException('private migration failure'); } public function down()", $migration));
        try {
            $this->app->make(ContinueSystemUpdate::class)->handle();
            $this->fail('Unlogged partial target DDL must never be guessed through.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertTrue(Schema::hasColumn('projects', 'update_probe_a'));
        $this->assertFalse(Schema::hasColumn('projects', 'update_probe_b'));
        $this->assertSame('recovery_required', $this->app->make(UpdateJournal::class)->state()['phase']);
        $this->assertFileExists($this->stableRoot.'/storage/app/lifecycle/update/migration-attempt.json');
        $this->assertSame('target-probe', $this->pointer->current()['release_id']);
        $this->assertFileExists($this->safetyPath());
    }

    public function test_source_runtime_identity_drift_blocks_before_any_target_migration(): void
    {
        File::append($this->stableRoot.'/.env', "MAIL_PASSWORD=changed-private-value\n");
        try {
            $this->app->make(ContinueSystemUpdate::class)->handle();
            $this->fail('Changed source runtime evidence must block target mutation.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertFalse(Schema::hasColumn('projects', 'update_probe_a'));
        $this->assertSame('recovery_required', $this->app->make(UpdateJournal::class)->state()['phase']);
    }

    public function test_target_cannot_replace_the_source_lifecycle_lock_path_before_migration(): void
    {
        config(['backup.lifecycle_mutex_path' => $this->directory.'/different-lifecycle.lock']);
        try {
            $this->app->make(ContinueSystemUpdate::class)->handle();
            $this->fail('Target ownership must prove the exact source lifecycle lock.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertFalse(Schema::hasColumn('projects', 'update_probe_a'));
        $this->assertSame('recovery_required', $this->app->make(UpdateJournal::class)->state()['phase']);
    }

    public function test_a_valid_foreign_snapshot_cannot_replace_this_updates_captured_bundle(): void
    {
        $details = (new InstallationState)->details();
        $originalName = $this->owner->name;
        (new InstallationState)->restoreRecoverySnapshot([...$details, 'installation_id' => str_repeat('e', 32)]);
        $this->owner->update(['name' => 'foreign snapshot owner']);
        $this->app->instance(ProductVersion::class, new ProductVersion($this->stableRoot.'/VERSION'));
        $ownership = (new LifecycleMutex)->acquire('foreign-snapshot-fixture');
        try {
            $captured = $this->app->make(CaptureSystemState::class)->handle($ownership);
            try {
                $captured->rewind();
                File::put($this->safetyPath(), stream_get_contents($captured->stream()));
            } finally {
                $captured->close();
            }
        } finally {
            $ownership->release();
            (new InstallationState)->restoreRecoverySnapshot($details);
            $this->owner->update(['name' => $originalName]);
            $this->app->forgetInstance(ProductVersion::class);
        }
        try {
            $this->app->make(ContinueSystemUpdate::class)->handle();
            $this->fail('A valid but foreign backup is not this update safety snapshot.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertFalse(Schema::hasColumn('projects', 'update_probe_a'));
        $this->assertSame('recovery_required', $this->app->make(UpdateJournal::class)->state()['phase']);
        // Independently pin manifest identity even if a supplied digest matches
        // the replacement file: metadata still belongs to the original install.
        $this->evidence['safety_snapshot_sha256'] = hash_file('sha256', $this->safetyPath());
        $this->writeState($this->evidence);
        try {
            $this->app->make(ContinueSystemUpdate::class)->handle();
            $this->fail('Snapshot manifest identity must match recorded installation metadata.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertFalse(Schema::hasColumn('projects', 'update_probe_a'));
    }

    public function test_receipt_write_failure_after_metadata_update_retains_retryable_finalization(): void
    {
        $path = (string) config('update.retention_path');
        symlink($this->directory.'/receipt-write-blocked', $path);
        try {
            try {
                $this->app->make(ContinueSystemUpdate::class)->handle();
                $this->fail('An unsafe receipt path must not be written through.');
            } catch (UpdateException $exception) {
                $this->assertSame('update_finalization_retry', $exception->codeName());
            }
            $this->assertSame('0.1.0-alpha.2', (new InstallationState)->details()['product_version']);
            $this->assertSame('validating', $this->app->make(UpdateJournal::class)->state()['phase']);
            $this->assertFileExists($this->uploadPath());
        } finally {
            unlink($path);
        }
        $this->assertSame('complete', $this->app->make(ContinueSystemUpdate::class)->handle()['status']);
        $this->assertNull($this->app->make(UpdateJournal::class)->state());
    }

    public function test_http_continuation_rejects_source_request_then_finalizes_on_a_fresh_target_request(): void
    {
        $this->app->setBasePath($this->stableRoot);
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/settings/update/continue')->assertStatus(409)->assertJsonPath('code', 'update_invalid_state');
        $this->assertFalse(Schema::hasColumn('projects', 'update_probe_a'));
        $this->app->setBasePath($this->targetRoot);
        $this->postJson('/settings/update/continue')->assertOk()
            ->assertJsonPath('status', 'complete')->assertJsonPath('requires_reload', false)
            ->assertHeader('Cache-Control', 'no-cache, no-store, private');
        $this->assertSame('0.1.0-alpha.2', (new InstallationState)->details()['product_version']);
    }

    private function writeState(array $state): void
    {
        File::put($this->stableRoot.'/storage/app/lifecycle/update/state.json', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function safetyPath(): string
    {
        return (string) config('update.safety_path').'/'.str_repeat('c', 32).'.seobak';
    }

    private function safetyMetadataPath(): string
    {
        return (string) config('update.safety_path').'/'.str_repeat('c', 32).'.json';
    }

    private function uploadPath(): string
    {
        return (string) config('update.upload_path').'/'.str_repeat('c', 32).'.seoupdate';
    }
}
