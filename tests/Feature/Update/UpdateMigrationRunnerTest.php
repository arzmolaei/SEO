<?php

namespace Tests\Feature\Update;

use App\Models\User;
use App\Services\Update\UpdateException;
use App\Services\Update\UpdateMigrationRunner;
use App\Services\Update\UpdateMigrationState;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UpdateMigrationRunnerTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private UpdateMigrationRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/seo-update-migrations-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
        $this->runner = new UpdateMigrationRunner($this->app->make(Migrator::class), migrationPath: $this->directory);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('update_probe_second');
        Schema::dropIfExists('update_probe_first');
        Schema::dropIfExists('update_probe_unlogged');
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function test_pending_files_publish_exact_restartable_checkpoints_and_reverse_only_the_verified_suffix(): void
    {
        $owner = User::factory()->create();
        $this->writeMigration('2099_01_01_000001_first', "Schema::create('update_probe_first', function (Blueprint \$table): void { \$table->id(); });", "Schema::drop('update_probe_first');");
        $this->writeMigration('2099_01_01_000002_second', "Schema::create('update_probe_second', function (Blueprint \$table): void { \$table->id(); \$table->foreignId('parent_id')->constrained('update_probe_first'); });", "Schema::drop('update_probe_second');");
        $before = $this->runner->snapshot();
        $seen = [];
        $after = $this->runner->runPending($before, function (UpdateMigrationState $state) use (&$seen): void {
            $seen[] = UpdateMigrationState::fromArray($state->toArray());
        });
        $this->assertCount(2, $seen);
        $this->assertSame(['2099_01_01_000001_first', '2099_01_01_000002_second'], array_column($after->appliedSuffix, 'migration'));
        $this->assertSame([...$before->ran, ...array_map(static fn (array $row): array => ['migration' => $row['migration'], 'batch' => $row['batch']], $after->appliedSuffix)], $after->ran);
        $this->assertSame($after->toArray(), $seen[1]->toArray());
        $this->assertTrue(Schema::hasTable('update_probe_second'));

        $this->runner->rollbackExactSuffix($before, $after->appliedSuffix);
        $this->assertSame($before->toArray(), $this->runner->snapshot()->toArray());
        $this->assertFalse(Schema::hasTable('update_probe_first'));
        $this->assertFalse(Schema::hasTable('update_probe_second'));
        $this->assertTrue(User::query()->whereKey($owner->id)->exists());
    }

    public function test_history_drift_blocks_forward_migration_before_any_schema_change(): void
    {
        $before = $this->runner->snapshot();
        $this->writeMigration('2099_01_01_000001_first', "Schema::create('update_probe_first', fn (Blueprint \$table) => \$table->id());", "Schema::drop('update_probe_first');");
        DB::table('migrations')->insert(['migration' => 'unexpected_manual_migration', 'batch' => 99]);
        try {
            $this->runner->runPending($before, static function (): void {});
            $this->fail('Repository drift must block migration.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertFalse(Schema::hasTable('update_probe_first'));
    }

    public function test_unlogged_partial_ddl_fails_closed_without_a_guessed_down(): void
    {
        $this->writeMigration('2099_01_01_000001_unlogged', "Schema::create('update_probe_unlogged', fn (Blueprint \$table) => \$table->id()); throw new \\RuntimeException('private failure');", "Schema::drop('update_probe_unlogged');");
        $before = $this->runner->snapshot();
        try {
            $this->runner->runPending($before, static function (): void {});
            $this->fail('Partial unlogged DDL must require recovery.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertTrue(Schema::hasTable('update_probe_unlogged'));
        $this->assertSame($before->ran, $this->runner->snapshot()->ran);
    }

    public function test_extra_history_or_schema_drift_refuses_suffix_rollback_without_touching_target_data(): void
    {
        $this->writeMigration('2099_01_01_000001_first', "Schema::create('update_probe_first', fn (Blueprint \$table) => \$table->id());", "Schema::drop('update_probe_first');");
        $before = $this->runner->snapshot();
        $after = $this->runner->runPending($before, static function (): void {});
        DB::table('migrations')->insert(['migration' => 'unrelated_after_update', 'batch' => 100]);
        try {
            $this->runner->rollbackExactSuffix($before, $after->appliedSuffix);
            $this->fail('Extra history must block rollback.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        DB::table('migrations')->where('migration', 'unrelated_after_update')->delete();
        Schema::create('update_probe_unlogged', fn ($table) => $table->id());
        try {
            $this->runner->rollbackExactSuffix($before, $after->appliedSuffix);
            $this->fail('Unlogged schema drift must block rollback.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertTrue(Schema::hasTable('update_probe_first'));
    }

    public function test_restart_at_a_persisted_checkpoint_runs_only_remaining_files(): void
    {
        $this->writeMigration('2099_01_01_000001_first', "Schema::create('update_probe_first', fn (Blueprint \$table) => \$table->id());", "Schema::drop('update_probe_first');");
        $first = $this->runner->runPending($this->runner->snapshot(), static function (): void {});
        $this->writeMigration('2099_01_01_000002_second', "Schema::create('update_probe_second', fn (Blueprint \$table) => \$table->id());", "Schema::drop('update_probe_second');");
        $events = 0;
        $after = $this->runner->runPending(UpdateMigrationState::fromArray($first->toArray()), function () use (&$events): void { $events++; });
        $this->assertSame(1, $events);
        $this->assertCount(2, $after->appliedSuffix);
    }

    private function writeMigration(string $name, string $up, string $down): void
    {
        File::put($this->directory.'/'.$name.'.php', "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public \$withinTransaction = false; public function up(): void { {$up} } public function down(): void { {$down} } };\n");
    }
}
