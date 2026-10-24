<?php

namespace Tests\Feature\Acceptance;

use App\Services\Update\UpdateException;
use App\Services\Update\UpdateJournal;
use App\Services\Update\UpdateMigrationRunner;
use App\Services\Update\UpdateQuiescence;
use App\Support\Lifecycle\LifecycleMutex;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UpdateMigrationsMariaDbAcceptanceTest extends TestCase
{
    private string $directory;

    private UpdateMigrationRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('BACKUP_RESTORE_CONNECTED') !== '1') {
            $this->markTestSkipped('Migration DDL acceptance runs in the connected MariaDB gate.');
        }
        config(['database.connections.update_migrations' => [
            'driver' => 'mysql',
            'host' => getenv('BACKUP_SOURCE_DB_HOST'),
            'port' => getenv('BACKUP_SOURCE_DB_PORT'),
            'database' => getenv('BACKUP_SOURCE_DB_DATABASE'),
            'username' => getenv('BACKUP_SOURCE_DB_USERNAME'),
            'password' => getenv('BACKUP_SOURCE_DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]]);
        DB::purge('update_migrations');
        DB::setDefaultConnection('update_migrations');
        Artisan::call('migrate:fresh', ['--database' => 'update_migrations', '--force' => true]);
        $this->directory = sys_get_temp_dir().'/seo-mariadb-migrations-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
        $this->runner = new UpdateMigrationRunner($this->app->make(Migrator::class), 'update_migrations', $this->directory, $this->directory.'/attempt.json');
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            DB::connection('update_migrations')->getSchemaBuilder()->dropAllTables();
            File::deleteDirectory($this->directory);
            DB::purge('update_migrations');
        }
        parent::tearDown();
    }

    public function test_real_nontransactional_ddl_reverses_exact_suffix_and_ignores_mutable_row_counters(): void
    {
        $before = $this->runner->snapshot();
        DB::table('cache')->insert(['key' => 'row-state', 'value' => 'preserved', 'expiration' => 123]);
        $this->assertSame($before->schemaFingerprintSha256, $this->runner->snapshot()->schemaFingerprintSha256);
        $this->writeMigration('2099_01_01_000001_first', 'CREATE TABLE update_probe_first (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB', 'DROP TABLE update_probe_first');
        $this->writeMigration('2099_01_01_000002_second', 'CREATE TABLE update_probe_second (id BIGINT UNSIGNED PRIMARY KEY, parent_id BIGINT UNSIGNED, FOREIGN KEY (parent_id) REFERENCES update_probe_first(id)) ENGINE=InnoDB', 'DROP TABLE update_probe_second');
        $after = $this->runner->runPending($before, static function (): void {});
        DB::table('update_probe_first')->insert(['id' => 100]);
        $this->assertSame($after->schemaFingerprintSha256, $this->runner->snapshot()->schemaFingerprintSha256);
        $this->runner->rollbackExactSuffix($before, $after->appliedSuffix);
        $this->assertSame($before->toArray(), $this->runner->snapshot()->toArray());
        $this->assertSame('preserved', DB::table('cache')->where('key', 'row-state')->value('value'));
    }

    public function test_counter_like_default_literals_and_view_write_constraints_are_structural_evidence(): void
    {
        DB::unprepared("CREATE TABLE update_probe_first (id BIGINT PRIMARY KEY, note VARCHAR(100) DEFAULT ' AUTO_INCREMENT=1') ENGINE=InnoDB");
        $before = $this->runner->snapshot();
        DB::unprepared("ALTER TABLE update_probe_first ALTER COLUMN note SET DEFAULT ' AUTO_INCREMENT=2'");
        $this->assertNotSame($before->schemaFingerprintSha256, $this->runner->snapshot()->schemaFingerprintSha256);
        DB::unprepared('CREATE VIEW update_probe_view AS SELECT * FROM update_probe_first WHERE id > 0');
        $beforeView = $this->runner->snapshot();
        DB::unprepared('ALTER VIEW update_probe_view AS SELECT * FROM update_probe_first WHERE id > 0 WITH CASCADED CHECK OPTION');
        $this->assertNotSame($beforeView->schemaFingerprintSha256, $this->runner->snapshot()->schemaFingerprintSha256);
        DB::unprepared('DROP VIEW update_probe_view');
    }

    /** @return array<string, array{string}> */
    public static function unloggedDdl(): array
    {
        return [
            'trigger' => ['CREATE TRIGGER update_probe_trigger BEFORE INSERT ON update_probe_first FOR EACH ROW SET NEW.id = NEW.id'],
            'check constraint' => ['ALTER TABLE update_probe_first ADD CONSTRAINT update_probe_positive CHECK (id > 0)'],
            'table engine' => ['ALTER TABLE update_probe_first ENGINE=MyISAM'],
        ];
    }

    #[DataProvider('unloggedDdl')]
    public function test_unlogged_persistent_ddl_fails_closed(string $ddl): void
    {
        DB::unprepared('CREATE TABLE update_probe_first (id BIGINT PRIMARY KEY) ENGINE=InnoDB');
        $before = $this->runner->snapshot();
        $this->writeMigration('2099_01_01_000001_partial', $ddl, '', true);
        try {
            $this->runner->runPending($before, static function (): void {});
            $this->fail('Nontransactional unlogged DDL must require recovery.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertSame($before->ran, $this->runner->snapshot()->ran);
        $this->assertNotSame($before->schemaFingerprintSha256, $this->runner->snapshot()->schemaFingerprintSha256);
    }

    /** @return array<string, array{string}> */
    public static function interruptedDirections(): array
    {
        return ['forward' => ['up'], 'reverse' => ['down']];
    }

    #[DataProvider('interruptedDirections')]
    public function test_process_exit_during_committed_data_only_work_never_repeats_it(string $direction): void
    {
        DB::table('cache')->insert(['key' => 'process-probe', 'value' => 'preserved', 'expiration' => 0]);
        $before = $this->runner->snapshot();
        $mutation = "DB::table('cache')->where('key', 'process-probe')->increment('expiration'); exit(73);";
        $up = $direction === 'up' ? $mutation : '';
        $down = $direction === 'down' ? $mutation : '';
        File::put($this->directory.'/2099_01_01_000001_process.php', "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\nreturn new class extends Migration { public function up(): void { {$up} } public function down(): void { {$down} } };\n");
        $after = $direction === 'down' ? $this->runner->runPending($before, static function (): void {}) : null;
        $payload = ['before' => $before->toArray(), 'suffix' => $after?->appliedSuffix ?? []];
        File::put($this->directory.'/checkpoint.json', json_encode($payload, JSON_THROW_ON_ERROR));
        $autoload = var_export(base_path('vendor/autoload.php'), true);
        $bootstrap = var_export(base_path('bootstrap/app.php'), true);
        $connection = var_export(config('database.connections.update_migrations'), true);
        $directory = var_export($this->directory, true);
        $operation = $direction === 'up'
            ? '$runner->runPending($before, static function (): void {});'
            : '$runner->rollbackExactSuffix($before, $payload["suffix"]);';
        File::put($this->directory.'/child.php', <<<PHPCHILD
<?php
require {$autoload};
\$app = require {$bootstrap};
\$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.update_migrations' => {$connection}, 'database.default' => 'update_migrations']);
Illuminate\Support\Facades\DB::purge('update_migrations');
\$directory = {$directory};
\$payload = json_decode(file_get_contents(\$directory.'/checkpoint.json'), true, flags: JSON_THROW_ON_ERROR);
\$before = App\Services\Update\UpdateMigrationState::fromArray(\$payload['before']);
\$runner = new App\Services\Update\UpdateMigrationRunner(\$app->make(Illuminate\Database\Migrations\Migrator::class), 'update_migrations', \$directory, \$directory.'/attempt.json');
{$operation}
PHPCHILD);
        $process = new Process([PHP_BINARY, $this->directory.'/child.php'], base_path(), timeout: 15);
        $process->run();
        $this->assertSame(73, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(1, DB::table('cache')->where('key', 'process-probe')->value('expiration'));
        try {
            if ($direction === 'up') {
                $this->runner->runPending($before, static function (): void {});
            } else {
                $this->runner->rollbackExactSuffix($before, $after->appliedSuffix);
            }
            $this->fail('Process interruption must retain an unresolved attempt.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_recovery_required', $exception->codeName());
        }
        $this->assertSame(1, DB::table('cache')->where('key', 'process-probe')->value('expiration'));
        $this->assertFileExists($this->directory.'/attempt.json');
    }

    public function test_queue_admission_commits_reservations_before_releasing_lifecycle_ownership_on_mariadb(): void
    {
        config([
            'backup.lifecycle_mutex_path' => $this->directory.'/lifecycle.lock',
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'update_migrations',
            'database.connections.update_migrations_observer' => config('database.connections.update_migrations'),
        ]);
        $this->app->instance(UpdateJournal::class, new UpdateJournal($this->directory.'/update-state.json'));
        $observer = DB::connection('update_migrations_observer');
        try {
            $queue = Queue::connection('database');
            $id = $queue->pushRaw('{"job":"connected-probe"}');
            $ownership = (new LifecycleMutex)->acquire('update');
            try {
                $this->app->make(UpdateQuiescence::class)->assertReady();
                $this->assertTrue($queue->pop() === null, 'No reservation may cross exclusive safety capture.');
                $this->assertNull($observer->table('jobs')->where('id', $id)->value('reserved_at'));
            } finally {
                $ownership->release();
            }
            DB::beginTransaction();
            try {
                try {
                    $queue->pop();
                    $this->fail('An outer transaction could hide reservation commit from safety capture.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Queue reservation requires an independent transaction.', $exception->getMessage());
                }
                $this->assertNull($observer->table('jobs')->where('id', $id)->value('reserved_at'));
            } finally {
                DB::rollBack();
            }
            $job = $queue->pop();
            $this->assertNotNull($job);
            $ownership = (new LifecycleMutex)->acquire('update');
            try {
                $this->assertNotNull($observer->table('jobs')->where('id', $id)->value('reserved_at'));
                try {
                    $this->app->make(UpdateQuiescence::class)->assertReady();
                    $this->fail('A committed reserved job must block safety capture.');
                } catch (UpdateException $exception) {
                    $this->assertSame('update_quiescence_required', $exception->codeName());
                }
            } finally {
                $ownership->release();
            }
            $job->delete();
            $this->app->make(UpdateQuiescence::class)->assertReady();
        } finally {
            DB::purge('update_migrations_observer');
        }
    }

    private function writeMigration(string $name, string $up, string $down, bool $fail = false): void
    {
        $up = var_export($up, true);
        $down = var_export($down, true);
        $failure = $fail ? "throw new \\RuntimeException('private failure');" : '';
        File::put($this->directory.'/'.$name.'.php', "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\nreturn new class extends Migration { public function up(): void { DB::unprepared({$up}); {$failure} } public function down(): void { DB::unprepared({$down}); } };\n");
    }
}
