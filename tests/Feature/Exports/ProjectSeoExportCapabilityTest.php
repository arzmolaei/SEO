<?php

namespace Tests\Feature\Exports;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectSeoExportCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_keywords_page_exposes_export_capability_from_project_policy(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Export UI');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'example.com');
        $viewer = User::factory()->create();
        $workspace->memberships()->create([
            'user_id' => $viewer->id,
            'role' => WorkspaceRole::Viewer,
        ]);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/keywords")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canExport', true));

        $this->actingAs($viewer)
            ->get("/projects/{$project->id}/keywords")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canExport', false));
    }

    public function test_known_export_error_is_shared_once_as_flash_data(): void
    {
        [$owner, $project] = $this->project();

        $this->actingAs($owner)
            ->withSession(['export_error' => 'پیام خطای خروجی'])
            ->get("/projects/{$project->id}/keywords")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('flash.export_error', 'پیام خطای خروجی'));
    }

    /** @return array{User,Project} */
    private function project(): array
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Export flash');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'example.com');

        return [$owner, $project];
    }
}
