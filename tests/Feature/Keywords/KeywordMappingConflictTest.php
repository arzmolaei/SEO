<?php

namespace Tests\Feature\Keywords;

use App\Domain\Keywords\ClusterTargetType;
use App\Domain\Keywords\KeywordIntent;
use App\Models\ImportBatch;
use App\Models\Keyword;
use App\Models\KeywordMetric;
use App\Models\KeywordSource;
use App\Models\ReviewItem;
use App\Models\StagedKeywordRow;
use App\Models\User;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Keywords\DetectKeywordMappingConflicts;
use App\Services\Keywords\MapClusterToTarget;
use App\Services\Pages\CreatePlannedPage;
use App\Services\Projects\CreateProject;
use App\Services\Review\ResolveMappingConflictReview;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class KeywordMappingConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_unrelated_clusters_on_one_page_warn_without_changing_targets(): void
    {
        [$actor, $project] = $this->fixture();
        $a = $this->cluster($actor, $project, 'کفش ورزشی');
        $b = $this->cluster($actor, $project, 'لپ تاپ');
        $page = app(CreatePlannedPage::class)->handle($actor, $project, 'Shared');
        foreach ([$a, $b] as $cluster) {
            app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::PlannedPage, $page);
        }
        app(DetectKeywordMappingConflicts::class)->handle($actor, $project);
        $this->assertDatabaseCount('review_items', 2);
        $this->assertStringContainsString('overloaded_page', json_encode(ReviewItem::firstOrFail()->suggestion));
        $this->assertDatabaseCount('cluster_targets', 2);
        $this->assertDatabaseHas('cluster_targets', ['keyword_cluster_id' => $a->id, 'page_id' => $page->id]);
    }

    public function test_same_intent_requires_shared_topic_before_competing_page_warning(): void
    {
        [$actor, $project] = $this->fixture();
        foreach (['کفش ورزشی زنانه', 'کفش ورزشی مردانه', 'لپ تاپ'] as $i => $text) {
            $cluster = $this->cluster($actor, $project, $text);
            $page = app(CreatePlannedPage::class)->handle($actor, $project, 'Page '.$i);
            app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::PlannedPage, $page);
        }
        app(DetectKeywordMappingConflicts::class)->handle($actor, $project);
        $this->assertDatabaseCount('review_items', 2);
        $this->assertStringContainsString('competing_pages', json_encode(ReviewItem::firstOrFail()->suggestion));
    }

    public function test_unmapped_warning_requires_real_current_demand_and_preserves_source_evidence(): void
    {
        [$actor, $project] = $this->fixture();
        $cluster = $this->cluster($actor, $project, 'کفش ورزشی');
        $this->cluster($actor, $project, 'لپ تاپ');

        $batch = ImportBatch::query()->create([
            'project_id' => $project->id,
            'source' => 'manual_csv',
            'original_filename' => 'mapping-conflicts.csv',
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
            'keyword_id' => $cluster->primary_keyword_id,
            'import_batch_id' => $batch->id,
            'staged_keyword_row_id' => $row->id,
            'source' => 'manual_csv',
            'original_text' => 'کفش ورزشی',
            'raw_payload' => [],
        ]);
        $metric = KeywordMetric::query()->create([
            'keyword_id' => $cluster->primary_keyword_id,
            'keyword_source_id' => $source->id,
            'metric' => 'volume',
            'value' => 2500,
            'observed_at' => now(),
        ]);

        app(DetectKeywordMappingConflicts::class)->handle($actor, $project);
        $this->assertDatabaseCount('review_items', 1);
        $this->assertStringContainsString('high_demand_unmapped', json_encode(ReviewItem::firstOrFail()->suggestion));
        $this->assertStringContainsString((string) $metric->id, json_encode(ReviewItem::firstOrFail()->suggestion));

        KeywordMetric::query()->create([
            'keyword_id' => $cluster->primary_keyword_id,
            'keyword_source_id' => $source->id,
            'metric' => 'volume',
            'value' => 10,
            'observed_at' => now()->addDay(),
        ]);
        app(DetectKeywordMappingConflicts::class)->handle($actor, $project);
        $this->assertSame('superseded', ReviewItem::firstOrFail()->resolution);
    }

    public function test_resolved_evidence_stays_closed_and_changed_group_reopens(): void
    {
        [$actor, $project] = $this->fixture();
        $page = app(CreatePlannedPage::class)->handle($actor, $project, 'Shared');
        foreach (['کفش ورزشی', 'لپ تاپ'] as $text) {
            app(MapClusterToTarget::class)->handle($actor, $project, $this->cluster($actor, $project, $text), ClusterTargetType::PlannedPage, $page);
        }
        $detector = app(DetectKeywordMappingConflicts::class);
        $detector->handle($actor, $project);
        $review = ReviewItem::firstOrFail();
        app(ResolveMappingConflictReview::class)->handle($actor, $project, $review, $review->fingerprint, 'accept');
        $detector->handle($actor, $project);
        $this->assertSame('resolved', $review->fresh()->status);
        app(MapClusterToTarget::class)->handle($actor, $project, $this->cluster($actor, $project, 'میز اداری'), ClusterTargetType::PlannedPage, $page);
        $detector->handle($actor, $project);
        $this->assertSame('pending', $review->fresh()->status);
        $this->assertDatabaseCount('review_items', 3);
        $this->assertDatabaseHas('activity_events', ['event' => 'review.mapping_conflict.resolved', 'actor_id' => $actor->id]);
    }

    public function test_changed_mapping_rejects_stale_acknowledgement(): void
    {
        [$actor, $project] = $this->fixture();
        $page = app(CreatePlannedPage::class)->handle($actor, $project, 'Shared');
        $a = $this->cluster($actor, $project, 'کفش ورزشی');
        $b = $this->cluster($actor, $project, 'لپ تاپ');
        foreach ([$a, $b] as $cluster) {
            app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::PlannedPage, $page);
        }
        app(DetectKeywordMappingConflicts::class)->handle($actor, $project);
        $review = ReviewItem::firstOrFail();
        app(MapClusterToTarget::class)->handle($actor, $project, $b, ClusterTargetType::Ignore);
        $this->expectException(ConflictHttpException::class);
        app(ResolveMappingConflictReview::class)->handle($actor, $project, $review, $review->fingerprint, 'accept');
    }

    public function test_detector_rejects_foreign_project(): void
    {
        [$actor] = $this->fixture();
        [, $foreign] = $this->fixture();
        $this->expectException(AuthorizationException::class);
        app(DetectKeywordMappingConflicts::class)->handle($actor, $foreign);
    }

    private function fixture(): array
    {
        $actor = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($actor, 'Studio');

        return [$actor, app(CreateProject::class)->handle($actor, $workspace, 'example.com')];
    }

    private function cluster($actor, $project, string $text)
    {
        $keyword = Keyword::query()->create(['project_id' => $project->id, 'display_text' => $text, 'normalized_text' => $text, 'status' => 'review']);

        return app(CreateKeywordCluster::class)->handle($actor, $project, $text, $keyword, [$keyword->id], KeywordIntent::Commercial);
    }
}
