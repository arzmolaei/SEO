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
            // Only built, in-scope sections are offered (docs/OWNER_INTENT.md).
            ->has('projectContext.navigation', 8)
            ->where('projectContext.navigation.0.key', 'overview')
            ->where('projectContext.navigation.1.key', 'keywords')
            ->where('projectContext.navigation.2.key', 'imports')
            ->where('projectContext.navigation.2.href', route('projects.keyword-imports.index', $project))
            ->where('projectContext.navigation.3.key', 'reviews')
            ->where('projectContext.navigation.4.key', 'pages')
            ->where('projectContext.navigation.4.href', route('projects.pages.index', $project))
            ->where('projectContext.navigation.5.key', 'site-audit')
            ->where('projectContext.navigation.5.href', route('projects.crawls.index', $project))
            ->where('projectContext.navigation.6.key', 'content')
            ->where('projectContext.navigation.6.href', route('projects.content.index', $project))
            ->where('projectContext.navigation.7.key', 'tasks')
            ->where('projectContext.navigation.7.href', route('projects.tasks.index', $project))
        );
    }

    public function test_project_sections_remain_available_to_members_regardless_of_guided_mode(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $response = $this->actingAs($owner)->get("/projects/{$project->id}/reports");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('projects/section')
            ->where('section', 'reports')
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
