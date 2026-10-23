<?php

namespace Tests\Feature\Acceptance;

use App\Services\Update\UpdateException;
use App\Services\Update\UpdateMigrationRunner;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->runner = new UpdateMigrationRunner($this->app->make(Migrator::class), 'update_migrations', $this->directory);
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

    private function writeMigration(string $name, string $up, string $down, bool $fail = false): void
    {
        $up = var_export($up, true);
        $down = var_export($down, true);
        $failure = $fail ? "throw new \\RuntimeException('private failure');" : '';
        File::put($this->directory.'/'.$name.'.php', "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\nreturn new class extends Migration { public function up(): void { DB::unprepared({$up}); {$failure} } public function down(): void { DB::unprepared({$down}); } };\n");
    }
}
