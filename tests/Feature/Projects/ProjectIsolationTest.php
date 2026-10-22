<?php

namespace Tests\Feature\Projects;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_workspace_manager_can_create_list_show_and_update_projects(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');

        $createResponse = $this->actingAs($owner)->postJson(
            "/workspaces/{$workspace->id}/projects",
            ['name' => 'Example.com'],
        );

        $createResponse
            ->assertCreated()
            ->assertJsonPath('data.name', 'Example.com')
            ->assertJsonPath('data.workspace_id', $workspace->id);

        $projectId = $createResponse->json('data.id');

        $this->actingAs($owner)
            ->getJson("/workspaces/{$workspace->id}/projects")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $projectId);

        $this->actingAs($owner)
            ->getJson("/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('data.id', $projectId);

        $this->actingAs($owner)
            ->patchJson("/projects/{$projectId}", ['name' => 'Updated.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated.com');
    }

    public function test_a_member_can_only_list_and_show_projects_in_their_workspace(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $workspace->memberships()->create([
            'user_id' => $member->id,
            'role' => WorkspaceRole::Viewer,
        ]);

        $projectId = $this->actingAs($owner)
            ->postJson("/workspaces/{$workspace->id}/projects", ['name' => 'Visible'])
            ->json('data.id');

        $this->actingAs($member)
            ->getJson("/workspaces/{$workspace->id}/projects")
            ->assertOk()
            ->assertJsonPath('data.0.id', $projectId);

        $this->actingAs($member)
            ->getJson("/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('data.id', $projectId);
    }

    public function test_guessed_workspace_and_project_ids_are_forbidden_across_workspaces(): void
    {
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $firstWorkspace = app(CreateWorkspace::class)->handle($firstOwner, 'First');
        $secondWorkspace = app(CreateWorkspace::class)->handle($secondOwner, 'Second');

        $projectId = $this->actingAs($secondOwner)
            ->postJson("/workspaces/{$secondWorkspace->id}/projects", ['name' => 'Private'])
            ->json('data.id');

        $this->actingAs($firstOwner)
            ->getJson("/workspaces/{$secondWorkspace->id}/projects")
            ->assertForbidden();

        $this->actingAs($firstOwner)
            ->getJson("/projects/{$projectId}")
            ->assertForbidden();

        $this->actingAs($firstOwner)
            ->patchJson("/projects/{$projectId}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($firstOwner)
            ->postJson("/workspaces/{$secondWorkspace->id}/projects", ['name' => 'Injected'])
            ->assertForbidden();

        $this->assertDatabaseMissing('projects', [
            'workspace_id' => $secondWorkspace->id,
            'name' => 'Injected',
        ]);
    }

    public function test_a_viewer_cannot_create_or_update_projects(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $workspace->memberships()->create([
            'user_id' => $viewer->id,
            'role' => WorkspaceRole::Viewer,
        ]);

        $projectId = $this->actingAs($owner)
            ->postJson("/workspaces/{$workspace->id}/projects", ['name' => 'Managed'])
            ->json('data.id');

        $this->actingAs($viewer)
            ->postJson("/workspaces/{$workspace->id}/projects", ['name' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patchJson("/projects/{$projectId}", ['name' => 'Nope'])
            ->assertForbidden();
    }
}
