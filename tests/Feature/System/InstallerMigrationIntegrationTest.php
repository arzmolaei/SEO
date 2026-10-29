<?php

namespace Tests\Feature\System;

use App\Support\Installer\InstallerMigrationFailed;
use App\Support\Installer\InstallerMigrationRunner;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstallerMigrationIntegrationTest extends TestCase
{
    private string $temporaryRoot;

    private string $migrationPath;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryRoot = storage_path('framework/testing/installer-migrations-'.bin2hex(random_bytes(6)));
        $this->migrationPath = $this->temporaryRoot.'/migrations';
        $this->databasePath = $this->temporaryRoot.'/installer.sqlite';

        mkdir($this->migrationPath, 0700, true);
        touch($this->databasePath);

        config([
            'database.connections.installer_migration' => [
                'driver' => 'sqlite',
                'database' => $this->databasePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('installer_migration');
    }

    protected function tearDown(): void
    {
        DB::disconnect('installer_migration');
        DB::purge('installer_migration');
        (new Filesystem)->deleteDirectory($this->temporaryRoot);

        parent::tearDown();
    }

    public function test_real_interruption_keeps_completed_subset_and_retry_runs_only_remaining(): void
    {
        $markerPath = $this->temporaryRoot.'/allow-second-migration';

        $this->writeCreateTableMigration(
            '2026_10_22_000001_create_installer_alpha.php',
            'installer_alpha',
        );
        $this->writeInterruptibleMigration(
            '2026_10_22_000002_create_installer_beta.php',
            'installer_beta',
            $markerPath,
        );

        $runner = $this->runner();

        try {
            $runner->runPending();
            $this->fail('Expected the second migration to simulate an interruption.');
        } catch (InstallerMigrationFailed $exception) {
            $this->assertSame('2026_10_22_000002_create_installer_beta', $exception->migration);
            $this->assertSame('simulated migration interruption', $exception->getPrevious()?->getMessage());
        }

        $this->assertTrue(Schema::connection('installer_migration')->hasTable('installer_alpha'));
        $this->assertFalse(Schema::connection('installer_migration')->hasTable('installer_beta'));
        $this->assertSame([
            'pending' => ['2026_10_22_000002_create_installer_beta'],
            'complete' => false,
        ], $runner->status());

        touch($markerPath);

        $retry = $runner->runPending();

        $this->assertSame(['2026_10_22_000002_create_installer_beta'], $retry['ran']);
        $this->assertSame([], $retry['remaining']);
        $this->assertTrue($retry['complete']);
        $this->assertTrue(Schema::connection('installer_migration')->hasTable('installer_beta'));
        $this->assertSame(['pending' => [], 'complete' => true], $runner->status());
    }

    public function test_a_half_applied_migration_is_cleaned_up_so_the_retry_succeeds(): void
    {
        $markerPath = $this->temporaryRoot.'/allow-index';
        // Like MariaDB with a 767-byte key limit: CREATE TABLE commits, the
        // following index statement fails. A pre-existing table must survive.
        Schema::connection('installer_migration')->create('owner_kept', function ($table): void {
            $table->id();
        });
        file_put_contents($this->migrationPath.'/2026_10_22_000001_create_installer_users.php', str_replace(
            '__MARKER__',
            var_export($markerPath, true),
            <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::connection('installer_migration')->create('installer_users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });

        if (! is_file(__MARKER__)) {
            throw new \RuntimeException('index too large');
        }

        Schema::connection('installer_migration')->table('installer_users', function (Blueprint $table): void {
            $table->unique('email');
        });
    }
};
PHP,
        ));

        $runner = $this->runner();

        try {
            $runner->runPending();
            $this->fail('Expected the index step to fail.');
        } catch (InstallerMigrationFailed $exception) {
            $this->assertSame('2026_10_22_000001_create_installer_users', $exception->migration);
        }

        $this->assertFalse(Schema::connection('installer_migration')->hasTable('installer_users'));
        $this->assertTrue(Schema::connection('installer_migration')->hasTable('owner_kept'));

        touch($markerPath);

        $this->assertTrue($runner->runPending()['complete']);
        $this->assertTrue(Schema::connection('installer_migration')->hasTable('installer_users'));
    }

    public function test_each_request_runs_a_bounded_batch_and_reports_the_rest(): void
    {
        foreach (['alpha', 'beta', 'gamma'] as $index => $table) {
            $this->writeCreateTableMigration("2026_10_22_00000{$index}_create_installer_{$table}.php", "installer_{$table}");
        }

        $runner = $this->runner();

        $first = $runner->runPending(maxMigrations: 2);
        $this->assertSame(['2026_10_22_000000_create_installer_alpha', '2026_10_22_000001_create_installer_beta'], $first['ran']);
        $this->assertSame(['2026_10_22_000002_create_installer_gamma'], $first['remaining']);
        $this->assertFalse($first['complete']);

        $timeBound = $runner->runPending(maxMigrations: 50, timeBudgetSeconds: 0.0);
        $this->assertSame(['2026_10_22_000002_create_installer_gamma'], $timeBound['ran']);
        $this->assertTrue($timeBound['complete']);
    }

    public function test_runner_uses_laravel_migration_services_without_shell_or_process_apis(): void
    {
        $reflection = new \ReflectionClass(InstallerMigrationRunner::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Artisan::call', $source);
        $this->assertStringNotContainsString('shell_exec', $source);
        $this->assertStringNotContainsString('proc_open', $source);
        $this->assertStringNotContainsString('Symfony\\Component\\Process', $source);
        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z_])exec\s*\(/', $source);
    }

    private function runner(): InstallerMigrationRunner
    {
        return new InstallerMigrationRunner(
            app(Migrator::class),
            'installer_migration',
            $this->migrationPath,
        );
    }

    private function writeCreateTableMigration(string $filename, string $table): void
    {
        $contents = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('installer_migration')->create(__TABLE__, function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::connection('installer_migration')->dropIfExists(__TABLE__);
    }
};
PHP;

        file_put_contents(
            $this->migrationPath.'/'.$filename,
            str_replace('__TABLE__', var_export($table, true), $contents),
        );
    }

    private function writeInterruptibleMigration(string $filename, string $table, string $markerPath): void
    {
        $contents = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! is_file(__MARKER__)) {
            throw new \RuntimeException('simulated migration interruption');
        }

        Schema::connection('installer_migration')->create(__TABLE__, function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::connection('installer_migration')->dropIfExists(__TABLE__);
    }
};
PHP;

        file_put_contents(
            $this->migrationPath.'/'.$filename,
            str_replace(
                ['__TABLE__', '__MARKER__'],
                [var_export($table, true), var_export($markerPath, true)],
                $contents,
            ),
        );
    }
}
