<?php

namespace Tests\Feature\Exports;

use App\Models\Keyword;
use App\Models\KeywordMetric;
use App\Models\User;
use App\Services\Exports\ProjectSeoExportSnapshot;
use App\Services\Keywords\CommitKeywordImportBatch;
use App\Services\Keywords\StageKeywordImport;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectSeoExportQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_metric_validation_does_not_issue_keyword_lookup_per_metric(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Query Budget');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'query-budget.example');
        $batch = app(StageKeywordImport::class)->handle(
            actor: $owner,
            project: $project,
            source: 'query-budget',
            originalFilename: 'query-budget.csv',
            mapping: ['keyword' => 'Keyword', 'volume' => 'Volume'],
            rows: [
                ['Keyword' => 'سئو سایت', 'Volume' => '1200'],
                ['Keyword' => 'خدمات سئو', 'Volume' => '900'],
            ],
        );
        app(CommitKeywordImportBatch::class)->handle($owner, $batch);

        foreach (Keyword::query()->where('project_id', $project->id)->get() as $keyword) {
            $source = $keyword->sources()->firstOrFail();
            KeywordMetric::query()->create([
                'keyword_id' => $keyword->id,
                'keyword_source_id' => $source->id,
                'metric' => 'difficulty',
                'value' => '25.0000',
                'observed_at' => now(),
            ]);
        }

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            app(ProjectSeoExportSnapshot::class)->loadBatch($project, null, 250);
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $pointKeywordLookups = array_values(array_filter(
            $queries,
            static function (array $query): bool {
                $sql = strtolower(str_replace(['`', '"'], '', (string) $query['query']));

                return str_contains($sql, 'from keywords')
                    && str_contains($sql, 'where keywords.id = ?');
            },
        ));

        $this->assertCount(
            0,
            $pointKeywordLookups,
            'Export snapshot must validate metric keywords from the already-loaded batch map, not one query per metric.',
        );
    }
}
