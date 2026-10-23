<?php

namespace Tests\Feature\System;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\InstallationState;
use App\Support\SystemOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class SystemOwnerTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/system-owner-'.bin2hex(random_bytes(6)));
        $this->lockPath = $this->directory.'/.installed';
        File::ensureDirectoryExists($this->directory);

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->lockPath,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_persisted_system_owner_is_authoritative_even_when_other_workspace_owners_exist(): void
    {
        $systemOwner = $this->createOwner('system-owner@example.test');
        $this->createOwner('other-owner@example.test');

        $state = new InstallationState($this->lockPath, false);
        $state->markInstalled([
            'installation_id' => 'destination-installation',
            'owner_user_id' => $systemOwner->id,
        ]);

        $owner = new SystemOwner($state);

        $this->assertTrue($owner->resolve()->is($systemOwner));
        $this->assertTrue($owner->is($systemOwner));
        $this->assertFalse($owner->is(User::query()->whereKeyNot($systemOwner->id)->firstOrFail()));
    }

    public function test_legacy_lock_resolves_one_unambiguous_owner_user_and_persists_it_once(): void
    {
        $legacyOwner = $this->createOwner('legacy-owner@example.test');
        $secondWorkspace = Workspace::query()->create(['name' => 'فضای دوم']);
        Membership::query()->create([
            'workspace_id' => $secondWorkspace->id,
            'user_id' => $legacyOwner->id,
            'role' => WorkspaceRole::Owner,
        ]);

        $state = new InstallationState($this->lockPath, false);
        $state->markInstalled(['installation_id' => 'legacy-installation']);

        $owner = new SystemOwner($state);
        $resolved = $owner->resolve();

        $this->assertTrue($resolved->is($legacyOwner));
        $this->assertSame($legacyOwner->id, $state->details()['owner_user_id']);

        Membership::query()->delete();

        $this->assertTrue($owner->resolve()->is($legacyOwner));
    }

    public function test_legacy_lock_fails_closed_when_multiple_distinct_owner_users_exist(): void
    {
        $this->createOwner('first-owner@example.test');
        $this->createOwner('second-owner@example.test');

        $state = new InstallationState($this->lockPath, false);
        $state->markInstalled(['installation_id' => 'legacy-installation']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('System owner identity is ambiguous.');

        (new SystemOwner($state))->resolve();
    }

    public function test_persisted_system_owner_fails_closed_when_user_no_longer_exists(): void
    {
        $state = new InstallationState($this->lockPath, false);
        $state->markInstalled([
            'installation_id' => 'destination-installation',
            'owner_user_id' => 999999,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('System owner account is unavailable.');

        (new SystemOwner($state))->resolve();
    }

    private function createOwner(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
            'onboarded_at' => now(),
        ]);
        $workspace = Workspace::query()->create(['name' => $email]);

        Membership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceRole::Owner,
        ]);

        return $user;
    }
}
