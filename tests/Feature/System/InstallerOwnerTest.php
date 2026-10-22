<?php

namespace Tests\Feature\System;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Installer\InstallerJournal;
use App\Support\Installer\InstallerMutex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallerOwnerTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private InstallerJournal $journal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/installer-owner-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->directory);

        config([
            'installer.assume_installed' => false,
            'installer.lock_path' => $this->directory.'/.installed',
            'installer.journal_path' => $this->directory.'/journal.json',
            'installer.mutex_path' => $this->directory.'/installer.lock',
        ]);

        $this->journal = new InstallerJournal($this->directory.'/journal.json');
        $this->app->instance(InstallerJournal::class, $this->journal);
        $this->app->instance(InstallerMutex::class, new InstallerMutex($this->directory.'/installer.lock'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_owner_phase_exposes_only_the_owner_creation_action(): void
    {
        $this->prepareOwnerPhase();

        $this->get('/install')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('install/index')
                ->where('installer.phase', 'owner')
                ->where('installer.actions', ['create_owner']));
    }

    public function test_owner_submission_creates_exactly_one_verified_owner_workspace_and_membership(): void
    {
        $this->prepareOwnerPhase();

        $response = $this->post('/install/owner', $this->validPayload());

        $response->assertRedirect('/install')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('memberships', 1);

        $owner = User::query()->sole();
        $workspace = Workspace::query()->sole();
        $membership = Membership::query()->sole();

        $this->assertSame('owner@example.test', $owner->email);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue(Hash::check('Strong!Password123', $owner->password));
        $this->assertSame('فضای کاری اصلی', $workspace->name);
        $this->assertSame($workspace->id, $membership->workspace_id);
        $this->assertSame($owner->id, $membership->user_id);
        $this->assertSame(WorkspaceRole::Owner, $membership->role);

        $journal = $this->journal->read();
        $serialized = json_encode($journal, JSON_THROW_ON_ERROR);

        $this->assertSame('operations', $journal['phase']);
        $this->assertSame($owner->id, $journal['owner_user_id']);
        $this->assertStringNotContainsString('Strong!Password123', $serialized);
        $this->assertStringNotContainsString('password', strtolower($serialized));
    }

    public function test_duplicate_identical_owner_submission_is_idempotent(): void
    {
        $this->prepareOwnerPhase();
        $payload = $this->validPayload();

        $this->post('/install/owner', $payload)
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();
        $ownerId = User::query()->sole()->id;

        $this->post('/install/owner', $payload)
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertSame($ownerId, User::query()->sole()->id);
        $this->assertSame($ownerId, $this->journal->read()['owner_user_id']);
    }

    public function test_changed_duplicate_owner_input_fails_safely_without_creating_more_records(): void
    {
        $this->prepareOwnerPhase();

        $this->post('/install/owner', $this->validPayload())
            ->assertRedirect('/install')
            ->assertSessionHasNoErrors();

        $this->from('/install')->post('/install/owner', [
            ...$this->validPayload(),
            'email' => 'different@example.test',
        ])
            ->assertRedirect('/install')
            ->assertSessionHasErrors('owner');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('workspaces', 1);
        $this->assertDatabaseCount('memberships', 1);
    }

    public function test_unrelated_preexisting_user_blocks_owner_bootstrap(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);
        $this->prepareOwnerPhase();

        $this->from('/install')->post('/install/owner', $this->validPayload())
            ->assertRedirect('/install')
            ->assertSessionHasErrors('owner');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_owner_password_policy_is_local_and_rejects_weak_passwords(): void
    {
        $this->prepareOwnerPhase();

        $this->from('/install')->post('/install/owner', [
            ...$this->validPayload(),
            'password' => 'weakpassword',
            'password_confirmation' => 'weakpassword',
        ])
            ->assertRedirect('/install')
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    private function prepareOwnerPhase(): void
    {
        $this->journal->replace([
            'phase' => 'owner',
            'database_fingerprint' => str_repeat('a', 64),
            'migrations_completed_at' => '2026-10-22T20:00:00+00:00',
        ]);
    }

    /** @return array{name: string, email: string, workspace_name: string, password: string, password_confirmation: string} */
    private function validPayload(): array
    {
        return [
            'name' => 'مالک اولیه',
            'email' => 'Owner@Example.test',
            'workspace_name' => 'فضای کاری اصلی',
            'password' => 'Strong!Password123',
            'password_confirmation' => 'Strong!Password123',
        ];
    }
}
