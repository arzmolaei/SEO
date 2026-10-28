<?php

namespace Tests\Feature\Home;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_home_lists_only_the_members_own_workspaces_and_projects(): void
    {
        [$user, $workspace] = $this->member(WorkspaceRole::Owner, 'آژانس من');
        Project::query()->create(['workspace_id' => $workspace->id, 'name' => 'سایت فروشگاه']);
        [, $foreign] = $this->member(WorkspaceRole::Owner, 'فضای دیگران');
        Project::query()->create(['workspace_id' => $foreign->id, 'name' => 'پروژه خصوصی دیگران']);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->has('workspaces', 1)
                ->where('workspaces.0.name', 'آژانس من')
                ->where('workspaces.0.canCreateProjects', true)
                ->has('projects.data', 1)
                ->where('projects.data.0.name', 'سایت فروشگاه')
                ->where('projects.data.0.workspace_name', 'آژانس من')
                ->where('projects.data.0.overview_url', route('projects.overview', Project::query()->where('name', 'سایت فروشگاه')->firstOrFail()))
            );
    }

    public function test_projects_are_paginated_on_the_server(): void
    {
        [$user, $workspace] = $this->member(WorkspaceRole::Owner, 'فضای بزرگ');
        foreach (range(1, 30) as $index) {
            Project::query()->create(['workspace_id' => $workspace->id, 'name' => 'پروژه '.$index]);
        }

        $this->actingAs($user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects.data', 25)
                ->where('projects.total', 30));
        $this->actingAs($user)->get('/dashboard?page=2')
            ->assertInertia(fn (Assert $page) => $page->has('projects.data', 5));
    }

    public function test_project_managers_create_projects_from_the_home_form_and_viewers_cannot(): void
    {
        [$owner, $workspace] = $this->member(WorkspaceRole::Owner, 'فضای کاری');
        $viewer = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        Membership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);

        $this->actingAs($viewer)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('workspaces.0.canCreateProjects', false));
        $this->actingAs($viewer)
            ->post("/workspaces/{$workspace->id}/projects", ['name' => 'نباید ساخته شود'])
            ->assertForbidden();

        $response = $this->actingAs($owner)
            ->post("/workspaces/{$workspace->id}/projects", ['name' => '  وبلاگ تخصصی  ']);

        $project = Project::query()->where('name', 'وبلاگ تخصصی')->firstOrFail();
        $response->assertRedirect(route('projects.overview', $project));
        $this->assertSame($workspace->id, $project->workspace_id);
    }

    public function test_project_name_is_required(): void
    {
        [$owner, $workspace] = $this->member(WorkspaceRole::Owner, 'فضای کاری');

        $this->actingAs($owner)
            ->from('/dashboard')
            ->post("/workspaces/{$workspace->id}/projects", ['name' => '   '])
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors('name');
    }

    /** @return array{User, Workspace} */
    private function member(WorkspaceRole $role, string $workspaceName): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        $workspace = Workspace::query()->create(['name' => $workspaceName]);
        Membership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return [$user, $workspace];
    }
}
