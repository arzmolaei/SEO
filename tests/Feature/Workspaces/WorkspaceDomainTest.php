<?php

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\CreateWorkspace;
use App\Services\Workspaces\UpdateMembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkspaceDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_workspace_makes_the_creator_an_owner(): void
    {
        $user = User::factory()->create();

        $workspace = app(CreateWorkspace::class)->handle($user, 'My SEO Studio');

        $this->assertInstanceOf(Workspace::class, $workspace);
        $this->assertDatabaseHas('workspaces', [
            'id' => $workspace->id,
            'name' => 'My SEO Studio',
        ]);
        $this->assertDatabaseHas('memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceRole::Owner->value,
        ]);
    }

    public function test_an_owner_can_change_a_members_role(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $membership = $workspace->memberships()->create([
            'user_id' => $member->id,
            'role' => WorkspaceRole::SeoSpecialist,
        ]);

        app(UpdateMembershipRole::class)->handle(
            actor: $owner,
            membership: $membership,
            role: WorkspaceRole::SeoManager,
        );

        $this->assertSame(
            WorkspaceRole::SeoManager,
            $membership->fresh()->role,
        );
    }

    public function test_a_non_privileged_member_cannot_change_roles(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $target = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $workspace->memberships()->create([
            'user_id' => $member->id,
            'role' => WorkspaceRole::SeoSpecialist,
        ]);
        $targetMembership = $workspace->memberships()->create([
            'user_id' => $target->id,
            'role' => WorkspaceRole::Viewer,
        ]);

        $this->expectException(ValidationException::class);

        app(UpdateMembershipRole::class)->handle(
            actor: $member,
            membership: $targetMembership,
            role: WorkspaceRole::Admin,
        );
    }

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $ownerMembership = $workspace->memberships()->firstOrFail();

        $this->expectException(ValidationException::class);

        app(UpdateMembershipRole::class)->handle(
            actor: $owner,
            membership: $ownerMembership,
            role: WorkspaceRole::Admin,
        );
    }
}
