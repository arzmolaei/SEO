<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Keywords\ClusterLifecycleState;
use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\ClusterTargetType;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\Page;
use App\Models\User;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KeywordIntelligenceAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_build_a_reviewed_mapped_ready_cluster_through_connected_http_workflow(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'SEO Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'example.com');
        $primary = $this->keyword($project->id, 'کفش ورزشی');
        $secondary = $this->keyword($project->id, 'کفش ورزشی زنانه');

        $this->actingAs($owner)->post("/projects/{$project->id}/keyword-intelligence/clusters", [
            'name' => 'کفش ورزشی',
            'primary_keyword_id' => $primary->id,
            'member_ids' => [$primary->id, $secondary->id],
            'intent' => 'commercial',
        ])->assertRedirect("/projects/{$project->id}/keywords");

        $cluster = KeywordCluster::query()->where('project_id', $project->id)->firstOrFail();

        $this->actingAs($owner)->patch("/projects/{$project->id}/keyword-intelligence/clusters/{$cluster->id}", [
            'review_state' => ClusterReviewState::Reviewed->value,
            'business_relevance' => 4,
        ])->assertRedirect("/projects/{$project->id}/keywords");

        $this->actingAs($owner)->post("/projects/{$project->id}/keyword-intelligence/pages", [
            'title' => 'راهنمای کفش ورزشی',
        ])->assertRedirect("/projects/{$project->id}/keywords");

        $page = Page::query()->where('project_id', $project->id)->firstOrFail();

        $this->actingAs($owner)->put("/projects/{$project->id}/keyword-intelligence/clusters/{$cluster->id}/target", [
            'type' => ClusterTargetType::PlannedPage->value,
            'page_id' => $page->id,
        ])->assertRedirect("/projects/{$project->id}/keywords");

        $this->actingAs($owner)->post("/projects/{$project->id}/keyword-intelligence/clusters/{$cluster->id}/ready")
            ->assertRedirect("/projects/{$project->id}/keywords");

        $this->assertSame(ClusterLifecycleState::Ready, $cluster->fresh()->lifecycle_state);

        $this->actingAs($owner)
            ->get("/projects/{$project->id}/keywords")
            ->assertOk()
            ->assertInertia(fn (Assert $screen) => $screen
                ->component('projects/keywords')
                ->where('intelligence.summary.active_clusters', 1)
                ->where('intelligence.summary.ready_clusters', 1)
                ->where('intelligence.summary.unmapped_clusters', 0)
                ->where('intelligence.clusters.data.0.id', $cluster->id)
                ->where('intelligence.clusters.data.0.primary_keyword.id', $primary->id)
                ->where('intelligence.clusters.data.0.review_state', ClusterReviewState::Reviewed->value)
                ->where('intelligence.clusters.data.0.business_relevance', 4)
                ->where('intelligence.clusters.data.0.lifecycle_state', ClusterLifecycleState::Ready->value)
                ->where('intelligence.clusters.data.0.target.type', ClusterTargetType::PlannedPage->value)
                ->where('intelligence.clusters.data.0.target.page.id', $page->id)
                ->where('intelligence.clusters.data.0.priority.components.mapping.value', true)
                ->where('intelligence.clusters.data.0.priority.components.review.value', true)
            );
    }

    public function test_connected_mutation_endpoints_reject_cross_project_identifiers(): void
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'SEO Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'example.com');
        $foreignProject = app(CreateProject::class)->handle($owner, $workspace, 'other.example');
        $foreignKeyword = $this->keyword($foreignProject->id, 'لپ تاپ');

        $this->actingAs($owner)->post("/projects/{$project->id}/keyword-intelligence/clusters", [
            'name' => 'Invalid foreign cluster',
            'primary_keyword_id' => $foreignKeyword->id,
            'member_ids' => [$foreignKeyword->id],
            'intent' => 'commercial',
        ])->assertForbidden();

        $this->assertDatabaseMissing('keyword_clusters', [
            'project_id' => $project->id,
            'name' => 'Invalid foreign cluster',
        ]);
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
