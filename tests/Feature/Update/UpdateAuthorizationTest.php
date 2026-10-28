<?php

namespace Tests\Feature\Update;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Update\UpdateJournal;
use App\Support\InstallationState;
use App\Support\Lifecycle\ProductVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UpdateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/update-authorization-'.bin2hex(random_bytes(6)));
        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->directory.'/.installed',
            'update.upload_path' => $this->directory.'/lifecycle/update/uploads',
            'backup.restore.journal_path' => $this->directory.'/lifecycle/restore/state.json',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function test_update_status_is_authenticated_verified_and_system_owner_only(): void
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $otherOwner = $this->workspaceOwner(true);
        $unverified = $this->workspaceOwner(false);
        $this->markInstalled($owner);

        $this->get('/settings/update')
            ->assertRedirect('/login');

        $this->actingAs($unverified)
            ->get('/settings/update')
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($otherOwner)
            ->get('/settings/update')
            ->assertForbidden();

        $this->actingAs($owner)
            ->getJson('/settings/update')
            ->assertOk()
            ->assertJson([
                'current_product_version' => (new ProductVersion)->requireDistributable(),
                'status' => 'ready',
            ])
            ->assertHeader('Cache-Control', 'no-cache, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_owner_can_cancel_a_pre_switch_update_and_leave_maintenance(): void
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $otherOwner = $this->workspaceOwner(true);
        $this->markInstalled($owner);
        config(['backup.lifecycle_mutex_path' => $this->directory.'/lifecycle/lifecycle.lock']);
        $journal = new UpdateJournal($this->directory.'/lifecycle/update/state.json');
        $this->app->instance(UpdateJournal::class, $journal);
        File::ensureDirectoryExists($this->directory.'/lifecycle/update');
        File::put($this->directory.'/lifecycle/update/state.json', json_encode([
            'phase' => 'quiescing',
            'upload_id' => str_repeat('a', 32),
        ], JSON_THROW_ON_ERROR));

        $this->actingAs($owner)->getJson('/settings/update')
            ->assertOk()
            ->assertJson(['status' => 'quiescing', 'canCancel' => true, 'canContinue' => false]);
        $this->actingAs($otherOwner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/settings/update/cancel')
            ->assertForbidden();
        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => 0])
            ->post('/settings/update/cancel')
            ->assertRedirect(route('password.confirm'));
        $this->assertSame('quiescing', $journal->state()['phase'] ?? null);

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/settings/update/cancel')
            ->assertOk()
            ->assertJson(['status' => 'ready'])
            ->assertHeader('Cache-Control', 'no-cache, no-store, private');
        $this->assertNull($journal->state());
    }

    public function test_update_verify_requires_recent_password_and_system_owner(): void
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $otherOwner = $this->workspaceOwner(true);
        $this->markInstalled($owner);

        $payload = fn (): array => [
            'update' => UploadedFile::fake()->createWithContent('invalid.seoupdate', 'invalid'),
        ];

        $this->post('/settings/update/verify', $payload())
            ->assertRedirect('/login');

        $this->actingAs($otherOwner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/settings/update/verify', $payload())
            ->assertForbidden();

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => 0])
            ->post('/settings/update/verify', $payload())
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/settings/update/verify', $payload())
            ->assertStatus(422)
            ->assertJson([
                'code' => 'update_invalid_package',
            ])
            ->assertHeader('Cache-Control', 'no-cache, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_update_verify_is_throttled_per_system_owner_after_three_attempts(): void
    {
        $owner = User::factory()->create([
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $this->markInstalled($owner);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->actingAs($owner)
                ->withSession(['auth.password_confirmed_at' => time()])
                ->post('/settings/update/verify', [
                    'update' => UploadedFile::fake()->createWithContent("invalid-{$attempt}.seoupdate", 'invalid'),
                ])
                ->assertStatus(422);
        }

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/settings/update/verify', [
                'update' => UploadedFile::fake()->createWithContent('invalid-4.seoupdate', 'invalid'),
            ])
            ->assertTooManyRequests();
    }

    private function workspaceOwner(bool $verified): User
    {
        $user = User::factory()->create([
            'email_verified_at' => $verified ? now() : null,
            'onboarded_at' => now(),
        ]);
        $workspace = Workspace::query()->create([
            'name' => 'Workspace '.bin2hex(random_bytes(4)),
        ]);
        Membership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceRole::Owner,
        ]);

        return $user;
    }

    private function markInstalled(User $owner): void
    {
        (new InstallationState)->markInstalled([
            'installer_schema_version' => 2,
            'product_version' => (new ProductVersion)->requireDistributable(),
            'installation_id' => str_repeat('a', 32),
            'database_fingerprint' => str_repeat('b', 64),
            'owner_user_id' => $owner->id,
        ]);
    }
}
