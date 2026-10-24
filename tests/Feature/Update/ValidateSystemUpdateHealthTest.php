<?php

namespace Tests\Feature\Update;

use App\Services\Update\UpdateHealthReport;
use App\Services\Update\ValidateSystemUpdateHealth;
use App\Support\InstallationState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UpdateTargetTestCase;

class ValidateSystemUpdateHealthTest extends UpdateTargetTestCase
{
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
            'encryption cipher drift' => ['cipher', 'runtime_identity'],
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
            'cipher' => config(['app.cipher' => 'AES-256-GCM']),
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
