<?php

namespace Tests\Feature\Update;

use App\Models\User;
use App\Services\Update\UpdateJournal;
use App\Support\InstallationState;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UpdateSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/update-settings-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->directory);
        config(['installer.assume_installed' => false, 'installer.lock_path' => $this->directory.'/.installed']);
        $this->app->instance(UpdateJournal::class, new UpdateJournal($this->directory.'/state.json'));
        $this->owner = User::factory()->create(['onboarded_at' => now()]);
        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2, 'product_version' => (new ProductVersion)->requireDistributable(),
            'installation_id' => str_repeat('a', 32), 'database_fingerprint' => str_repeat('b', 64),
            'owner_user_id' => $this->owner->id,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_owner_gets_persian_update_page_and_other_users_cannot_access_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings/update')->assertForbidden();
        $this->actingAs($this->owner)->get('/settings/update')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/update-recovery')
            ->where('systemUpdate.canManage', true)
            ->where('update.status', 'ready')
            ->where('update.current_product_version', (new ProductVersion)->requireDistributable())
            ->where('update.preview', null)
            ->where('update.canContinue', false)
            ->where('update.canRecover', false));
        $this->actingAs(User::factory()->create())->get('/settings/profile')
            ->assertInertia(fn (Assert $page) => $page->where('systemUpdate.canManage', false));
    }

    public function test_verified_preview_and_json_status_expose_only_allowlisted_operator_fields(): void
    {
        File::put($this->directory.'/state.json', json_encode([
            'phase' => 'verified', 'upload_id' => str_repeat('c', 32),
            'runtime_key_sha256' => 'private-runtime-identity', 'source_absolute_path' => '/private/installation',
            'preview' => [
                'package_digest_sha256' => str_repeat('d', 64), 'target_product_version' => '0.1.0-alpha.2',
                'source_product_version' => '0.1.0-alpha.1', 'channel' => 'development',
                'minimum_php_version' => '8.3.0', 'required_php_extensions' => ['pdo'],
                'migration_count' => 2, 'credential' => 'private-credential',
            ],
        ], JSON_THROW_ON_ERROR));
        $response = $this->actingAs($this->owner)->get('/settings/update');
        $response->assertInertia(fn (Assert $page) => $page
            ->where('update.preview.channel', 'development')
            ->where('update.preview.migration_count', 2)
            ->where('update.preview.package_digest_sha256', str_repeat('d', 64))
            ->missing('update.upload_id')->missing('update.runtime_key_sha256')
            ->missing('update.source_absolute_path')->missing('update.preview.credential'));
        $this->actingAs($this->owner)->getJson('/settings/update')->assertOk()
            ->assertJsonPath('status', 'verified')
            ->assertJsonMissing(['credential' => 'private-credential'])
            ->assertHeader('Cache-Control', 'no-cache, no-store, private');
        $state = json_decode(File::get($this->directory.'/state.json'), true, flags: JSON_THROW_ON_ERROR);
        $state['phase'] = 'staged';
        File::put($this->directory.'/state.json', json_encode($state, JSON_THROW_ON_ERROR));
        $this->get('/settings/update')->assertInertia(fn (Assert $page) => $page
            ->where('update.status', 'staged')
            ->where('update.preview.package_digest_sha256', str_repeat('d', 64)));
    }

    public function test_server_phase_controls_continuation_and_ambiguous_recovery_never_exposes_an_automatic_recovery_action(): void
    {
        File::put($this->directory.'/state.json', json_encode(['phase' => 'target_active'], JSON_THROW_ON_ERROR));
        $this->actingAs($this->owner)->get('/settings/update')
            ->assertInertia(fn (Assert $page) => $page->where('update.canContinue', true)->where('update.canRecover', false));
        File::put($this->directory.'/state.json', json_encode(['phase' => 'migration_rollback'], JSON_THROW_ON_ERROR));
        $this->get('/settings/update')
            ->assertInertia(fn (Assert $page) => $page->where('update.canContinue', false)->where('update.canRecover', true));
        File::put($this->directory.'/state.json', json_encode(['phase' => 'recovery_required', 'migration_started' => true], JSON_THROW_ON_ERROR));
        $this->get('/settings/update')
            ->assertInertia(fn (Assert $page) => $page->where('update.status', 'recovery_required')->where('update.canContinue', false)->where('update.canRecover', false));
    }

    public function test_password_reconfirmation_returns_to_update_during_maintenance(): void
    {
        File::put($this->directory.'/state.json', json_encode(['phase' => 'target_active'], JSON_THROW_ON_ERROR));
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => 0])
            ->get('/settings/update/authorize')->assertRedirect(route('password.confirm'))
            ->assertSessionHas('url.intended', url('/settings/update/authorize'));
        $this->post('/user/confirm-password', ['password' => 'password'])
            ->assertRedirect('/settings/update/authorize');
        $this->get('/settings/update/authorize')->assertRedirect('/settings/update');
        $this->get('/settings/update')->assertOk();
    }

    public function test_lifecycle_budget_allows_a_multi_request_recovery_and_still_limits_repeated_attempts(): void
    {
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/settings/update/continue')->assertStatus(409);
        }
        $this->postJson('/settings/update/continue')->assertTooManyRequests();
    }

    public function test_continuation_and_recovery_routes_enforce_recent_password_and_system_owner_and_sanitize_invalid_state(): void
    {
        foreach (['continue', 'recover'] as $action) {
            $this->actingAs(User::factory()->create())
                ->withSession(['auth.password_confirmed_at' => time()])
                ->postJson('/settings/update/'.$action)->assertForbidden();
            $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => 0])
                ->postJson('/settings/update/'.$action)->assertStatus(423);
            $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
                ->postJson('/settings/update/'.$action)->assertStatus(409)
                ->assertJsonStructure(['code', 'message'])
                ->assertJsonMissingPath('exception')->assertJsonMissingPath('trace');
        }
    }
}
