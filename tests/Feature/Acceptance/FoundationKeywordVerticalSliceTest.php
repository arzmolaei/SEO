<?php

namespace Tests\Feature\Acceptance;

use App\Enums\KeywordStatus;
use App\Models\ImportBatch;
use App\Models\Keyword;
use App\Models\SavedKeywordView;
use App\Models\User;
use App\Services\Keywords\CommitKeywordImportBatch;
use App\Services\Keywords\ProcessKeywordCsvImport;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FoundationKeywordVerticalSliceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_complete_the_foundation_keyword_vertical_slice(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'SEO Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'example.com');

        $batch = ImportBatch::query()->create([
            'project_id' => $project->id,
            'source' => 'generic_csv',
            'original_filename' => 'acceptance-keywords.csv',
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

        $csvPath = tempnam(sys_get_temp_dir(), 'seo-os-acceptance-');
        $this->assertNotFalse($csvPath);

        file_put_contents(
            $csvPath,
            "\xEF\xBB\xBFKeyword,Search Volume\n"
            ."سئو سایت,1200\n"
            ."سئو سايت,900\n"
            ."خدمات سئو,800\n",
        );

        try {
            app(ProcessKeywordCsvImport::class)->handle($batch, $csvPath, chunkSize: 2);
        } finally {
            @unlink($csvPath);
        }

        $batch->refresh();
        $this->assertSame('staged', $batch->status);
        $this->assertSame(3, $batch->total_rows);
        $this->assertSame(3, $batch->valid_rows);

        app(CommitKeywordImportBatch::class)->handle($owner, $batch);

        $this->assertDatabaseCount('keywords', 2);
        $this->assertDatabaseCount('keyword_sources', 3);
        $this->assertDatabaseCount('keyword_metrics', 3);
        $this->assertDatabaseHas('keywords', [
            'project_id' => $project->id,
            'display_text' => 'سئو سایت',
            'normalized_text' => 'سئو سایت',
            'status' => KeywordStatus::Review->value,
        ]);

        $seoKeyword = Keyword::query()
            ->where('project_id', $project->id)
            ->where('normalized_text', 'سئو سایت')
            ->firstOrFail();

        $this->actingAs($owner)
            ->get(
                "/projects/{$project->id}/keywords?q=".urlencode('سئو')
                .'&source=generic_csv&volume_operator=gte&volume=800&sort=volume&direction=desc&per_page=25',
            )
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/keywords')
                ->where('keywords.total', 1)
                ->has('keywords.data', 1)
                ->where('keywords.data.0.id', $seoKeyword->id)
                ->where('keywords.data.0.normalized_text', 'سئو سایت')
                ->where('filters.source', 'generic_csv')
                ->where('filters.volume_operator', 'gte')
                ->where('filters.volume', 800)
            );

        $savedViewState = [
            'query' => [
                'q' => 'سئو',
                'source' => 'generic_csv',
                'status' => KeywordStatus::Review->value,
                'volume_operator' => 'gte',
                'volume' => 800,
                'sort' => 'volume',
                'direction' => 'desc',
                'per_page' => 25,
            ],
            'columns' => ['keyword', 'status', 'volume', 'source'],
        ];

        $this->actingAs($owner)
            ->post("/projects/{$project->id}/keyword-views", [
                'name' => 'Acceptance view',
                'state' => $savedViewState,
            ])
            ->assertRedirect("/projects/{$project->id}/keywords");

        $savedView = SavedKeywordView::query()->firstOrFail();

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/keywords?view={$savedView->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeView.id', $savedView->id)
                ->where('filters.q', 'سئو')
                ->where('filters.source', 'generic_csv')
                ->where('filters.status', KeywordStatus::Review->value)
            );

        $this->actingAs($owner)
            ->patch("/projects/{$project->id}/keywords/bulk-status", [
                'keyword_ids' => [$seoKeyword->id],
                'status' => KeywordStatus::Ready->value,
            ])
            ->assertRedirect("/projects/{$project->id}/keywords");

        $this->assertDatabaseHas('keywords', [
            'id' => $seoKeyword->id,
            'status' => KeywordStatus::Ready->value,
        ]);
        $this->assertDatabaseCount('change_sets', 1);
        $this->assertDatabaseCount('activity_events', 1);
        $this->assertSame($owner->id, DB::table('change_sets')->value('actor_id'));
        $this->assertSame(
            'keywords.bulk_status.updated',
            DB::table('activity_events')->value('event'),
        );
    }
}
