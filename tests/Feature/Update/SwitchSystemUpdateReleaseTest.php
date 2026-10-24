<?php

namespace Tests\Feature\Update;

use App\Models\User;
use App\Services\Backup\RestoreJournal;
use App\Services\Update\SwitchSystemUpdateRelease;
use App\Services\Update\UpdateException;
use App\Services\Update\UpdateJournal;
use App\Support\InstallationState;
use App\Support\Update\ActiveReleasePointer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SwitchSystemUpdateReleaseTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $installationRoot;

    private string $releaseRoot;

    private string $uploadRoot;

    private string $journalPath;

    private UpdateJournal $journal;

    private ActiveReleasePointer $pointer;

    private string $uploadId;

    private string $packageDigest;

    private string $targetVersion;

    private string $targetReleaseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/switch-system-update-'.bin2hex(random_bytes(6)));
        $this->installationRoot = $this->directory.'/installation';
        $this->releaseRoot = $this->installationRoot.'/storage/app/lifecycle/update/releases';
        $this->uploadRoot = $this->installationRoot.'/storage/app/lifecycle/update/uploads';
        $this->journalPath = $this->installationRoot.'/storage/app/lifecycle/update/state.json';
        $this->uploadId = str_repeat('a', 32);
        $this->targetVersion = '0.1.0-alpha.2';

        File::ensureDirectoryExists($this->installationRoot);
        File::ensureDirectoryExists($this->uploadRoot);
        File::put($this->installationRoot.'/VERSION', "0.1.0-alpha.1\n");

        $upload = $this->uploadRoot.'/'.$this->uploadId.'.seoupdate';
        File::put($upload, 'verified package bytes');
        $digest = hash_file('sha256', $upload);
        $this->assertIsString($digest);
        $this->packageDigest = $digest;
        $this->targetReleaseId = $this->targetVersion.'-'.substr($digest, 0, 16);

        config([
            'backup.lifecycle_mutex_path' => $this->installationRoot.'/storage/app/lifecycle/lifecycle.lock',
            'update.upload_path' => $this->uploadRoot,
            'queue.default' => 'sync',
            'cache.default' => 'array',
        ]);

        $this->journal = new UpdateJournal($this->journalPath);
        $this->pointer = new ActiveReleasePointer($this->installationRoot, null, $this->releaseRoot);
        $this->app->instance(UpdateJournal::class, $this->journal);
        $this->app->instance(ActiveReleasePointer::class, $this->pointer);

        $this->seedSlot($this->targetReleaseId, $this->targetVersion, $this->packageDigest);
        $this->seedStagedJournal();
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_switch_activates_exact_target_and_does_not_run_migrations_in_source_request(): void
    {
        $beforeMigrations = $this->migrationHistory();

        $result = $this->app->make(SwitchSystemUpdateRelease::class)->handle($this->packageDigest);

        $this->assertSame([
            'status' => 'target_active',
            'target_version' => $this->targetVersion,
        ], $result);
        $this->assertSame('slot', $this->pointer->current()['kind']);
        $this->assertSame($this->targetReleaseId, $this->pointer->current()['release_id']);
        $this->assertSame($this->targetVersion, $this->pointer->current()['product_version']);

        $state = $this->journal->state();
        $this->assertIsArray($state);
        $this->assertSame('target_active', $state['phase']);
        $this->assertFalse($state['migration_started'] ?? true);
        $this->assertSame($beforeMigrations, $this->migrationHistory());
    }

    public function test_switch_rejects_package_changed_after_staging_before_pointer_mutation(): void
    {
        File::append($this->uploadRoot.'/'.$this->uploadId.'.seoupdate', 'tamper');

        try {
            $this->app->make(SwitchSystemUpdateRelease::class)->handle($this->packageDigest);
            $this->fail('Changed package bytes must block release activation.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_package_changed', $exception->codeName());
        }

        $this->assertSame('root', $this->pointer->current()['kind']);
        $this->assertSame('staged', $this->journal->state()['phase'] ?? null);
    }

    public function test_switch_reproves_quiescence_before_pointer_mutation(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
        ]);

        try {
            $this->app->make(SwitchSystemUpdateRelease::class)->handle($this->packageDigest);
            $this->fail('Unsafe queue state must block release activation.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_quiescence_required', $exception->codeName());
        }

        $this->assertSame('root', $this->pointer->current()['kind']);
        $this->assertSame('staged', $this->journal->state()['phase'] ?? null);
    }

    public function test_switch_rejects_source_pointer_drift_before_target_activation(): void
    {
        $sourceDigest = str_repeat('b', 64);
        $sourceReleaseId = '0.1.0-alpha.1-'.substr($sourceDigest, 0, 16);
        $this->seedSlot($sourceReleaseId, '0.1.0-alpha.1', $sourceDigest);
        $this->pointer->activateSlot($sourceReleaseId, '0.1.0-alpha.1', $sourceDigest);

        try {
            $this->app->make(SwitchSystemUpdateRelease::class)->handle($this->packageDigest);
            $this->fail('Source release drift must block target activation.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_source_mismatch', $exception->codeName());
        }

        $this->assertSame($sourceReleaseId, $this->pointer->current()['release_id']);
        $this->assertSame('staged', $this->journal->state()['phase'] ?? null);
    }

    public function test_owner_confirm_endpoint_switches_then_requires_a_separate_target_request(): void
    {
        $owner = $this->markInstalledOwner();
        $before = $this->migrationHistory();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/settings/update/confirm', ['package_digest_sha256' => $this->packageDigest])
            ->assertOk()
            ->assertJson([
                'status' => 'target_active',
                'target_version' => $this->targetVersion,
                'requires_reload' => true,
                'continue_url' => url('/settings/update'),
            ])
            ->assertHeader('Cache-Control', 'no-cache, no-store, private');
        $this->assertSame($this->targetReleaseId, $this->pointer->current()['release_id']);
        $this->assertFalse($this->journal->state()['migration_started']);
        $this->assertSame($before, $this->migrationHistory());
    }

    public function test_confirm_requires_authentication_system_owner_and_recent_password(): void
    {
        $owner = $this->markInstalledOwner();
        $payload = ['package_digest_sha256' => $this->packageDigest];
        $this->post('/settings/update/confirm', $payload)->assertRedirect('/login');
        $other = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($other)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/settings/update/confirm', $payload)->assertForbidden();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => 0])
            ->post('/settings/update/confirm', $payload)->assertRedirect(route('password.confirm'));
        $this->assertSame('root', $this->pointer->current()['kind']);
    }

    public function test_stale_confirm_digest_does_not_activate_a_release(): void
    {
        $owner = $this->markInstalledOwner();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/settings/update/confirm', ['package_digest_sha256' => str_repeat('f', 64)])
            ->assertStatus(409)->assertJson(['code' => 'update_invalid_state']);
        $this->assertSame('root', $this->pointer->current()['kind']);
        $this->assertSame('staged', $this->journal->state()['phase']);
    }

    public function test_switch_refuses_an_unresolved_restore_before_pointer_mutation(): void
    {
        config(['backup.restore.journal_path' => $this->directory.'/restore/state.json']);
        (new RestoreJournal)->startUpload(str_repeat('b', 32));
        try {
            $this->app->make(SwitchSystemUpdateRelease::class)->handle($this->packageDigest);
            $this->fail('An unresolved restore must block activation.');
        } catch (UpdateException $exception) {
            $this->assertSame('update_lifecycle_conflict', $exception->codeName());
        }
        $this->assertSame('root', $this->pointer->current()['kind']);
    }

    private function markInstalledOwner(): User
    {
        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->directory.'/.installed',
        ]);
        $owner = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => '0.1.0-alpha.1',
            'installation_id' => str_repeat('a', 32),
            'database_fingerprint' => str_repeat('b', 64),
            'owner_user_id' => $owner->id,
        ]);

        return $owner;
    }

    private function seedStagedJournal(): void
    {
        $this->journal->startUpload($this->uploadId);
        $this->journal->transition('uploaded', 'verified', [
            'package_digest_sha256' => $this->packageDigest,
            'target_product_version' => $this->targetVersion,
        ]);
        $this->journal->transition('verified', 'preparing', [
            'source_release_kind' => 'root',
            'source_release_id' => null,
            'source_product_version' => '0.1.0-alpha.1',
            'source_schema_fingerprint' => str_repeat('c', 64),
        ]);
        $this->journal->transition('preparing', 'quiescing');
        $this->journal->transition('quiescing', 'snapshot_ready', [
            'safety_snapshot_id' => $this->uploadId,
            'pre_update_migration_history_sha256' => str_repeat('d', 64),
        ]);
        $this->journal->transition('snapshot_ready', 'staged', [
            'target_release_id' => $this->targetReleaseId,
            'target_product_version' => $this->targetVersion,
            'target_schema_fingerprint' => str_repeat('e', 64),
        ]);
    }

    private function seedSlot(string $releaseId, string $version, string $digest): void
    {
        $slot = $this->releaseRoot.'/'.$releaseId;
        File::ensureDirectoryExists($slot);
        File::put($slot.'/VERSION', $version."\n");
        File::put($slot.'/release.json', json_encode([
            'schema' => 'seo-release-slot/1',
            'release_id' => $releaseId,
            'product_version' => $version,
            'package_digest_sha256' => $digest,
            'launcher_schema' => 'seo-launcher/1',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        @chmod($slot.'/release.json', 0600);
    }

    /** @return list<array{migration: string, batch: int}> */
    private function migrationHistory(): array
    {
        return DB::table('migrations')
            ->orderBy('id')
            ->get(['migration', 'batch'])
            ->map(static fn (object $row): array => [
                'migration' => (string) $row->migration,
                'batch' => (int) $row->batch,
            ])
            ->all();
    }
}
