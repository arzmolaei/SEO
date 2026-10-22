<?php

namespace Tests\Feature\Keywords;

use App\Domain\Keywords\KeywordIntent;
use App\Domain\Review\ReviewItemType;
use App\Jobs\RefreshProjectClusterReviews;
use App\Jobs\RefreshProjectMappingConflicts;
use App\Models\Keyword;
use App\Models\ReviewItem;
use App\Models\User;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Pages\CreatePlannedPage;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KeywordIntelligenceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cluster_suggestion_refresh_is_queue_driven_and_idempotent(): void
    {
        [$actor, $project] = $this->fixture();
        $this->keyword($project->id, 'کفش ورزشی');
        $this->keyword($project->id, 'کفش ورزشی زنانه');

        RefreshProjectClusterReviews::dispatchSync($actor->id, $project->id);
        RefreshProjectClusterReviews::dispatchSync($actor->id, $project->id);

        $this->assertDatabaseCount('review_items', 1);
        $review = ReviewItem::query()->firstOrFail();
        $this->assertSame(ReviewItemType::Cluster, $review->type);
        $this->assertSame('pending', $review->status);
    }

    public function test_refresh_endpoints_dispatch_bounded_background_work(): void
    {
        [$actor, $project] = $this->fixture();
        Queue::fake();

        $this->actingAs($actor)->post("/projects/{$project->id}/keyword-intelligence/cluster-suggestions/refresh")
            ->assertRedirect("/projects/{$project->id}/keywords");
        $this->actingAs($actor)->post("/projects/{$project->id}/keyword-intelligence/mapping-conflicts/refresh")
            ->assertRedirect("/projects/{$project->id}/keywords");

        Queue::assertPushed(RefreshProjectClusterReviews::class, fn (RefreshProjectClusterReviews $job): bool => $job->actorId === $actor->id && $job->projectId === $project->id);
        Queue::assertPushed(RefreshProjectMappingConflicts::class, fn (RefreshProjectMappingConflicts $job): bool => $job->actorId === $actor->id && $job->projectId === $project->id);
    }

    public function test_page_search_is_project_scoped_and_bounded(): void
    {
        [$actor, $project] = $this->fixture();
        [$foreignActor, $foreignProject] = $this->fixture();
        $own = app(CreatePlannedPage::class)->handle($actor, $project, 'راهنمای کفش ورزشی');
        app(CreatePlannedPage::class)->handle($foreignActor, $foreignProject, 'راهنمای کفش خارجی');

        $this->actingAs($actor)
            ->getJson("/projects/{$project->id}/keyword-intelligence/pages/search?q=کفش")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.title', 'راهنمای کفش ورزشی');
    }

    public function test_lock_endpoint_uses_existing_safe_cluster_mutation_service(): void
    {
        [$actor, $project] = $this->fixture();
        $keyword = $this->keyword($project->id, 'کفش ورزشی');
        $cluster = app(CreateKeywordCluster::class)->handle($actor, $project, 'کفش ورزشی', $keyword, [$keyword->id], KeywordIntent::Commercial);

        $this->actingAs($actor)->patch("/projects/{$project->id}/keyword-intelligence/clusters/{$cluster->id}/lock", [
            'locked' => true,
        ])->assertRedirect("/projects/{$project->id}/keywords");

        $this->assertTrue($cluster->fresh()->is_locked);
        $this->assertDatabaseHas('activity_events', [
            'project_id' => $project->id,
            'event' => 'keywords.clusters.lock',
            'actor_id' => $actor->id,
        ]);
    }

    private function fixture(): array
    {
        $actor = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($actor, 'Studio');

        return [$actor, app(CreateProject::class)->handle($actor, $workspace, 'example.com')];
    }

    private function keyword(int $projectId, string $text): Keyword
    {
        return Keyword::query()->create([
            'project_id' => $projectId,
            'display_text' => $text,
            'normalized_text' => $text,
            'status' => 'review',
        ]);
    }
}
