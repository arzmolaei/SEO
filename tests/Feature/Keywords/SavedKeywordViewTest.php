<?php

namespace Tests\Feature\Keywords;

use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\SavedKeywordView;
use App\Models\User;
use App\Services\Keywords\CreateSavedKeywordView;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SavedKeywordViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_member_can_create_a_versioned_personal_saved_keyword_view(): void
    {
        [$owner, $project] = $this->project();

        $response = $this->actingAs($owner)->post("/projects/{$project->id}/keyword-views", [
            'name' => 'Quick Wins',
            'state' => $this->validState(),
        ]);

        $response->assertRedirect("/projects/{$project->id}/keywords");
        $this->assertDatabaseCount('saved_keyword_views', 1);

        $saved = DB::table('saved_keyword_views')->first();

        $this->assertNotNull($saved);
        $this->assertSame($owner->id, $saved->user_id);
        $this->assertSame($project->workspace_id, $saved->workspace_id);
        $this->assertSame($project->id, $saved->project_id);
        $this->assertSame('Quick Wins', $saved->name);
        $this->assertSame(1, $saved->schema_version);
        $this->assertSame(0, $saved->is_default);
        $this->assertSame($this->validState(), json_decode((string) $saved->state, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_saved_view_rejects_unknown_state_fields(): void
    {
        [$owner, $project] = $this->project();
        $state = $this->validState();
        $state['query']['unknown_filter'] = 'surprise';

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/keyword-views", [
                'name' => 'Unsafe',
                'state' => $state,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('saved_keyword_views', 0);
    }

    public function test_saved_view_rejects_unknown_filter_operators(): void
    {
        [$owner, $project] = $this->project();
        $state = $this->validState();
        $state['query']['volume_operator'] = 'contains';

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/keyword-views", [
                'name' => 'Unsafe',
                'state' => $state,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('saved_keyword_views', 0);
    }

    public function test_outsider_cannot_create_saved_view_by_guessing_project_id(): void
    {
        [, $project] = $this->project();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post("/projects/{$project->id}/keyword-views", [
                'name' => 'Stolen',
                'state' => $this->validState(),
            ])
            ->assertForbidden();
    }

    public function test_owner_can_update_own_saved_view_with_revalidated_state(): void
    {
        [$owner, $project] = $this->project();
        $view = $this->savedView($owner, $project, 'Quick Wins');
        $updatedState = $this->validState();
        $updatedState['query']['source'] = 'ahrefs';
        $updatedState['columns'] = ['keyword', 'normalized', 'status', 'volume'];

        $this->actingAs($owner)
            ->patch("/projects/{$project->id}/keyword-views/{$view->id}", [
                'name' => 'Priority Review',
                'state' => $updatedState,
            ])
            ->assertRedirect("/projects/{$project->id}/keywords");

        $view->refresh();
        $this->assertSame('Priority Review', $view->name);
        $this->assertSame($updatedState, $view->state);
        $this->assertSame(1, $view->schema_version);
    }

    public function test_same_project_member_cannot_update_another_users_saved_view(): void
    {
        [$owner, $project] = $this->project();
        $view = $this->savedView($owner, $project, 'Owner view');
        $member = $this->addProjectMember($project);

        $this->actingAs($member)
            ->patch("/projects/{$project->id}/keyword-views/{$view->id}", [
                'name' => 'Hijacked',
                'state' => $this->validState(),
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('saved_keyword_views', [
            'id' => $view->id,
            'name' => 'Owner view',
        ]);
    }

    public function test_setting_default_saved_view_keeps_exactly_one_default_per_user_and_project(): void
    {
        [$owner, $project] = $this->project();
        $first = $this->savedView($owner, $project, 'First');
        $second = $this->savedView($owner, $project, 'Second');

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/keyword-views/{$first->id}/default")
            ->assertRedirect("/projects/{$project->id}/keywords");

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/keyword-views/{$second->id}/default")
            ->assertRedirect("/projects/{$project->id}/keywords");

        $this->assertDatabaseHas('saved_keyword_views', [
            'id' => $first->id,
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('saved_keyword_views', [
            'id' => $second->id,
            'is_default' => true,
        ]);
        $this->assertSame(1, SavedKeywordView::query()
            ->where('user_id', $owner->id)
            ->where('project_id', $project->id)
            ->where('is_default', true)
            ->count());
    }

    public function test_saved_view_applies_to_grid_and_explicit_query_overrides_saved_values(): void
    {
        [$owner, $project] = $this->project();
        $view = $this->savedView($owner, $project, 'Quick Wins');

        $response = $this->actingAs($owner)->get(
            "/projects/{$project->id}/keywords?view={$view->id}&status=ready",
        );

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('projects/keywords')
            ->where('filters.source', 'semrush')
            ->where('filters.status', 'ready')
            ->where('filters.volume_operator', 'gte')
            ->where('filters.volume', 500)
            ->where('filters.sort', 'volume')
            ->where('filters.direction', 'desc')
            ->where('filters.per_page', 50)
            ->where('activeView.id', $view->id)
            ->where('activeView.name', 'Quick Wins')
            ->has('savedViews', 1)
            ->where('savedViews.0.id', $view->id)
        );
    }

    public function test_same_project_member_cannot_apply_another_users_saved_view(): void
    {
        [$owner, $project] = $this->project();
        $view = $this->savedView($owner, $project, 'Owner view');
        $member = $this->addProjectMember($project);

        $this->actingAs($member)
            ->get("/projects/{$project->id}/keywords?view={$view->id}")
            ->assertForbidden();
    }

    public function test_default_view_applies_only_when_no_explicit_keyword_query_is_present(): void
    {
        [$owner, $project] = $this->project();
        $view = $this->savedView($owner, $project, 'Default');
        $view->update(['is_default' => true]);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/keywords")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.source', 'semrush')
                ->where('filters.status', 'review')
                ->where('activeView.id', $view->id)
            );

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/keywords?q=manual")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.q', 'manual')
                ->where('filters.source', null)
                ->where('activeView', null)
            );
    }

    /** @return array{User, Project} */
    private function project(): array
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        return [$owner, $project];
    }

    private function savedView(User $owner, Project $project, string $name): SavedKeywordView
    {
        return app(CreateSavedKeywordView::class)->handle(
            actor: $owner,
            project: $project,
            name: $name,
            state: $this->validState(),
        );
    }

    private function addProjectMember(Project $project): User
    {
        $member = User::factory()->create();

        DB::table('memberships')->insert([
            'workspace_id' => $project->workspace_id,
            'user_id' => $member->id,
            'role' => WorkspaceRole::SeoSpecialist->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $member;
    }

    /** @return array{query: array<string, mixed>, columns: list<string>} */
    private function validState(): array
    {
        return [
            'query' => [
                'q' => null,
                'source' => 'semrush',
                'status' => 'review',
                'volume_operator' => 'gte',
                'volume' => 500,
                'sort' => 'volume',
                'direction' => 'desc',
                'per_page' => 50,
            ],
            'columns' => ['keyword', 'status', 'volume', 'source'],
        ];
    }
}
