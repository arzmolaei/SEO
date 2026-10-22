<?php

namespace Tests\Feature\Keywords;

use App\Models\Project;
use App\Models\SavedKeywordView;
use App\Models\User;
use App\Services\Keywords\CreateSavedKeywordView;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedKeywordViewVersionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_grid_rejects_saved_view_from_an_unsupported_future_schema_version(): void
    {
        [$owner, $project, $view] = $this->savedView();
        $view->update(['schema_version' => 2]);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/keywords?view={$view->id}")
            ->assertUnprocessable();
    }

    public function test_grid_revalidates_stored_state_before_applying_a_saved_view(): void
    {
        [$owner, $project, $view] = $this->savedView();
        $state = $view->state;
        $state['query']['unknown_filter'] = 'unexpected';
        $view->update(['state' => $state]);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/keywords?view={$view->id}")
            ->assertUnprocessable();
    }

    /** @return array{User, Project, SavedKeywordView} */
    private function savedView(): array
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');
        $view = app(CreateSavedKeywordView::class)->handle(
            actor: $owner,
            project: $project,
            name: 'Safe view',
            state: [
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
            ],
        );

        return [$owner, $project, $view];
    }
}
