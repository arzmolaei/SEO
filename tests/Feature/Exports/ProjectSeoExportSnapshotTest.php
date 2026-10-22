<?php

namespace Tests\Feature\Exports;

use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\ClusterTargetType;
use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Domain\Keywords\ClusterPriorityScore;
use App\Models\Keyword;
use App\Models\KeywordIntentAssignment;
use App\Models\KeywordMetric;
use App\Models\Project;
use App\Models\User;
use App\Services\Exports\ProjectSeoExportException;
use App\Services\Exports\ProjectSeoExportSchema;
use App\Services\Exports\ProjectSeoExportSnapshot;
use App\Services\Keywords\CommitKeywordImportBatch;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Keywords\MapClusterToTarget;
use App\Services\Keywords\StageKeywordImport;
use App\Services\Pages\CreatePlannedPage;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectSeoExportSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_is_project_scoped_ordered_and_uses_exact_schema_keys(): void
    {
        [$owner, $project] = $this->project('پروژه الف');
        [, $foreignProject] = $this->project('پروژه ب');
        $this->import($owner, $project, 'kwfinder', [
            ['Keyword' => 'کلمه دوم', 'Volume' => '200'],
            ['Keyword' => 'کلمه اول', 'Volume' => '100'],
        ]);
        $foreignOwner = $foreignProject->workspace->memberships()->firstOrFail()->user;
        $this->import($foreignOwner, $foreignProject, 'foreign', [
            ['Keyword' => 'نباید دیده شود', 'Volume' => '9999'],
        ]);

        $result = app(ProjectSeoExportSnapshot::class)->loadBatch($project, null, 50);

        $this->assertCount(2, $result['rows']);
        $this->assertSame(
            Keyword::query()->where('project_id', $project->id)->orderBy('id')->pluck('id')->all(),
            array_column($result['rows'], 'keyword_id'),
        );
        foreach ($result['rows'] as $row) {
            $this->assertSame(ProjectSeoExportSchema::HEADERS, array_keys($row));
            $this->assertSame($project->id, $row['project_id']);
            $this->assertStringNotContainsString('نباید دیده شود', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        $this->assertNull($result['next_after_id']);
    }

    public function test_snapshot_uses_latest_metric_tie_break_and_exactly_one_current_intent(): void
    {
        [$owner, $project] = $this->project('فروشگاه');
        $this->import($owner, $project, 'first', [['Keyword' => 'خرید لپ تاپ', 'Volume' => '100']]);
        $this->import($owner, $project, 'second', [['Keyword' => 'خرید لپ تاپ', 'Volume' => '250']]);

        $keyword = Keyword::query()->where('project_id', $project->id)->firstOrFail();
        $metrics = KeywordMetric::query()->where('keyword_id', $keyword->id)->where('metric', 'volume')->orderBy('id')->get();
        $this->assertCount(2, $metrics);
        $tie = now()->subHour()->startOfSecond();
        foreach ($metrics as $metric) {
            $metric->update(['observed_at' => $tie]);
        }
        $latest = $metrics->last();

        KeywordMetric::query()->create([
            'keyword_id' => $keyword->id,
            'keyword_source_id' => $latest->keyword_source_id,
            'metric' => 'difficulty',
            'value' => '37.5000',
            'observed_at' => $tie,
        ]);
        $intent = KeywordIntentAssignment::query()->create([
            'project_id' => $project->id,
            'keyword_id' => $keyword->id,
            'actor_id' => $owner->id,
            'intent' => KeywordIntent::Transactional,
            'confidence' => IntentConfidence::High,
            'source' => 'manual',
            'evidence' => ['reason' => 'test'],
            'is_current' => true,
        ]);

        $row = app(ProjectSeoExportSnapshot::class)->loadBatch($project, null, 10)['rows'][0];

        $this->assertSame($latest->id, $row['volume_metric_id']);
        $this->assertSame('250.0000', $row['volume_value']);
        $this->assertSame('second', $row['volume_source']);
        $this->assertSame('37.5000', $row['difficulty_value']);
        $this->assertSame($intent->id, $row['intent_assignment_id']);
        $this->assertSame('transactional', $row['intent']);
        $this->assertSame('high', $row['intent_confidence']);
        $this->assertSame('manual', $row['intent_source']);
    }

    public function test_snapshot_rejects_multiple_current_intents_instead_of_guessing(): void
    {
        [$owner, $project] = $this->project('تعارض');
        $this->import($owner, $project, 'source', [['Keyword' => 'سئو', 'Volume' => '1000']]);
        $keyword = Keyword::query()->where('project_id', $project->id)->firstOrFail();

        foreach ([KeywordIntent::Informational, KeywordIntent::Commercial] as $intent) {
            KeywordIntentAssignment::query()->create([
                'project_id' => $project->id,
                'keyword_id' => $keyword->id,
                'actor_id' => $owner->id,
                'intent' => $intent,
                'confidence' => IntentConfidence::Medium,
                'source' => 'test',
                'evidence' => [],
                'is_current' => true,
            ]);
        }

        try {
            app(ProjectSeoExportSnapshot::class)->loadBatch($project, null, 10);
            $this->fail('Expected current-intent integrity failure.');
        } catch (ProjectSeoExportException $exception) {
            $this->assertSame('export_integrity_current_intent', $exception->codeName());
            $this->assertSame('خروجی به‌دلیل ناسازگاری داده‌های نیت قابل تولید نیست.', $exception->userMessage());
        }
    }

    public function test_secondary_member_priority_uses_primary_evidence_and_archived_clusters_have_blank_priority(): void
    {
        [$owner, $project] = $this->project('آژانس');
        $this->import($owner, $project, 'primary-source', [
            ['Keyword' => 'سئو سایت', 'Volume' => '10000'],
            ['Keyword' => 'خدمات سئو', 'Volume' => '50'],
            ['Keyword' => 'سئو قدیمی', 'Volume' => '500'],
        ]);
        $primary = Keyword::query()->where('project_id', $project->id)->where('display_text', 'سئو سایت')->firstOrFail();
        $secondary = Keyword::query()->where('project_id', $project->id)->where('display_text', 'خدمات سئو')->firstOrFail();
        $archivedKeyword = Keyword::query()->where('project_id', $project->id)->where('display_text', 'سئو قدیمی')->firstOrFail();

        $primarySource = $primary->sources()->firstOrFail();
        KeywordMetric::query()->create([
            'keyword_id' => $primary->id,
            'keyword_source_id' => $primarySource->id,
            'metric' => 'difficulty',
            'value' => '20.0000',
            'observed_at' => now(),
        ]);

        $cluster = app(CreateKeywordCluster::class)->handle(
            $owner,
            $project,
            'خوشه سئو',
            $primary,
            [$primary->id, $secondary->id],
            KeywordIntent::Commercial,
            IntentConfidence::High,
            false,
            'user',
            null,
            ClusterReviewState::Reviewed,
        );
        $cluster->update(['business_relevance' => 5]);
        $page = app(CreatePlannedPage::class)->handle($owner, $project, 'صفحه خدمات سئو');
        $target = app(MapClusterToTarget::class)->handle($owner, $project, $cluster, ClusterTargetType::PlannedPage, $page);

        $archived = app(CreateKeywordCluster::class)->handle(
            $owner,
            $project,
            'آرشیو',
            $archivedKeyword,
            [$archivedKeyword->id],
            KeywordIntent::Informational,
        );
        $archived->update(['archived_at' => now()]);

        $rows = collect(app(ProjectSeoExportSnapshot::class)->loadBatch($project, null, 10)['rows'])->keyBy('keyword_id');
        $secondaryRow = $rows->get($secondary->id);
        $this->assertIsArray($secondaryRow);

        $expected = ClusterPriorityScore::calculate(
            10000.0,
            20.0,
            5,
            true,
            true,
            volumeEvidence: ['metric_id' => $secondaryRow['priority_volume_metric_id']],
            difficultyEvidence: ['metric_id' => $secondaryRow['priority_difficulty_metric_id']],
        );

        $this->assertSame('50.0000', $secondaryRow['volume_value']);
        $this->assertSame($primary->id, $secondaryRow['priority_basis_keyword_id']);
        $this->assertSame('10000.0000', $secondaryRow['priority_volume_value']);
        $this->assertSame('20.0000', $secondaryRow['priority_difficulty_value']);
        $this->assertSame($target->id, $secondaryRow['target_id']);
        $this->assertSame($page->id, $secondaryRow['page_id']);
        $this->assertSame($expected->score, $secondaryRow['priority_score']);
        $this->assertSame($expected->dataCompleteness, $secondaryRow['priority_data_completeness']);
        $this->assertSame($expected->components['search_demand']['points'], $secondaryRow['priority_search_demand_points']);
        $this->assertSame($expected->components['difficulty']['points'], $secondaryRow['priority_difficulty_points']);
        $this->assertSame($expected->components['business_relevance']['points'], $secondaryRow['priority_business_relevance_points']);
        $this->assertSame($expected->components['mapping']['points'], $secondaryRow['priority_mapping_points']);
        $this->assertSame($expected->components['review']['points'], $secondaryRow['priority_review_points']);

        $archivedRow = $rows->get($archivedKeyword->id);
        $this->assertIsArray($archivedRow);
        $this->assertNotNull($archivedRow['cluster_archived_at_utc']);
        foreach (array_slice(ProjectSeoExportSchema::HEADERS, 49, 24) as $priorityKey) {
            $this->assertNull($archivedRow[$priorityKey], $priorityKey.' should be blank for archived cluster');
        }
    }

    /** @return array{User, Project} */
    private function project(string $name): array
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, $name.' workspace');
        $project = app(CreateProject::class)->handle($owner, $workspace, $name);
        $project->setRelation('workspace', $workspace->load('memberships.user'));

        return [$owner, $project];
    }

    /** @param list<array{Keyword:string,Volume:string}> $rows */
    private function import(User $owner, Project $project, string $source, array $rows): void
    {
        $batch = app(StageKeywordImport::class)->handle(
            actor: $owner,
            project: $project,
            source: $source,
            originalFilename: $source.'.csv',
            mapping: ['keyword' => 'Keyword', 'volume' => 'Volume'],
            rows: $rows,
        );
        app(CommitKeywordImportBatch::class)->handle($owner, $batch);
    }
}
