<?php

namespace Tests\Feature\Keywords;

use App\Models\User;
use App\Services\Keywords\CommitKeywordImportBatch;
use App\Services\Keywords\StageKeywordImport;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'status' => 'review',
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

    public function test_reimport_from_second_source_preserves_independent_volume_metric_history(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');
        $stager = app(StageKeywordImport::class);
        $committer = app(CommitKeywordImportBatch::class);

        $kwfinder = $stager->handle(
            actor: $owner,
            project: $project,
            source: 'kwfinder',
            originalFilename: 'kwfinder.csv',
            mapping: ['keyword' => 'Keyword', 'volume' => 'Search Volume'],
            rows: [['Keyword' => 'سئو سایت', 'Search Volume' => '1200']],
        );
        $committer->handle($owner, $kwfinder);

        $semrush = $stager->handle(
            actor: $owner,
            project: $project,
            source: 'semrush',
            originalFilename: 'semrush.csv',
            mapping: ['keyword' => 'Keyword', 'volume' => 'Volume'],
            rows: [['Keyword' => 'سئو سايت', 'Volume' => '900']],
        );
        $committer->handle($owner, $semrush);

        $this->assertDatabaseCount('keywords', 1);
        $this->assertDatabaseCount('keyword_sources', 2);
        $this->assertDatabaseCount('keyword_metrics', 2);

        $kwfinderSourceId = DB::table('keyword_sources')->where('source', 'kwfinder')->value('id');
        $semrushSourceId = DB::table('keyword_sources')->where('source', 'semrush')->value('id');

        $this->assertNotNull($kwfinderSourceId);
        $this->assertNotNull($semrushSourceId);
        $this->assertDatabaseHas('keyword_metrics', [
            'keyword_source_id' => $kwfinderSourceId,
            'metric' => 'volume',
            'value' => 1200,
        ]);
        $this->assertDatabaseHas('keyword_metrics', [
            'keyword_source_id' => $semrushSourceId,
            'metric' => 'volume',
            'value' => 900,
        ]);
    }

    public function test_retrying_a_committed_batch_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'Example.com');

        $batch = app(StageKeywordImport::class)->handle(
            actor: $owner,
            project: $project,
            source: 'kwfinder',
            originalFilename: 'kwfinder.csv',
            mapping: ['keyword' => 'Keyword', 'volume' => 'Search Volume'],
            rows: [['Keyword' => 'سئو سایت', 'Search Volume' => '1200']],
        );

        $committer = app(CommitKeywordImportBatch::class);
        $committer->handle($owner, $batch);
        $committer->handle($owner, $batch);

        $this->assertDatabaseCount('keywords', 1);
        $this->assertDatabaseCount('keyword_sources', 1);
        $this->assertDatabaseCount('keyword_metrics', 1);
    }

    public function test_normalized_keyword_identity_never_merges_across_projects(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $firstProject = app(CreateProject::class)->handle($owner, $workspace, 'First');
        $secondProject = app(CreateProject::class)->handle($owner, $workspace, 'Second');
        $stager = app(StageKeywordImport::class);
        $committer = app(CommitKeywordImportBatch::class);

        foreach ([$firstProject, $secondProject] as $project) {
            $batch = $stager->handle(
                actor: $owner,
                project: $project,
                source: 'kwfinder',
                originalFilename: 'keywords.csv',
                mapping: ['keyword' => 'Keyword', 'volume' => 'Search Volume'],
                rows: [['Keyword' => 'سئو سايت', 'Search Volume' => '100']],
            );
            $committer->handle($owner, $batch);
        }

        $this->assertDatabaseCount('keywords', 2);
        $this->assertDatabaseHas('keywords', [
            'project_id' => $firstProject->id,
            'normalized_text' => 'سئو سایت',
        ]);
        $this->assertDatabaseHas('keywords', [
            'project_id' => $secondProject->id,
            'normalized_text' => 'سئو سایت',
        ]);
    }
}
