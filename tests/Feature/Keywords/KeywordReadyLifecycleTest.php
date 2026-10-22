<?php

namespace Tests\Feature\Keywords;

use App\Domain\Keywords\ClusterLifecycleState;
use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\ClusterTargetType;
use App\Domain\Keywords\KeywordIntent;
use App\Domain\Review\ReviewItemType;
use App\Models\ImportBatch;
use App\Models\Keyword;
use App\Models\KeywordMetric;
use App\Models\KeywordSource;
use App\Models\ReviewItem;
use App\Models\StagedKeywordRow;
use App\Models\User;
use App\Services\Keywords\CalculateClusterPriority;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Keywords\DetectKeywordMappingConflicts;
use App\Services\Keywords\MapClusterToTarget;
use App\Services\Pages\CreatePlannedPage;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordReadyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_priority_service_uses_latest_local_metrics_and_cluster_state(): void
    {
        [$actor, $project, $cluster, $keyword] = $this->fixture();
        $this->addMetric($project->id, $keyword->id, 'volume', 1000, now());
        $this->addMetric($project->id, $keyword->id, 'difficulty', 40, now());
        $page = app(CreatePlannedPage::class)->handle($actor, $project, 'Target');
        app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::PlannedPage, $page);
        $cluster->update(['review_state' => ClusterReviewState::Reviewed, 'business_relevance' => 4]);

        $score = app(CalculateClusterPriority::class)->handle($actor, $project, $cluster);

        $this->assertSame(74, $score->score);
        $this->assertSame(1000.0, $score->components['search_demand']['value']);
        $this->assertSame(40.0, $score->components['difficulty']['value']);
        $this->assertSame(4, $score->components['business_relevance']['value']);
        $this->assertTrue($score->components['mapping']['value']);
        $this->assertTrue($score->components['review']['value']);
    }

    public function test_latest_metric_wins_and_missing_metrics_remain_missing(): void
    {
        [$actor, $project, $cluster, $keyword] = $this->fixture();
        $this->addMetric($project->id, $keyword->id, 'volume', 5000, now()->subDay());
        $this->addMetric($project->id, $keyword->id, 'volume', 100, now());

        $score = app(CalculateClusterPriority::class)->handle($actor, $project, $cluster);

        $this->assertSame(100.0, $score->components['search_demand']['value']);
        $this->assertNull($score->components['difficulty']['value']);
        $this->assertNull($score->components['business_relevance']['value']);
    }

    public function test_cluster_cannot_become_ready_while_its_own_review_is_pending(): void
    {
        [, , $cluster] = $this->fixture();

        $this->expectException(DomainException::class);
        $cluster->lifecycle_state = ClusterLifecycleState::Ready;
        $cluster->save();
    }

    public function test_cluster_cannot_become_ready_without_a_mapping(): void
    {
        [, , $cluster] = $this->fixture();
        $cluster->review_state = ClusterReviewState::Reviewed;
        $cluster->save();

        $this->expectException(DomainException::class);
        $cluster->lifecycle_state = ClusterLifecycleState::Ready;
        $cluster->save();
    }

    public function test_pending_required_review_blocks_ready_even_when_mapping_exists(): void
    {
        [$actor, $project, $cluster, $keyword] = $this->fixture();
        $cluster->update(['review_state' => ClusterReviewState::Reviewed]);
        app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::Ignore);
        ReviewItem::query()->create([
            'project_id' => $project->id,
            'keyword_id' => $keyword->id,
            'type' => ReviewItemType::MappingConflict,
            'status' => 'pending',
            'fingerprint' => hash('sha256', 'pending-ready-guard'),
            'suggestion' => ['evidence' => ['pending mapping review']],
        ]);

        $this->expectException(DomainException::class);
        $cluster->lifecycle_state = ClusterLifecycleState::Ready;
        $cluster->save();
    }

    public function test_reviewed_cluster_with_explicit_ignore_can_become_ready(): void
    {
        [$actor, $project, $cluster] = $this->fixture();
        $cluster->update(['review_state' => ClusterReviewState::Reviewed]);
        app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::Ignore);

        $cluster->lifecycle_state = ClusterLifecycleState::Ready;
        $cluster->save();

        $this->assertSame(ClusterLifecycleState::Ready, $cluster->fresh()->lifecycle_state);
    }

    public function test_reviewed_cluster_with_page_mapping_can_become_ready(): void
    {
        [$actor, $project, $cluster] = $this->fixture();
        $cluster->update(['review_state' => ClusterReviewState::Reviewed]);
        $page = app(CreatePlannedPage::class)->handle($actor, $project, 'Target');
        app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::PlannedPage, $page);

        $cluster->lifecycle_state = ClusterLifecycleState::Ready;
        $cluster->save();

        $this->assertSame(ClusterLifecycleState::Ready, $cluster->fresh()->lifecycle_state);
    }

    public function test_remapping_a_ready_cluster_returns_it_to_planning(): void
    {
        [$actor, $project, $cluster] = $this->fixture();
        $cluster->update(['review_state' => ClusterReviewState::Reviewed]);
        app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::Ignore);
        $cluster->update(['lifecycle_state' => ClusterLifecycleState::Ready]);
        $page = app(CreatePlannedPage::class)->handle($actor, $project, 'New target');

        app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::PlannedPage, $page);

        $this->assertSame(ClusterLifecycleState::Planning, $cluster->fresh()->lifecycle_state);
    }

    public function test_new_mapping_conflict_invalidates_an_existing_ready_decision(): void
    {
        [$actor, $project, $cluster] = $this->fixture();
        $cluster->update(['review_state' => ClusterReviewState::Reviewed]);
        $page = app(CreatePlannedPage::class)->handle($actor, $project, 'Shared target');
        app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::PlannedPage, $page);
        $cluster->update(['lifecycle_state' => ClusterLifecycleState::Ready]);

        $otherKeyword = Keyword::query()->create([
            'project_id' => $project->id,
            'display_text' => 'لپ تاپ',
            'normalized_text' => 'لپ تاپ',
            'status' => 'review',
        ]);
        $other = app(CreateKeywordCluster::class)->handle(
            $actor,
            $project,
            'لپ تاپ',
            $otherKeyword,
            [$otherKeyword->id],
            KeywordIntent::Commercial,
        );
        app(MapClusterToTarget::class)->handle($actor, $project, $other, ClusterTargetType::PlannedPage, $page);

        app(DetectKeywordMappingConflicts::class)->handle($actor, $project);

        $this->assertSame(ClusterLifecycleState::Planning, $cluster->fresh()->lifecycle_state);
        $this->assertDatabaseHas('review_items', [
            'project_id' => $project->id,
            'keyword_id' => $cluster->primary_keyword_id,
            'type' => ReviewItemType::MappingConflict->value,
            'status' => 'pending',
        ]);
    }

    public function test_business_relevance_accepts_only_manual_one_to_five_scale(): void
    {
        [, , $cluster] = $this->fixture();

        $cluster->business_relevance = 6;
        $this->expectException(DomainException::class);
        $cluster->save();
    }

    private function fixture(): array
    {
        $actor = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($actor, 'Studio');
        $project = app(CreateProject::class)->handle($actor, $workspace, 'example.com');
        $keyword = Keyword::query()->create([
            'project_id' => $project->id,
            'display_text' => 'کفش ورزشی',
            'normalized_text' => 'کفش ورزشی',
            'status' => 'review',
        ]);
        $cluster = app(CreateKeywordCluster::class)->handle(
            $actor,
            $project,
            'کفش ورزشی',
            $keyword,
            [$keyword->id],
            KeywordIntent::Commercial,
        );

        return [$actor, $project, $cluster, $keyword];
    }

    private function addMetric(int $projectId, int $keywordId, string $metric, float $value, $observedAt): void
    {
        $batch = ImportBatch::query()->create([
            'project_id' => $projectId,
            'source' => 'manual_csv',
            'original_filename' => uniqid($metric.'-', true).'.csv',
            'mapping' => ['keyword' => 'keyword'],
            'status' => 'committed',
        ]);
        $row = StagedKeywordRow::query()->create([
            'import_batch_id' => $batch->id,
            'row_number' => 1,
            'keyword_original' => 'کفش ورزشی',
            'raw_payload' => ['keyword' => 'کفش ورزشی'],
            'mapped_payload' => ['keyword' => 'کفش ورزشی'],
            'is_valid' => true,
        ]);
        $source = KeywordSource::query()->create([
            'keyword_id' => $keywordId,
            'import_batch_id' => $batch->id,
            'staged_keyword_row_id' => $row->id,
            'source' => 'manual_csv',
            'original_text' => 'کفش ورزشی',
            'raw_payload' => [],
        ]);
        KeywordMetric::query()->create([
            'keyword_id' => $keywordId,
            'keyword_source_id' => $source->id,
            'metric' => $metric,
            'value' => $value,
            'observed_at' => $observedAt,
        ]);
    }
}
