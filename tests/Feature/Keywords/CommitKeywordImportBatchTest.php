<?php

namespace Tests\Feature\Keywords;

use App\Models\User;
use App\Services\Keywords\CommitKeywordImportBatch;
use App\Services\Keywords\StageKeywordImport;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommitKeywordImportBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalized_keyword_variants_merge_to_one_canonical_keyword_with_independent_provenance(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $batch = app(StageKeywordImport::class)->handle(
            actor: $owner,
            project: $project,
            source: 'kwfinder',
            originalFilename: 'kwfinder.csv',
            mapping: [
                'keyword' => 'Keyword',
                'volume' => 'Search Volume',
            ],
            rows: [
                ['Keyword' => 'سئو سایت', 'Search Volume' => '1200'],
                ['Keyword' => 'سئو سايت', 'Search Volume' => '900'],
            ],
        );

        app(CommitKeywordImportBatch::class)->handle($owner, $batch);

        $this->assertDatabaseCount('keywords', 1);
        $this->assertDatabaseHas('keywords', [
            'project_id' => $project->id,
            'display_text' => 'سئو سایت',
            'normalized_text' => 'سئو سایت',
        ]);

        $this->assertDatabaseCount('keyword_sources', 2);
        $this->assertDatabaseHas('keyword_sources', [
            'source' => 'kwfinder',
            'original_text' => 'سئو سایت',
        ]);
        $this->assertDatabaseHas('keyword_sources', [
            'source' => 'kwfinder',
            'original_text' => 'سئو سايت',
        ]);

        $batch->refresh();
        $this->assertSame('committed', $batch->status);
        $this->assertNotNull($batch->committed_at);
    }
}
