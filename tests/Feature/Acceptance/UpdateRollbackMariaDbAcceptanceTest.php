<?php

namespace Tests\Feature\Acceptance;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\CaptureSystemState;
use App\Services\Backup\RestoreJournal;
use App\Services\Update\RecoverInterruptedUpdate;
use App\Services\Update\UpdateJournal;
use App\Services\Update\UpdateMigrationRunner;
use App\Services\Update\UpdateRuntimeIdentity;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\Lifecycle\ProductVersion;
use App\Support\Update\ActiveReleasePointer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

class UpdateRollbackMariaDbAcceptanceTest extends TestCase
{
    private string $directory;

    private string $stableRoot;

    private string $targetRoot;

    private string $originalBase;

    private string $originalStorage;

    private string $originalEnvironment;

    private ActiveReleasePointer $pointer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('UPDATE_ROLLBACK_CONNECTED') !== '1') {
            $this->markTestSkipped('Connected Update/Rollback acceptance runs only in the MariaDB CI gate.');
        }

        $this->originalBase = base_path();
        $this->originalStorage = storage_path();
        $this->originalEnvironment = $this->app->environmentPath();
        $this->directory = storage_path('framework/testing/update-rollback-acceptance-'.bin2hex(random_bytes(6)));
        $this->stableRoot = $this->directory.'/installation';
        $this->targetRoot = $this->stableRoot.'/storage/app/lifecycle/update/releases/target-probe';

        $this->configureConnectedDatabase();
        $this->migrateFresh();

        foreach (['app/private', 'app/installer', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
            File::ensureDirectoryExists($this->stableRoot.'/storage/'.$path, 0700);
        }
        foreach (['bootstrap/cache', 'vendor', 'public/build/assets', 'public/fonts/open/vazirmatn', 'resources/views', 'database/migrations'] as $path) {
            File::ensureDirectoryExists($this->targetRoot.'/'.$path, 0700);
        }
        foreach (['bootstrap/cache', 'vendor', 'public/build/assets'] as $path) {
            File::ensureDirectoryExists($this->stableRoot.'/'.$path, 0700);
        }

        File::put($this->stableRoot.'/VERSION', "0.1.0-alpha.1\n");
        File::put($this->targetRoot.'/VERSION', "0.1.0-alpha.2\n");
        foreach (['artisan', 'bootstrap/app.php', 'bootstrap/runtime-release.php', 'vendor/autoload.php', 'resources/views/app.blade.php', '.env.example', 'public/fonts/open/vazirmatn/Vazirmatn[wght].woff2'] as $path) {
            File::ensureDirectoryExists(dirname($this->targetRoot.'/'.$path), 0700);
            File::put($this->targetRoot.'/'.$path, 'target runtime fixture');
        }
        foreach (['bootstrap/app.php', 'bootstrap/runtime-release.php', 'vendor/autoload.php'] as $path) {
            File::put($this->stableRoot.'/'.$path, 'source runtime fixture');
        }

        File::put($this->stableRoot.'/public/build/manifest.json', json_encode([
            'resources/js/app.tsx' => ['file' => 'assets/source-hash.js', 'isEntry' => true],
        ], JSON_THROW_ON_ERROR));
        File::put($this->targetRoot.'/public/build/manifest.json', json_encode([
            'resources/js/app.tsx' => ['file' => 'assets/target-hash.js', 'isEntry' => true],
        ], JSON_THROW_ON_ERROR));
        File::put($this->stableRoot.'/public/build/assets/source-hash.js', 'source asset');
        File::put($this->stableRoot.'/public/build/assets/target-hash.js', 'target asset');
        File::put($this->targetRoot.'/public/build/assets/target-hash.js', 'target asset');

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->stableRoot.'/storage/app/installer/.installed',
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'app.previous_keys' => ['base64:'.base64_encode(str_repeat('p', 32))],
            'backup.lifecycle_mutex_path' => $this->stableRoot.'/storage/app/lifecycle/lifecycle.lock',
            'backup.durable_file_roots' => [
                'storage/app/private/uploads' => $this->stableRoot.'/storage/app/private/uploads',
                'storage/app/public/uploads' => $this->stableRoot.'/storage/app/public/uploads',
            ],
            'update.safety_path' => $this->stableRoot.'/storage/app/lifecycle/update/safety',
            'update.upload_path' => $this->stableRoot.'/storage/app/lifecycle/update/uploads',
            'update.retention_path' => $this->stableRoot.'/storage/app/lifecycle/update/last-complete.json',
            'session.driver' => 'file',
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->app->useEnvironmentPath($this->stableRoot);
        $this->app->useStoragePath($this->stableRoot.'/storage');
        File::put($this->stableRoot.'/.env', implode("\n", [
            'APP_KEY='.config('app.key'),
            'APP_PREVIOUS_KEYS='.implode(',', config('app.previous_keys')),
            'DB_CONNECTION=mysql',
            'DB_HOST='.$this->connectedValue('HOST'),
            'DB_PORT='.$this->connectedValue('PORT'),
            'DB_DATABASE='.$this->connectedValue('DATABASE'),
            'DB_USERNAME='.$this->connectedValue('USERNAME'),
            'DB_PASSWORD='.$this->connectedValue('PASSWORD'),
            'MAIL_PASSWORD=local-private-value',
            '',
        ]));

        $this->owner = User::factory()->create([
            'name' => 'مالک پذیرش به‌روزرسانی',
            'email' => 'update-owner@example.test',
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $workspace = Workspace::query()->create(['name' => 'فضای به‌روزرسانی']);
        Membership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $this->owner->id,
            'role' => WorkspaceRole::Owner,
        ]);
        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => '0.1.0-alpha.1',
            'installation_id' => str_repeat('a', 32),
            'database_fingerprint' => str_repeat('b', 64),
            'owner_user_id' => $this->owner->id,
        ]);

        $this->app->setBasePath($this->stableRoot);
        $this->captureSourceSafety();

        $this->pointer = new ActiveReleasePointer($this->stableRoot);
        File::put($this->targetRoot.'/release.json', json_encode([
            'schema' => 'seo-release-slot/1',
            'release_id' => 'target-probe',
            'product_version' => '0.1.0-alpha.2',
            'package_digest_sha256' => str_repeat('d', 64),
            'launcher_schema' => 'seo-launcher/1',
        ], JSON_THROW_ON_ERROR));
        $this->pointer->activateSlot('target-probe', '0.1.0-alpha.2', str_repeat('d', 64));

        $this->app->setBasePath($this->targetRoot);
        $runner = $this->app->make(UpdateMigrationRunner::class);
        $before = $runner->snapshot();
        $sourceSchema = $this->app->make(ApplicationSchemaFingerprint::class)->forConnection(DB::connection());
        $migrationTemplate = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public $withinTransaction = false;
    public function up(): void { Schema::table('projects', fn (Blueprint $table) => $table->string('__COLUMN__')->nullable()); }
    public function down(): void { Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('__COLUMN__')); }
};
PHP;

        foreach (['first', 'second'] as $index => $name) {
            $column = 'connected_recovery_probe_'.$index;
            File::put(
                $this->targetRoot.'/database/migrations/2099_01_01_00000'.($index + 1).'_'.$name.'.php',
                str_replace('__COLUMN__', $column, $migrationTemplate),
            );
        }

        $after = $runner->runPending($before, static function (): void {});
        $runtimeIdentity = $this->app->make(UpdateRuntimeIdentity::class)->capture();
        $state = [
            ...$runtimeIdentity,
            'phase' => 'rollback_required',
            'upload_id' => str_repeat('c', 32),
            'source_product_version' => '0.1.0-alpha.1',
            'target_product_version' => '0.1.0-alpha.2',
            'target_release_id' => 'target-probe',
            'package_digest_sha256' => str_repeat('d', 64),
            'target_schema_fingerprint' => $after->schemaFingerprintSha256,
            'migration_started' => true,
            'source_release_kind' => 'root',
            'source_release_id' => null,
            'source_schema_fingerprint' => $sourceSchema,
            'safety_snapshot_id' => str_repeat('c', 32),
            'safety_snapshot_sha256' => hash_file('sha256', $this->safetyPath()),
            'lifecycle_mutex_path_sha256' => hash('sha256', (string) realpath((string) config('backup.lifecycle_mutex_path'))),
            'pre_update_migration_history_sha256' => $before->digestSha256,
            'pre_update_migration_state' => $before->toArray(),
            'migration_state' => $after->toArray(),
        ];
        File::put($this->statePath(), json_encode($state, JSON_THROW_ON_ERROR));
        $this->app->instance(ActiveReleasePointer::class, $this->pointer);
        $this->app->instance(UpdateJournal::class, new UpdateJournal($this->statePath()));
        $this->app->instance(RestoreJournal::class, new RestoreJournal($this->stableRoot.'/storage/app/lifecycle/restore/state.json'));
    }

    protected function tearDown(): void
    {
        if (isset($this->originalBase)) {
            $this->app->setBasePath($this->originalBase);
            $this->app->useStoragePath($this->originalStorage);
            $this->app->useEnvironmentPath($this->originalEnvironment);
        }

        if (isset($this->directory)) {
            try {
                DB::connection('update_rollback')->getSchemaBuilder()->dropAllTables();
            } catch (Throwable) {
                // Preserve the original acceptance failure if cleanup is unavailable.
            }
            DB::purge('update_rollback');
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_real_mariadb_exact_suffix_rollback_restores_source_snapshot_and_identity(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertTrue(str_contains(strtolower((string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION)), 'mariadb'));
        $this->assertTrue(Schema::hasColumn('projects', 'connected_recovery_probe_0'));
        $this->assertTrue(Schema::hasColumn('projects', 'connected_recovery_probe_1'));

        $originalName = $this->owner->name;
        $installation = (new InstallationState)->details();
        $environment = File::get($this->stableRoot.'/.env');
        $this->owner->update(['name' => 'تغییر پس از snapshot']);
        File::put($this->stableRoot.'/storage/app/private/uploads/source.txt', 'changed after snapshot');
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => 'pending-after-update',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => 123,
            'created_at' => 120,
        ]);

        $targetResult = $this->app->make(RecoverInterruptedUpdate::class)->handle();
        $this->assertSame('snapshot_restore_required', $targetResult['status']);
        $this->assertSame('root', $this->pointer->current()['kind']);
        $this->assertFalse(Schema::hasColumn('projects', 'connected_recovery_probe_0'));
        $this->assertFalse(Schema::hasColumn('projects', 'connected_recovery_probe_1'));
        $this->assertSame('تغییر پس از snapshot', User::query()->findOrFail($this->owner->id)->name);
        $this->assertFileExists($this->safetyPath());

        $this->app->setBasePath($this->stableRoot);
        $this->app->forgetInstance(ProductVersion::class);
        $sourceResult = $this->app->make(RecoverInterruptedUpdate::class)->handle();

        $this->assertSame(['status' => 'rolled_back', 'product_version' => '0.1.0-alpha.1'], $sourceResult);
        $this->assertSame($originalName, User::query()->findOrFail($this->owner->id)->name);
        $this->assertSame('source durable content', File::get($this->stableRoot.'/storage/app/private/uploads/source.txt'));
        $this->assertSame($environment, File::get($this->stableRoot.'/.env'));
        $this->assertSame($installation, (new InstallationState)->details());
        $this->assertSame('pending-after-update', DB::table('jobs')->value('payload'));
        $this->assertNull($this->app->make(UpdateJournal::class)->state());
        $this->assertFileExists($this->stableRoot.'/public/build/assets/source-hash.js');
        $this->assertFileExists($this->stableRoot.'/public/build/assets/target-hash.js');
    }

    private function configureConnectedDatabase(): void
    {
        $base = config('database.connections.mysql');
        $this->assertIsArray($base);
        config([
            'database.connections.update_rollback' => array_merge($base, [
                'url' => null,
                'host' => $this->connectedValue('HOST'),
                'port' => (int) $this->connectedValue('PORT'),
                'database' => $this->connectedValue('DATABASE'),
                'username' => $this->connectedValue('USERNAME'),
                'password' => $this->connectedValue('PASSWORD'),
            ]),
            'database.default' => 'update_rollback',
        ]);
        DB::purge('update_rollback');
        DB::setDefaultConnection('update_rollback');
    }

    private function migrateFresh(): void
    {
        $exitCode = Artisan::call('migrate:fresh', [
            '--database' => 'update_rollback',
            '--force' => true,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());
        DB::purge('update_rollback');
        DB::setDefaultConnection('update_rollback');
    }

    private function captureSourceSafety(): void
    {
        foreach ([
            (string) config('update.safety_path'),
            (string) config('update.upload_path'),
            $this->stableRoot.'/storage/app/private/uploads',
            $this->stableRoot.'/storage/app/public/uploads',
        ] as $path) {
            File::ensureDirectoryExists($path, 0700);
        }
        File::put($this->stableRoot.'/storage/app/private/uploads/source.txt', 'source durable content');

        $ownership = (new LifecycleMutex)->acquire('update-connected-acceptance');
        try {
            $captured = $this->app->make(CaptureSystemState::class)->handle($ownership);
            try {
                $captured->rewind();
                File::put($this->safetyPath(), stream_get_contents($captured->stream()));
            } finally {
                $captured->close();
            }
            File::put(
                (string) config('update.safety_path').'/'.str_repeat('c', 32).'.json',
                json_encode((new InstallationState)->details(), JSON_THROW_ON_ERROR),
            );
            File::put((string) config('update.upload_path').'/'.str_repeat('c', 32).'.seoupdate', 'verified fixture upload');
        } finally {
            $ownership->release();
        }
    }

    private function connectedValue(string $key): string
    {
        $value = (string) getenv('UPDATE_ROLLBACK_DB_'.$key);
        if ($value === '') {
            $this->fail('Missing UPDATE_ROLLBACK_DB_'.$key.' for connected Update/Rollback acceptance.');
        }

        return $value;
    }

    private function safetyPath(): string
    {
        return (string) config('update.safety_path').'/'.str_repeat('c', 32).'.seobak';
    }

    private function statePath(): string
    {
        return $this->stableRoot.'/storage/app/lifecycle/update/state.json';
    }
}
