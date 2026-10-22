<?php

namespace Tests\Feature\Acceptance;

use App\Models\ImportBatch;
use App\Models\Keyword;
use App\Models\User;
use App\Services\Keywords\CommitKeywordImportBatch;
use App\Services\Keywords\ProcessKeywordCsvImport;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FoundationKeywordCoreAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_complete_the_first_keyword_core_workflow_end_to_end(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'SEO Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'example.com');

        $csvPath = tempnam(sys_get_temp_dir(), 'seo-os-acceptance-');
        $this->assertNotFalse($csvPath);

        try {
            file_put_contents(
                $csvPath,
                "\xEF\xBB\xBFKeyword,Search Volume\n"
                ."سئو سایت,1200\n"
                ."سئو سايت,900\n"
                ."خدمات سئو,800\n",
            );

            $batch = ImportBatch::query()->create([
                'project_id' => $project->id,
                'source' => 'generic_csv',
                'original_filename' => 'keywords.csv',
                'mapping' => [
                    'keyword' => 'Keyword',
                    'volume' => 'Search Volume',
                ],
                'status' => 'queued',
                'total_rows' => 0,
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'warning_count' => 0,
                'error_count' => 0,
                'warnings' => [],
                'errors' => [],
            ]);

            app(ProcessKeywordCsvImport::class)->handle($batch, $csvPath, chunkSize: 2);
            $batch->refresh();

            $this->assertSame('staged', $batch->status);
            $this->assertSame(3, $batch->valid_rows);
            $this->assertSame(3, $batch->rows()->count());

            app(CommitKeywordImportBatch::class)->handle($owner, $batch);
        } finally {
            if (is_string($csvPath) && file_exists($csvPath)) {
                unlink($csvPath);
            }
        }

        $this->assertDatabaseCount('keywords', 2);
        $this->assertDatabaseCount('keyword_sources', 3);
        $this->assertDatabaseCount('keyword_metrics', 3);
        $this->assertDatabaseHas('keywords', [
            'project_id' => $project->id,
            'normalized_text' => 'سئو سایت',
            'status' => 'review',
        ]);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/keywords")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/keywords')
                ->where('keywords.total', 2)
                ->has('keywords.data', 2)
                ->where('filters.status', null)
            );

        $viewState = [
            'query' => [
                'q' => null,
                'source' => 'generic_csv',
                'status' => 'review',
                'volume_operator' => null,
                'volume' => null,
                'sort' => 'keyword',
                'direction' => 'asc',
                'per_page' => 25,
            ],
            'columns' => ['keyword', 'status', 'volume', 'source'],
        ];

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/keyword-views", [
                'name' => 'Review queue',
                'state' => $viewState,
            ])
            ->assertRedirect("/projects/{$project->id}/keywords");

        $viewId = (int) DB::table('saved_keyword_views')->value('id');

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/keywords?view={$viewId}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeView.id', $viewId)
                ->where('filters.source', 'generic_csv')
                ->where('filters.status', 'review')
                ->where('keywords.total', 2)
            );

        $keywordIds = Keyword::query()
            ->where('project_id', $project->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->actingAs($owner)
            ->patch("/projects/{$project->id}/keywords/bulk-status", [
                'keyword_ids' => $keywordIds,
                'status' => 'ready',
            ])
            ->assertRedirect("/projects/{$project->id}/keywords");

        $this->assertSame(2, Keyword::query()
            ->where('project_id', $project->id)
            ->where('status', 'ready')
            ->count());
        $this->assertDatabaseCount('change_sets', 1);
        $this->assertDatabaseCount('activity_events', 1);

        $changeSetId = (int) DB::table('change_sets')->value('id');

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/keyword-change-sets/{$changeSetId}/undo")
            ->assertRedirect("/projects/{$project->id}/keywords");

        $this->assertSame(2, Keyword::query()
            ->where('project_id', $project->id)
            ->where('status', 'review')
            ->count());
        $this->assertDatabaseHas('change_sets', [
            'id' => $changeSetId,
            'undone_by_id' => $owner->id,
        ]);
        $this->assertDatabaseCount('activity_events', 2);
    }
}
