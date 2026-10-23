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
        $this->runner = new UpdateMigrationRunner($this->app->make(Migrator::class), migrationPath: $this->directory, attemptPath: $this->directory.'/attempt.json');
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
        $after = $this->runner->runPending(UpdateMigrationState::fromArray($first->toArray()), function () use (&$events): void {
            $events++;
        });
        $this->assertSame(1, $events);
        $this->assertCount(2, $after->appliedSuffix);
    }

    public function test_unlogged_trigger_ddl_requires_recovery_even_when_columns_are_unchanged(): void
    {
        $this->writeMigration('2099_01_01_000001_trigger', "Schema::create('update_probe_first', fn (Blueprint \$table) => \$table->id());", "Schema::drop('update_probe_first');");
        $before = $this->runner->runPending($this->runner->snapshot(), static function (): void {});
        $this->writeMigration('2099_01_01_000002_trigger_fail', "\\Illuminate\\Support\\Facades\\DB::unprepared('CREATE TRIGGER update_probe_trigger AFTER INSERT ON update_probe_first BEGIN SELECT 1; END'); throw new \\RuntimeException('private');", '');
        try {
            $this->runner->runPending($before, static function (): void {});
            $this->fail('Unlogged triggers must not be mistaken for unchanged schema.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
    }

    public function test_interrupted_reverse_resumes_only_the_exact_proven_remaining_prefix(): void
    {
        $this->writeMigration('2099_01_01_000001_first', "Schema::create('update_probe_first', fn (Blueprint \$table) => \$table->id());", "Schema::drop('update_probe_first');");
        $this->writeMigration('2099_01_01_000002_second', "Schema::create('update_probe_second', fn (Blueprint \$table) => \$table->id());", "Schema::drop('update_probe_second');");
        $before = $this->runner->snapshot();
        $after = $this->runner->runPending($before, static function (): void {});
        $checkpoints = [];
        try {
            $this->runner->rollbackExactSuffix($before, $after->appliedSuffix, function (UpdateMigrationState $state) use (&$checkpoints): void {
                $checkpoints[] = $state;
                throw new \RuntimeException('simulated process interruption');
            });
            $this->fail('The reverse checkpoint must be published after each completed down.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated process interruption', $exception->getMessage());
        }
        $this->assertCount(1, $checkpoints);
        $this->assertTrue(Schema::hasTable('update_probe_first'));
        $this->assertFalse(Schema::hasTable('update_probe_second'));
        $this->runner->rollbackExactSuffix($before, $after->appliedSuffix);
        $this->assertSame($before->toArray(), $this->runner->snapshot()->toArray());
    }

    public function test_unlogged_data_mutation_is_never_retried_from_an_unchanged_schema_checkpoint(): void
    {
        $owner = User::factory()->create(['name' => 'source']);
        $before = $this->runner->snapshot();
        $up = "\\Illuminate\\Support\\Facades\\DB::table('users')->update(['name' => 'changed']); throw new \\RuntimeException('private failure');";
        $this->writeMigration('2099_01_01_000001_data', $up, '');
        foreach ([1, 2] as $attempt) {
            try {
                $this->runner->runPending($before, static function (): void {});
                $this->fail('An ambiguous data mutation must not run again.');
            } catch (UpdateException $exception) {
                $this->assertSame('update_recovery_required', $exception->codeName());
            }
            $this->assertSame('changed', $owner->fresh()->name);
            $this->assertFileExists($this->directory.'/attempt.json');
        }
    }

    public function test_unlogged_data_only_down_is_not_repeated_after_failure(): void
    {
        DB::table('cache')->insert(['key' => 'reverse-probe', 'value' => 'preserved', 'expiration' => 0]);
        $before = $this->runner->snapshot();
        $down = "\\Illuminate\\Support\\Facades\\DB::table('cache')->where('key', 'reverse-probe')->increment('expiration'); throw new \\RuntimeException('private failure');";
        $this->writeMigration('2099_01_01_000001_data', '', $down);
        $after = $this->runner->runPending($before, static function (): void {});
        foreach ([1, 2] as $attempt) {
            try {
                $this->runner->rollbackExactSuffix($before, $after->appliedSuffix);
                $this->fail('An ambiguous down must not run again.');
            } catch (UpdateException $exception) {
                $this->assertSame('update_recovery_required', $exception->codeName());
            }
            $this->assertSame(1, DB::table('cache')->where('key', 'reverse-probe')->value('expiration'));
        }
    }

    private function writeMigration(string $name, string $up, string $down): void
    {
        File::put($this->directory.'/'.$name.'.php', "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public \$withinTransaction = false; public function up(): void { {$up} } public function down(): void { {$down} } };\n");
    }
}
