<?php

namespace Tests\Feature\Update;

use App\Models\User;
use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Update\RecoverInterruptedUpdate;
use App\Services\Update\UpdateException;
use App\Services\Update\UpdateJournal;
use App\Services\Update\UpdateMigrationRunner;
use App\Services\Update\UpdateMigrationState;
use App\Support\InstallationState;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\UpdateTargetTestCase;

class RecoverInterruptedUpdateTest extends UpdateTargetTestCase
{
    protected bool $withSafetySnapshot = true;

    protected function setUp(): void
    {
        parent::setUp();
        File::ensureDirectoryExists($this->targetRoot.'/database/migrations', 0700);
        File::ensureDirectoryExists($this->stableRoot.'/bootstrap/cache', 0700);
        File::ensureDirectoryExists($this->stableRoot.'/vendor', 0700);
        foreach (['bootstrap/app.php', 'bootstrap/runtime-release.php', 'vendor/autoload.php'] as $path) {
            File::put($this->stableRoot.'/'.$path, 'source runtime fixture');
        }
        // Source health must inspect the actual source assets, never target assets.
        File::put($this->stableRoot.'/public/build/manifest.json', json_encode([
            'resources/js/app.tsx' => ['file' => 'assets/source-hash.js', 'isEntry' => true],
        ], JSON_THROW_ON_ERROR));
        $runner = $this->app->make(UpdateMigrationRunner::class);
        $before = $runner->snapshot();
        $sourceSchema = $this->app->make(ApplicationSchemaFingerprint::class)->forConnection(DB::connection());
        $this->evidence = [...$this->evidence,
            'phase' => 'rollback_required', 'migration_started' => true,
            'source_release_kind' => 'root', 'source_release_id' => null,
            'source_schema_fingerprint' => $sourceSchema,
            'safety_snapshot_id' => str_repeat('c', 32),
            'safety_snapshot_sha256' => hash_file('sha256', $this->safetyPath()),
            'lifecycle_mutex_path_sha256' => hash('sha256', (string) realpath((string) config('backup.lifecycle_mutex_path'))),
            'pre_update_migration_history_sha256' => $before->digestSha256,
            'pre_update_migration_state' => $before->toArray(),
        ];
        foreach (['first', 'second'] as $index => $name) {
            $column = 'recovery_probe_'.$index;
            $injection = $index === 1 ? 'if (is_file('.var_export($this->stableRoot.'/inject-down-publication', true).')) { mkdir('.var_export($this->stableRoot.'/storage/app/lifecycle/update/state.json.tmp', true).'); }' : '';
            File::put($this->targetRoot.'/database/migrations/2099_01_01_00000'.($index + 1).'_'.$name.'.php', "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public \$withinTransaction = false; public function up(): void { Schema::table('projects', fn (Blueprint \$table) => \$table->string('{$column}')->nullable()); } public function down(): void { Schema::table('projects', fn (Blueprint \$table) => \$table->dropColumn('{$column}')); {$injection} } };\n");
        }
        $cursor = $runner->runPending($before, static function (): void {});
        $this->evidence['migration_state'] = $cursor->toArray();
        $this->saveState();
    }

    protected function tearDown(): void
    {
        foreach (['recovery_probe_1', 'recovery_probe_0'] as $column) {
            if (Schema::hasColumn('projects', $column)) {
                Schema::table('projects', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        parent::tearDown();
    }

    public function test_exact_down_then_separate_source_request_restores_data_files_identity_and_preserves_pending_work_and_environment(): void
    {
        $originalName = $this->owner->name;
        $details = (new InstallationState)->details();
        $environment = File::get($this->stableRoot.'/.env');
        $legacy = (new Encrypter(str_repeat('p', 32), 'AES-256-CBC'))->encryptString('متن قدیمی');
        $this->owner->update(['name' => 'changed after snapshot']);
        File::put($this->stableRoot.'/storage/app/private/uploads/source.txt', 'changed');
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'pending', 'attempts' => 0, 'reserved_at' => null, 'available_at' => 123, 'created_at' => 120]);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $this->owner->id, 'ip_address' => null, 'user_agent' => null, 'payload' => 'old', 'last_activity' => time()]);
        $this->assertSame('snapshot_restore_required', $this->recover()['status']);
        $this->assertSame('root', $this->pointer->current()['kind']);
        $this->assertFalse(Schema::hasColumn('projects', 'recovery_probe_0'));
        $this->assertFalse(Schema::hasColumn('projects', 'recovery_probe_1'));
        $this->assertSame('changed after snapshot', User::findOrFail($this->owner->id)->name);
        $this->assertFileExists($this->safetyPath());
        // Target-loaded application must never apply a source-version snapshot.
        try {
            $this->recover();
            $this->fail('Source snapshot requires a fresh source request.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_invalid_state', $exception->codeName());
        }
        $this->bootSource();
        $this->assertSame(['status' => 'rolled_back', 'product_version' => '0.1.0-alpha.1'], $this->recover());
        $this->assertSame($originalName, User::findOrFail($this->owner->id)->name);
        $this->assertSame('source durable content', File::get($this->stableRoot.'/storage/app/private/uploads/source.txt'));
        $this->assertSame($environment, File::get($this->stableRoot.'/.env'));
        $this->assertSame($details, (new InstallationState)->details());
        $this->assertSame('pending', DB::table('jobs')->value('payload'));
        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertSame('متن قدیمی', Crypt::decryptString($legacy));
        $this->assertNull($this->app->make(UpdateJournal::class)->state());
        $this->assertFileExists($this->stableRoot.'/public/build/assets/source-hash.js');
        $this->assertFileExists($this->stableRoot.'/public/build/assets/target-hash.js');
    }

    public function test_slot_source_is_selected_by_its_exact_recorded_identity(): void
    {
        $slot = $this->sourceSlot();
        $this->evidence['source_release_kind'] = 'slot';
        $this->evidence['source_release_id'] = 'source-probe';
        $this->evidence['source_package_digest_sha256'] = str_repeat('e', 64);
        $this->saveState();
        $this->assertSame('snapshot_restore_required', $this->recover()['status']);
        $this->assertSame('source-probe', $this->pointer->current()['release_id']);
        $this->bootSource($slot);
        $this->assertSame('rolled_back', $this->recover()['status']);
    }

    public function test_interrupted_down_with_completed_receipt_resumes_without_repeating_the_down(): void
    {
        $this->evidence['phase'] = 'migration_rollback';
        $this->evidence['rollback_migration_state'] = $this->evidence['migration_state'];
        $this->saveState();
        $before = UpdateMigrationState::fromArray($this->evidence['pre_update_migration_state']);
        try {
            $this->app->make(UpdateMigrationRunner::class)->rollbackExactSuffix($before, $this->evidence['migration_state']['applied_suffix'], static function (): void {
                throw new \RuntimeException('interrupted before checkpoint');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('interrupted before checkpoint', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('projects', 'recovery_probe_0'));
        $this->assertFalse(Schema::hasColumn('projects', 'recovery_probe_1'));
        $this->assertSame('snapshot_restore_required', $this->recover()['status']);
        $this->assertFalse(Schema::hasColumn('projects', 'recovery_probe_0'));
    }

    public function test_interruption_after_source_pointer_switch_resumes_in_source_instead_of_loading_target_code(): void
    {
        $before = UpdateMigrationState::fromArray($this->evidence['pre_update_migration_state']);
        $this->app->make(UpdateMigrationRunner::class)->rollbackExactSuffix($before, $this->evidence['migration_state']['applied_suffix']);
        $this->evidence['phase'] = 'source_switch_pending';
        $this->evidence['rollback_migration_state'] = $before->toArray();
        $this->saveState();
        $this->pointer->activateRoot('0.1.0-alpha.1');
        $this->bootSource();
        $this->assertSame('rolled_back', $this->recover()['status']);
    }

    public function test_extra_migration_history_is_retained_without_any_guessed_down_or_source_switch(): void
    {
        DB::table('migrations')->insert(['migration' => 'unexpected_manual_change', 'batch' => 99]);
        $this->assertRecoveryBlocked();
        $this->assertTrue(Schema::hasColumn('projects', 'recovery_probe_1'));
        $this->assertSame('target-probe', $this->pointer->current()['release_id']);
        $this->assertTrue(DB::table('migrations')->where('migration', 'unexpected_manual_change')->exists());
    }

    public function test_unlogged_schema_drift_is_retained_without_a_guessed_down(): void
    {
        Schema::create('unrecognized_probe', fn (Blueprint $table) => $table->id());
        $this->assertRecoveryBlocked();
        $this->assertTrue(Schema::hasTable('unrecognized_probe'));
        $this->assertTrue(Schema::hasColumn('projects', 'recovery_probe_1'));
    }

    public function test_corrupt_snapshot_blocks_before_reverse_migration_and_retains_all_artifacts(): void
    {
        File::put($this->safetyPath(), 'corrupt snapshot');
        $this->assertRecoveryBlocked();
        $this->assertTrue(Schema::hasColumn('projects', 'recovery_probe_1'));
        $this->assertFileExists($this->targetRoot.'/release.json');
    }

    public function test_missing_source_slot_blocks_before_reverse_migration(): void
    {
        $this->evidence['source_release_kind'] = 'slot';
        $this->evidence['source_release_id'] = 'missing-source';
        $this->evidence['source_package_digest_sha256'] = str_repeat('e', 64);
        $this->saveState();
        $this->assertRecoveryBlocked();
        $this->assertTrue(Schema::hasColumn('projects', 'recovery_probe_1'));
    }

    public function test_wrong_active_pointer_never_authorizes_reverse_migration(): void
    {
        $this->pointer->activateRoot('0.1.0-alpha.1');
        try {
            $this->recover();
            $this->fail('Wrong active release must be rejected.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_invalid_state', $exception->codeName());
        }
        $this->assertTrue(Schema::hasColumn('projects', 'recovery_probe_1'));
    }

    public function test_runtime_secret_drift_blocks_recovery_without_overwriting_the_changed_environment(): void
    {
        File::append($this->stableRoot.'/.env', "MAIL_PASSWORD=locally-changed\n");
        $this->assertRecoveryBlocked();
        $this->assertStringContainsString('locally-changed', File::get($this->stableRoot.'/.env'));
        $this->assertTrue(Schema::hasColumn('projects', 'recovery_probe_1'));
    }

    public function test_missing_source_autoloader_blocks_before_any_reverse_migration(): void
    {
        File::delete($this->stableRoot.'/vendor/autoload.php');
        $this->assertRecoveryBlocked();
        $this->assertTrue(Schema::hasColumn('projects', 'recovery_probe_1'));
        $this->assertSame('target-probe', $this->pointer->current()['release_id']);
    }

    public function test_checkpoint_publication_failure_preserves_completed_down_evidence_for_retry(): void
    {
        $block = $this->stableRoot.'/storage/app/lifecycle/update/state.json.tmp';
        File::put($this->stableRoot.'/inject-down-publication', 'armed');
        try {
            $this->recover();
            $this->fail('Checkpoint publication must fail at the injected interruption.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_retry', $exception->codeName());
        }
        $this->assertFalse(Schema::hasColumn('projects', 'recovery_probe_1'));
        $this->assertTrue(Schema::hasColumn('projects', 'recovery_probe_0'));
        $this->assertFileExists($this->stableRoot.'/storage/app/lifecycle/update/migration-attempt.json');
        rmdir($block);
        File::delete($this->stableRoot.'/inject-down-publication');
        $this->assertSame('snapshot_restore_required', $this->recover()['status']);
    }

    public function test_publication_failure_after_source_pointer_switch_preserves_a_retryable_source_phase(): void
    {
        $before = UpdateMigrationState::fromArray($this->evidence['pre_update_migration_state']);
        $this->app->make(UpdateMigrationRunner::class)->rollbackExactSuffix($before, $this->evidence['migration_state']['applied_suffix']);
        $this->evidence['phase'] = 'source_switch_pending';
        $this->evidence['rollback_migration_state'] = $before->toArray();
        $this->saveState();
        $block = $this->stableRoot.'/storage/app/lifecycle/update/state.json.tmp';
        mkdir($block);
        try {
            $this->recover();
            $this->fail('Publishing the post-switch phase must fail.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_retry', $exception->codeName());
        }
        $this->assertSame('root', $this->pointer->current()['kind']);
        $this->assertSame('source_switch_pending', $this->app->make(UpdateJournal::class)->state()['phase']);
        rmdir($block);
        $this->bootSource();
        $this->assertSame('rolled_back', $this->recover()['status']);
    }

    public function test_source_snapshot_staging_failure_is_retryable_without_losing_safety_evidence(): void
    {
        $this->recover();
        $this->bootSource();
        $block = $this->stableRoot.'/storage/app/lifecycle/update/recovery';
        File::put($block, 'temporary filesystem obstruction');
        try {
            $this->recover();
            $this->fail('Staging must fail before applying the protected snapshot.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_retry', $exception->codeName());
        }
        $this->assertSame('snapshot_restore_required', $this->app->make(UpdateJournal::class)->state()['phase']);
        File::delete($block);
        $this->assertSame('rolled_back', $this->recover()['status']);
    }

    public function test_bootstrap_failure_proven_before_migrations_restores_source_from_its_protected_snapshot(): void
    {
        $before = UpdateMigrationState::fromArray($this->evidence['pre_update_migration_state']);
        $this->app->make(UpdateMigrationRunner::class)->rollbackExactSuffix($before, $this->evidence['migration_state']['applied_suffix']);
        $this->evidence['phase'] = 'recovery_required';
        $this->evidence['migration_started'] = false;
        $this->evidence['recovery_reverted_to_source'] = true;
        $this->evidence['recovery_code'] = 'update_target_bootstrap_failed';
        unset($this->evidence['migration_state']);
        $this->saveState();
        $this->pointer->activateRoot('0.1.0-alpha.1');
        $this->bootSource();
        $this->assertSame('rolled_back', $this->recover()['status']);
        $this->assertNull($this->app->make(UpdateJournal::class)->state());
    }

    public function test_recovery_required_after_ambiguous_migration_never_uses_the_bootstrap_only_escape(): void
    {
        $this->evidence['phase'] = 'recovery_required';
        $this->evidence['recovery_reverted_to_source'] = true;
        $this->evidence['recovery_code'] = 'update_target_bootstrap_failed';
        $this->saveState();
        try {
            $this->recover();
            $this->fail('A bootstrap failure after migration began cannot authorize automatic source restore.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_invalid_state', $exception->codeName());
        }
        $this->assertTrue(Schema::hasColumn('projects', 'recovery_probe_1'));
        $this->assertSame('target-probe', $this->pointer->current()['release_id']);
    }

    public function test_http_recovery_requires_a_source_reload_then_restores_and_demands_fresh_login(): void
    {
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/settings/update/recover')->assertOk()
            ->assertJsonPath('status', 'snapshot_restore_required')->assertJsonPath('requires_reload', true);
        $this->assertSame('root', $this->pointer->current()['kind']);
        $this->bootSource();
        $this->postJson('/settings/update/recover')->assertOk()
            ->assertJsonPath('status', 'rolled_back')->assertJsonPath('requires_login', true);
        $this->assertGuest();
        $this->assertNull($this->app->make(UpdateJournal::class)->state());
    }

    private function assertRecoveryBlocked(): void
    {
        try {
            $this->recover();
            $this->fail('Ambiguous evidence must keep maintenance and safety artifacts.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertSame('recovery_required', $this->app->make(UpdateJournal::class)->state()['phase']);
        $this->assertFileExists($this->safetyPath());
    }

    private function bootSource(?string $root = null): void
    {
        $this->app->setBasePath($root ?? $this->stableRoot);
        $this->app->forgetInstance(ProductVersion::class);
    }

    private function sourceSlot(): string
    {
        $slot = dirname($this->targetRoot).'/source-probe';
        File::copyDirectory($this->targetRoot, $slot);
        File::put($slot.'/VERSION', "0.1.0-alpha.1\n");
        File::put($slot.'/release.json', json_encode([
            'schema' => 'seo-release-slot/1', 'release_id' => 'source-probe',
            'product_version' => '0.1.0-alpha.1', 'package_digest_sha256' => str_repeat('e', 64),
            'launcher_schema' => 'seo-launcher/1',
        ], JSON_THROW_ON_ERROR));
        File::put($slot.'/public/build/manifest.json', File::get($this->stableRoot.'/public/build/manifest.json'));
        File::put($slot.'/public/build/assets/source-hash.js', 'source asset');

        return $slot;
    }

    private function recover(): array
    {
        return $this->app->make(RecoverInterruptedUpdate::class)->handle();
    }

    private function saveState(): void
    {
        File::put($this->stableRoot.'/storage/app/lifecycle/update/state.json', json_encode($this->evidence, JSON_THROW_ON_ERROR));
    }

    private function safetyPath(): string
    {
        return (string) config('update.safety_path').'/'.str_repeat('c', 32).'.seobak';
    }
}
