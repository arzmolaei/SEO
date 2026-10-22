<?php

namespace Tests\Feature\Keywords;

use App\Models\User;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageKeywordImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_manager_can_stage_a_mapped_keyword_import_without_canonical_writes(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $response = $this->actingAs($owner)->postJson(
            "/projects/{$project->id}/keyword-imports/stage",
            [
                'source' => 'generic_csv',
                'original_filename' => 'keywords.csv',
                'mapping' => [
                    'keyword' => 'Keyword',
                    'volume' => 'Search Volume',
                    'difficulty' => 'KD',
                ],
                'rows' => [
                    ['Keyword' => 'سئو سایت', 'Search Volume' => '1200', 'KD' => '31'],
                    ['Keyword' => 'خدمات سئو', 'Search Volume' => '800', 'KD' => '27'],
                ],
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.source', 'generic_csv')
            ->assertJsonPath('data.original_filename', 'keywords.csv')
            ->assertJsonPath('data.status', 'staged')
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.valid_rows', 2)
            ->assertJsonPath('data.invalid_rows', 0)
            ->assertJsonPath('data.error_count', 0);

        $batchId = $response->json('data.id');

        $this->assertDatabaseHas('import_batches', [
            'id' => $batchId,
            'project_id' => $project->id,
            'source' => 'generic_csv',
            'original_filename' => 'keywords.csv',
            'status' => 'staged',
            'total_rows' => 2,
            'valid_rows' => 2,
            'invalid_rows' => 0,
            'error_count' => 0,
        ]);

        $this->assertDatabaseCount('staged_keyword_rows', 2);
        $this->assertDatabaseHas('staged_keyword_rows', [
            'import_batch_id' => $batchId,
            'row_number' => 1,
            'keyword_original' => 'سئو سایت',
        ]);
        $this->assertDatabaseHas('staged_keyword_rows', [
            'import_batch_id' => $batchId,
            'row_number' => 2,
            'keyword_original' => 'خدمات سئو',
        ]);
    }
}
