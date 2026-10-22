<?php

namespace Tests\Feature\Projects;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_member_can_open_overview_with_shared_project_context(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $workspace->memberships()->create([
            'user_id' => $member->id,
            'role' => WorkspaceRole::Viewer,
        ]);
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $response = $this->actingAs($member)->get("/projects/{$project->id}/overview");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('projects/overview')
            ->where('projectContext.project.id', $project->id)
            ->where('projectContext.project.name', 'Example.com')
            ->where('projectContext.project.workspace_id', $workspace->id)
            ->where('projectContext.mode', 'guided')
            ->has('projectContext.navigation', 11)
            ->where('projectContext.navigation.0.key', 'overview')
            ->where('projectContext.navigation.1.key', 'keywords')
            ->where('projectContext.navigation.10.key', 'settings')
        );
    }

    public function test_project_sections_remain_available_to_members_regardless_of_guided_mode(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $response = $this->actingAs($owner)->get("/projects/{$project->id}/keywords");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('projects/section')
            ->where('section', 'keywords')
            ->where('projectContext.mode', 'guided')
        );
    }

    public function test_non_member_cannot_open_project_shell_by_guessing_project_id(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Private');

        $this->actingAs($outsider)
            ->get("/projects/{$project->id}/overview")
            ->assertForbidden();
    }
}
