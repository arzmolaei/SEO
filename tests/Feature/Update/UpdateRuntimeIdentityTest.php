<?php

namespace Tests\Feature\Update;

use App\Services\Update\UpdateException;
use App\Services\Update\UpdateRuntimeIdentity;
use App\Support\InstallationState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UpdateRuntimeIdentityTest extends TestCase
{
    private string $directory;

    private string $originalEnvironmentPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/seo-update-identity-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory, 0700);
        $this->originalEnvironmentPath = $this->app->environmentPath();
        $this->app->useEnvironmentPath($this->directory);
        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->directory.'/.installed',
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
        ]);
        File::put($this->directory.'/.env', "APP_KEY=".config('app.key')."\nDB_PASSWORD=local-private-value\n");
        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => '0.1.0-alpha.1',
            'installation_id' => str_repeat('a', 32),
            'database_fingerprint' => str_repeat('b', 64),
            'owner_user_id' => 1,
            'restored_from_installation_id' => str_repeat('c', 32),
            'restored_at' => '2026-10-23T12:00:00+00:00',
        ]);
    }

    protected function tearDown(): void
    {
        $this->app->useEnvironmentPath($this->originalEnvironmentPath);
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_source_evidence_pins_secrets_and_identity_without_serializing_them(): void
    {
        $identity = $this->app->make(UpdateRuntimeIdentity::class)->capture();
        $this->assertSame(hash('sha256', (string) config('app.key')), $identity['runtime_key_sha256']);
        $this->assertSame(hash_file('sha256', $this->directory.'/.env'), $identity['environment_sha256']);
        foreach ($identity as $digest) {
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $digest);
        }
        $json = json_encode($identity, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) config('app.key'), $json);
        $this->assertStringNotContainsString('local-private-value', $json);
        $this->assertStringNotContainsString($this->directory, $json);
        (new InstallationState)->updateMetadata(['product_version' => '0.1.0-alpha.2']);
        $this->assertSame($identity, $this->app->make(UpdateRuntimeIdentity::class)->capture());
        (new InstallationState)->updateMetadata(['owner_user_id' => 2]);
        $this->assertNotSame($identity['installation_identity_sha256'], $this->app->make(UpdateRuntimeIdentity::class)->capture()['installation_identity_sha256']);
    }

    public function test_environment_key_and_effective_database_changes_have_independent_evidence(): void
    {
        $service = $this->app->make(UpdateRuntimeIdentity::class);
        $before = $service->capture();
        File::append($this->directory.'/.env', "MAIL_PASSWORD=changed-private-value\n");
        $this->assertNotSame($before['environment_sha256'], $service->capture()['environment_sha256']);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32))]);
        $this->assertNotSame($before['runtime_key_sha256'], $service->capture()['runtime_key_sha256']);
        $name = (string) config('database.default');
        config(['database.connections.'.$name.'.prefix' => 'changed_']);
        DB::purge($name);
        $this->assertNotSame($before['database_configuration_sha256'], $service->capture()['database_configuration_sha256']);
    }

    public function test_missing_stable_environment_cannot_produce_a_ready_source_identity(): void
    {
        File::delete($this->directory.'/.env');
        $this->expectException(UpdateException::class);
        $this->app->make(UpdateRuntimeIdentity::class)->capture();
    }
}
