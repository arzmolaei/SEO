<?php

namespace Tests\Feature\Keywords;

use App\Models\Project;
use App\Models\User;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** @return array{User, Project} */
    private function project(): array
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        return [$owner, $project];
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
