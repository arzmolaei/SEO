<?php

namespace Tests\Feature\Keywords;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordImportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_outsider_cannot_stage_keyword_rows_by_guessing_project_id(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Private');

        $this->actingAs($outsider)
            ->postJson("/projects/{$project->id}/keyword-imports/stage", $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('staged_keyword_rows', 0);
    }

    public function test_viewer_cannot_stage_keyword_rows(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $workspace->memberships()->create([
            'user_id' => $viewer->id,
            'role' => WorkspaceRole::Viewer,
        ]);
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Private');

        $this->actingAs($viewer)
            ->postJson("/projects/{$project->id}/keyword-imports/stage", $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('staged_keyword_rows', 0);
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'source' => 'generic_csv',
            'original_filename' => 'keywords.csv',
            'mapping' => [
                'keyword' => 'Keyword',
            ],
            'rows' => [
                ['Keyword' => 'سئو سایت'],
            ],
        ];
    }
}
