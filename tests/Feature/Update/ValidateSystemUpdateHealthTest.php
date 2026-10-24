<?php

namespace Tests\Feature\Update;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Backup\ApplicationSchemaFingerprint;
use App\Services\Backup\RestoreJournal;
use App\Services\Update\UpdateHealthReport;
use App\Services\Update\UpdateJournal;
use App\Services\Update\UpdateRuntimeIdentity;
use App\Services\Update\ValidateSystemUpdateHealth;
use App\Support\InstallationState;
use App\Support\Update\ActiveReleasePointer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidateSystemUpdateHealthTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $stableRoot;

    private string $targetRoot;

    private string $originalBase;

    private string $originalStorage;

    private string $originalEnvironment;

    private ActiveReleasePointer $pointer;

    private User $owner;

    /** @var array<string, mixed> */
    private array $evidence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBase = base_path();
        $this->originalStorage = storage_path();
        $this->originalEnvironment = $this->app->environmentPath();
        $this->directory = sys_get_temp_dir().'/seo-update-health-'.bin2hex(random_bytes(6));
        $this->stableRoot = $this->directory.'/installation';
        $this->targetRoot = $this->stableRoot.'/storage/app/lifecycle/update/releases/target-probe';
        foreach (['app/private', 'app/installer', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
            File::ensureDirectoryExists($this->stableRoot.'/storage/'.$path, 0700);
        }
        foreach (['bootstrap/cache', 'vendor', 'public/build/assets', 'public/fonts/open/vazirmatn', 'resources/views'] as $path) {
            File::ensureDirectoryExists($this->targetRoot.'/'.$path, 0700);
        }
        File::ensureDirectoryExists($this->stableRoot.'/public/build/assets', 0700);
        File::put($this->stableRoot.'/VERSION', "0.1.0-alpha.1\n");
        File::put($this->targetRoot.'/VERSION', "0.1.0-alpha.2\n");
        foreach (['artisan', 'bootstrap/app.php', 'bootstrap/runtime-release.php', 'vendor/autoload.php', 'resources/views/app.blade.php', '.env.example', 'public/fonts/open/vazirmatn/Vazirmatn[wght].woff2'] as $path) {
            File::put($this->targetRoot.'/'.$path, 'fixture');
        }
        File::put($this->targetRoot.'/public/build/manifest.json', json_encode([
            'resources/js/app.tsx' => ['file' => 'assets/target-hash.js', 'isEntry' => true],
        ], JSON_THROW_ON_ERROR));
        File::put($this->targetRoot.'/public/build/assets/target-hash.js', 'target asset');
        File::put($this->stableRoot.'/public/build/assets/target-hash.js', 'target asset');
        File::put($this->stableRoot.'/public/build/assets/source-hash.js', 'source asset');
        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->stableRoot.'/storage/app/installer/.installed',
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
        ]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        $this->app->useEnvironmentPath($this->stableRoot);
        $this->app->useStoragePath($this->stableRoot.'/storage');
        File::put($this->stableRoot.'/.env', 'APP_KEY='.config('app.key')."\nDB_PASSWORD=local-private-value\n");
        $this->owner = User::factory()->create(['onboarded_at' => now()]);
        $workspace = Workspace::query()->create(['name' => 'فضای مالک']);
        Membership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Owner]);
        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2, 'product_version' => '0.1.0-alpha.1',
            'installation_id' => str_repeat('a', 32), 'database_fingerprint' => str_repeat('b', 64),
            'owner_user_id' => $this->owner->id,
        ]);
        $this->pointer = new ActiveReleasePointer($this->stableRoot);
        File::put($this->targetRoot.'/release.json', json_encode([
            'schema' => 'seo-release-slot/1', 'release_id' => 'target-probe',
            'product_version' => '0.1.0-alpha.2', 'package_digest_sha256' => str_repeat('d', 64),
            'launcher_schema' => 'seo-launcher/1',
        ], JSON_THROW_ON_ERROR));
        $this->pointer->activateSlot('target-probe', '0.1.0-alpha.2', str_repeat('d', 64));
        $this->evidence = [
            ...$this->app->make(UpdateRuntimeIdentity::class)->capture(),
            'phase' => 'validating', 'upload_id' => str_repeat('c', 32),
            'source_product_version' => '0.1.0-alpha.1',
            'target_product_version' => '0.1.0-alpha.2', 'target_release_id' => 'target-probe',
            'package_digest_sha256' => str_repeat('d', 64),
            'target_schema_fingerprint' => $this->app->make(ApplicationSchemaFingerprint::class)->forConnection(DB::connection()),
        ];
        File::put($this->stableRoot.'/storage/app/lifecycle/update/state.json', json_encode($this->evidence, JSON_THROW_ON_ERROR));
        $this->app->instance(ActiveReleasePointer::class, $this->pointer);
        $this->app->instance(UpdateJournal::class, new UpdateJournal($this->stableRoot.'/storage/app/lifecycle/update/state.json'));
        $this->app->instance(RestoreJournal::class, new RestoreJournal($this->stableRoot.'/storage/app/lifecycle/restore/state.json'));
        $this->app->setBasePath($this->targetRoot);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBase);
        $this->app->useStoragePath($this->originalStorage);
        $this->app->useEnvironmentPath($this->originalEnvironment);
        Schema::dropIfExists('unrecognized_probe');
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_healthy_target_reports_only_safe_codes_and_preserves_source_local_state(): void
    {
        $before = (new InstallationState)->details();
        $report = $this->app->make(ValidateSystemUpdateHealth::class)->handle($this->evidence);
        $this->assertInstanceOf(UpdateHealthReport::class, $report);
        $this->assertTrue($report->healthy);
        $this->assertSame([], $report->failures);
        $this->assertSame($before, (new InstallationState)->details());
        $this->assertSame('source asset', File::get($this->stableRoot.'/public/build/assets/source-hash.js'));
        $this->assertReportIsSafe($report);
    }

    /** @return array<string, array{string,string}> */
    public static function unsafeTargets(): array
    {
        return [
            'source process' => ['source_process', 'target_release'],
            'wrong pointer' => ['pointer', 'target_release'],
            'wrong version' => ['version', 'target_release'],
            'schema drift' => ['schema', 'schema'],
            'unknown durable table' => ['catalog', 'schema'],
            'owner metadata drift' => ['identity', 'runtime_identity'],
            'runtime key drift' => ['key', 'runtime_identity'],
            'stable environment drift' => ['environment', 'runtime_identity'],
            'missing copied asset' => ['missing_asset', 'assets'],
            'copied asset corruption' => ['corrupt_asset', 'assets'],
            'invalid Vite manifest' => ['manifest', 'assets'],
            'storage rebound to slot' => ['storage', 'stable_storage'],
            'cached wrong encrypter' => ['encrypter', 'encryption'],
            'owner no longer verified' => ['owner', 'owner'],
            'conflicting restore' => ['restore', 'lifecycle'],
        ];
    }

    #[DataProvider('unsafeTargets')]
    public function test_unsafe_local_target_invariants_fail_without_secret_or_path_disclosure(string $mutation, string $failure): void
    {
        match ($mutation) {
            'source_process' => $this->app->setBasePath($this->stableRoot),
            'pointer' => $this->pointer->activateRoot('0.1.0-alpha.1'),
            'version' => $this->evidence['target_product_version'] = '9.9.9',
            'schema' => Schema::table('projects', fn (Blueprint $table) => $table->string('health_probe')->nullable()),
            'catalog' => Schema::create('unrecognized_probe', fn (Blueprint $table) => $table->id()),
            'identity' => (new InstallationState)->updateMetadata(['owner_user_id' => 999]),
            'key' => config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]),
            'environment' => File::append($this->stableRoot.'/.env', "MAIL_PASSWORD=changed-private-value\n"),
            'missing_asset' => File::delete($this->stableRoot.'/public/build/assets/target-hash.js'),
            'corrupt_asset' => File::put($this->stableRoot.'/public/build/assets/target-hash.js', 'corrupted'),
            'manifest' => File::put($this->targetRoot.'/public/build/manifest.json', '{invalid'),
            'storage' => $this->app->useStoragePath($this->targetRoot.'/storage'),
            'encrypter' => $this->replaceEncrypter(),
            'owner' => $this->owner->forceFill(['email_verified_at' => null])->save(),
            'restore' => $this->writeRestoreState(),
        };
        $report = $this->app->make(ValidateSystemUpdateHealth::class)->handle($this->evidence);
        $this->assertFalse($report->healthy);
        $this->assertContains($failure, $report->failures);
        $this->assertReportIsSafe($report);
    }

    private function replaceEncrypter(): void
    {
        $this->app->instance('encrypter', new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
        Crypt::clearResolvedInstance('encrypter');
    }

    private function writeRestoreState(): void
    {
        File::ensureDirectoryExists($this->stableRoot.'/storage/app/lifecycle/restore', 0700);
        File::put($this->stableRoot.'/storage/app/lifecycle/restore/state.json', '{"phase":"recovery_required"}');
    }

    private function assertReportIsSafe(UpdateHealthReport $report): void
    {
        $json = json_encode($report->toArray(), JSON_THROW_ON_ERROR);
        foreach ([$this->directory, 'local-private-value', 'changed-private-value', (string) config('app.key'), 'SQLSTATE', 'stack trace'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }
}
