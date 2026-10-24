<?php

namespace Tests\Feature\Acceptance;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\CaptureSystemState;
use App\Services\Update\UpdateJournal;
use App\Services\Update\UpdateMigrationRunner;
use App\Services\Update\UpdateRuntimeIdentity;
use App\Support\InstallationState;
use App\Support\Lifecycle\LifecycleMutex;
use App\Support\ReleasePackageContract;
use App\Support\Update\ActiveReleasePointer;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

class UpdateRuntimeProcessMariaDbAcceptanceTest extends TestCase
{
    private string $directory;

    private string $stableRoot;

    private string $targetRoot;

    private string $sourceRoot;

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

        $this->sourceRoot = base_path();
        $this->originalStorage = storage_path();
        $this->originalEnvironment = $this->app->environmentPath();
        $this->directory = storage_path('framework/testing/update-runtime-process-'.bin2hex(random_bytes(6)));
        $this->stableRoot = $this->directory.'/installation';
        $this->targetRoot = $this->stableRoot.'/storage/app/lifecycle/update/releases/target-probe';

        $this->configureConnectedDatabase();
        $this->migrateFresh();
        $this->buildRuntimeFixture($this->stableRoot, '0.1.0-alpha.1', 'source-runtime.js');
        $this->buildRuntimeFixture($this->targetRoot, '0.1.0-alpha.2', 'target-runtime.js');
        File::ensureDirectoryExists($this->stableRoot.'/public/build/assets');
        File::copy(
            $this->targetRoot.'/public/build/assets/target-runtime.js',
            $this->stableRoot.'/public/build/assets/target-runtime.js',
        );

        $appKey = 'base64:'.base64_encode(str_repeat('s', 32));
        $previousKey = 'base64:'.base64_encode(str_repeat('p', 32));
        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->stableRoot.'/storage/app/.installed',
            'app.key' => $appKey,
            'app.previous_keys' => [$previousKey],
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
            'APP_ENV=testing',
            'APP_KEY='.$appKey,
            'APP_PREVIOUS_KEYS='.$previousKey,
            'APP_LOCALE=en',
            'DB_URL=(null)',
            'DB_CONNECTION=mysql',
            'DB_HOST='.$this->connectedValue('HOST'),
            'DB_PORT='.$this->connectedValue('PORT'),
            'DB_DATABASE='.$this->connectedValue('DATABASE'),
            'DB_USERNAME='.$this->connectedValue('USERNAME'),
            'DB_PASSWORD='.$this->connectedValue('PASSWORD'),
            'CACHE_STORE=array',
            'QUEUE_CONNECTION=sync',
            'SESSION_DRIVER=file',
            'INSTALLER_ASSUME_INSTALLED=false',
            'MAIL_PASSWORD=local-private-value',
            '',
        ]));

        $this->owner = User::factory()->create([
            'name' => 'مالک پذیرش process',
            'email' => 'runtime-process-owner@example.test',
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $workspace = Workspace::query()->create(['name' => 'فضای runtime process']);
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

        $this->captureSourceSafety();
        $before = $this->migrationRunner()->snapshot();
        $sourceSchema = $this->app->make(ApplicationSchemaFingerprint::class)->forConnection(DB::connection());
        $this->writeTargetMigrations();
        $after = $this->migrationRunner()->runPending($before, static function (): void {});

        File::put($this->targetRoot.'/release.json', json_encode([
            'schema' => 'seo-release-slot/1',
            'release_id' => 'target-probe',
            'product_version' => '0.1.0-alpha.2',
            'package_digest_sha256' => str_repeat('d', 64),
            'launcher_schema' => 'seo-launcher/1',
        ], JSON_THROW_ON_ERROR));
        $this->pointer = new ActiveReleasePointer($this->stableRoot);
        $this->pointer->activateSlot('target-probe', '0.1.0-alpha.2', str_repeat('d', 64));

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
    }

    protected function tearDown(): void
    {
        if (isset($this->originalStorage)) {
            $this->app->useStoragePath($this->originalStorage);
            $this->app->useEnvironmentPath($this->originalEnvironment);
        }

        if (isset($this->directory)) {
            try {
                DB::connection('mysql')->getSchemaBuilder()->dropAllTables();
            } catch (Throwable) {
                // Preserve the original acceptance failure if cleanup is unavailable.
            }
            DB::purge('mysql');
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_target_and_source_recovery_boot_in_separate_real_release_processes(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertTrue(str_contains(strtolower((string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION)), 'mariadb'));
        $this->assertTrue(Schema::hasColumn('projects', 'connected_process_probe_0'));
        $this->assertTrue(Schema::hasColumn('projects', 'connected_process_probe_1'));

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

        $target = $this->runRecoveryProcess();
        $this->assertSame('slot', $target['release']['kind'] ?? null);
        $this->assertSame(realpath($this->targetRoot), $target['base_path'] ?? null);
        $this->assertSame('snapshot_restore_required', $target['result']['status'] ?? null);
        $this->assertSame('root', $this->pointer->current()['kind']);
        $this->assertFalse(Schema::hasColumn('projects', 'connected_process_probe_0'));
        $this->assertFalse(Schema::hasColumn('projects', 'connected_process_probe_1'));
        $this->assertSame('تغییر پس از snapshot', User::query()->findOrFail($this->owner->id)->name);

        $source = $this->runRecoveryProcess();
        $this->assertNotSame($target['pid'] ?? null, $source['pid'] ?? null);
        $this->assertSame('root', $source['release']['kind'] ?? null);
        $this->assertSame(realpath($this->stableRoot), $source['base_path'] ?? null);
        $this->assertSame('rolled_back', $source['result']['status'] ?? null);
        $this->assertSame('0.1.0-alpha.1', $source['result']['product_version'] ?? null);

        $this->assertSame($originalName, User::query()->findOrFail($this->owner->id)->name);
        $this->assertSame('source durable content', File::get($this->stableRoot.'/storage/app/private/uploads/source.txt'));
        $this->assertSame($environment, File::get($this->stableRoot.'/.env'));
        $this->assertSame($installation, (new InstallationState)->details());
        $this->assertSame('pending-after-update', DB::table('jobs')->value('payload'));
        $this->assertNull((new UpdateJournal($this->statePath()))->state());
        $this->assertFileExists($this->stableRoot.'/public/build/assets/source-runtime.js');
        $this->assertFileExists($this->stableRoot.'/public/build/assets/target-runtime.js');
    }

    /** @return array<string, mixed> */
    private function runRecoveryProcess(): array
    {
        $runner = $this->directory.'/recover-runtime.php';
        if (! is_file($runner)) {
            File::put($runner, <<<'PHP'
<?php

declare(strict_types=1);

use App\Services\Update\RecoverInterruptedUpdate;
use Illuminate\Contracts\Console\Kernel;

$root = $argv[1] ?? '';
require_once $root.'/bootstrap/runtime-release.php';
$release = (new SeoOsRuntimeReleaseLocator($root))->resolve();
define('SEO_OS_INSTALLATION_ROOT', $root);
require $release['release_root'].'/vendor/autoload.php';
$app = require $release['release_root'].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$result = $app->make(RecoverInterruptedUpdate::class)->handle();
echo json_encode([
    'pid' => getmypid(),
    'base_path' => base_path(),
    'release' => $release,
    'result' => $result,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHP);
        }

        $process = new Process([PHP_BINARY, $runner, $this->stableRoot], $this->stableRoot, [
            'APP_ENV' => 'testing',
        ]);
        $process->setTimeout(60);
        $exitCode = $process->run();
        $this->assertSame(0, $exitCode, $process->getErrorOutput()."\n".$process->getOutput());
        $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function buildRuntimeFixture(string $destination, string $version, string $asset): void
    {
        File::ensureDirectoryExists($destination);
        foreach (['VERSION', 'artisan', 'composer.json', 'composer.lock', '.env.example'] as $path) {
            File::copy($this->sourceRoot.'/'.$path, $destination.'/'.$path);
        }
        File::put($destination.'/VERSION', $version."\n");
        foreach (['app', 'bootstrap', 'config', 'database/migrations', 'resources/views', 'routes', 'vendor'] as $path) {
            File::copyDirectory($this->sourceRoot.'/'.$path, $destination.'/'.$path);
        }
        foreach (['public/index.php', 'public/robots.txt', 'public/favicon.ico'] as $path) {
            if (is_file($this->sourceRoot.'/'.$path)) {
                File::ensureDirectoryExists(dirname($destination.'/'.$path));
                File::copy($this->sourceRoot.'/'.$path, $destination.'/'.$path);
            }
        }
        foreach (['bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
            File::ensureDirectoryExists($destination.'/'.$path);
        }
        File::ensureDirectoryExists($destination.'/public/build/assets');
        File::put($destination.'/public/build/manifest.json', json_encode([
            'resources/js/app.tsx' => ['file' => 'assets/'.$asset, 'isEntry' => true],
        ], JSON_THROW_ON_ERROR));
        File::put($destination.'/public/build/assets/'.$asset, $version.' runtime asset');
        File::ensureDirectoryExists($destination.'/public/fonts/open/vazirmatn');
        File::put($destination.'/public/fonts/open/vazirmatn/Vazirmatn[wght].woff2', 'runtime-font-fixture');

        $report = (new ReleasePackageContract)->inspect($destination);
        $this->assertTrue($report['valid'], json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function writeTargetMigrations(): void
    {
        $template = <<<'PHP'
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

        foreach ([0, 1] as $index) {
            File::put(
                $this->targetRoot.'/database/migrations/2099_02_01_00000'.($index + 1).'_process_probe_'.$index.'.php',
                str_replace('__COLUMN__', 'connected_process_probe_'.$index, $template),
            );
        }
    }

    private function migrationRunner(): UpdateMigrationRunner
    {
        return new UpdateMigrationRunner(
            $this->app->make(Migrator::class),
            'mysql',
            $this->targetRoot.'/database/migrations',
            $this->stableRoot.'/storage/app/lifecycle/update/migration-attempt.json',
        );
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

        $ownership = (new LifecycleMutex)->acquire('update-runtime-process-acceptance');
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

    private function configureConnectedDatabase(): void
    {
        $base = config('database.connections.mysql');
        $this->assertIsArray($base);
        config([
            'database.connections.mysql' => array_merge($base, [
                'url' => null,
                'host' => $this->connectedValue('HOST'),
                'port' => $this->connectedValue('PORT'),
                'database' => $this->connectedValue('DATABASE'),
                'username' => $this->connectedValue('USERNAME'),
                'password' => $this->connectedValue('PASSWORD'),
            ]),
            'database.default' => 'mysql',
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
    }

    private function migrateFresh(): void
    {
        $exitCode = Artisan::call('migrate:fresh', [
            '--database' => 'mysql',
            '--force' => true,
        ]);
        $this->assertSame(0, $exitCode, Artisan::output());
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');
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
