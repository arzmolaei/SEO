<?php

namespace Tests\Feature\Acceptance;

use App\Models\User;
use App\Services\Exports\ExportProjectSeoPlan;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The installer writes DB_CONNECTION=mariadb for MariaDB hosts. Consistent
 * export snapshots must request REPEATABLE READ on that driver too, even when
 * the server/session default is weaker.
 */
class MariaDbDriverSnapshotIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('BACKUP_RESTORE_CONNECTED') !== '1') {
            $this->markTestSkipped('Runs in the connected MariaDB gate.');
        }
        config(['database.connections.mariadb_driver' => [
            'driver' => 'mariadb',
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
        DB::purge('mariadb_driver');
        DB::setDefaultConnection('mariadb_driver');
        Artisan::call('migrate:fresh', ['--database' => 'mariadb_driver', '--force' => true]);
    }

    protected function tearDown(): void
    {
        if (getenv('BACKUP_RESTORE_CONNECTED') === '1') {
            DB::connection('mariadb_driver')->getSchemaBuilder()->dropAllTables();
            DB::purge('mariadb_driver');
        }
        parent::tearDown();
    }

    public function test_export_snapshot_requests_repeatable_read_on_the_mariadb_driver(): void
    {
        $this->assertSame('mariadb', DB::connection()->getDriverName());
        DB::connection()->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'فضای آزمون');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'example.ir');

        $statements = [];
        Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtoupper($query->sql);
        });

        app(ExportProjectSeoPlan::class)->handle($project);

        $this->assertContains('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', $statements);
    }
}
