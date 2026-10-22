<?php

namespace Tests\Feature\Keywords;

use App\Domain\Keywords\KeywordIntent;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\User;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordIntelligenceManualOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_merge_clusters_through_project_scoped_http_operation(): void
    {
        [$actor, $project, $a, $b] = $this->fixture();

        $this->actingAs($actor)
            ->post("/projects/{$project->id}/keyword-intelligence/clusters/{$a->id}/merge", [
                'source_cluster_ids' => [$b->id],
            ])
            ->assertRedirect("/projects/{$project->id}/keywords");

        $this->assertSame(4, $a->fresh()->members()->count());
        $this->assertNotNull($b->fresh()->archived_at);
    }

    public function test_owner_can_move_member_and_split_cluster_through_http_operations(): void
    {
        [$actor, $project, $a, $b, $keywords] = $this->fixture();

        $this->actingAs($actor)
            ->post("/projects/{$project->id}/keyword-intelligence/clusters/{$a->id}/members/{$keywords[1]->id}/move", [
                'target_cluster_id' => $b->id,
            ])
            ->assertRedirect("/projects/{$project->id}/keywords");

        $this->assertSame(1, $a->fresh()->members()->count());
        $this->assertSame(3, $b->fresh()->members()->count());

        $this->actingAs($actor)
            ->post("/projects/{$project->id}/keyword-intelligence/clusters/{$b->id}/split", [
                'member_ids' => [$keywords[1]->id],
                'name' => 'Split keyword',
                'primary_keyword_id' => $keywords[1]->id,
            ])
            ->assertRedirect("/projects/{$project->id}/keywords");

        $this->assertDatabaseHas('keyword_clusters', [
            'project_id' => $project->id,
            'name' => 'Split keyword',
            'primary_keyword_id' => $keywords[1]->id,
        ]);
    }

    public function test_manual_operation_searches_are_project_scoped_and_bounded(): void
    {
        [$actor, $project, $a] = $this->fixture();
        [, $foreignProject, $foreign] = $this->fixture();

        $this->actingAs($actor)
            ->getJson("/projects/{$project->id}/keyword-intelligence/clusters/search?q=A")
            ->assertOk()
            ->assertJsonPath('data.0.id', $a->id)
            ->assertJsonMissing(['id' => $foreign->id]);

        $this->actingAs($actor)
            ->getJson("/projects/{$project->id}/keyword-intelligence/clusters/{$a->id}/members/search?q=لپ")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.keyword_cluster_id', $a->id);

        $this->actingAs($actor)
            ->getJson("/projects/{$foreignProject->id}/keyword-intelligence/clusters/{$a->id}/members/search")
            ->assertForbidden();
    }

    public function test_manual_operations_reject_foreign_cluster_identifiers_without_mutation(): void
    {
        [$actor, $project, $a] = $this->fixture();
        [, , $foreign] = $this->fixture();

        $this->actingAs($actor)
            ->post("/projects/{$project->id}/keyword-intelligence/clusters/{$a->id}/merge", [
                'source_cluster_ids' => [$foreign->id],
            ])
            ->assertForbidden();

        $this->assertNull($a->fresh()->archived_at);
        $this->assertSame(2, $a->members()->count());
    }

    /** @return array{User, \App\Models\Project, KeywordCluster, KeywordCluster, list<Keyword>} */
    private function fixture(): array
    {
        $actor = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($actor, 'Studio');
        $project = app(CreateProject::class)->handle($actor, $workspace, 'example.com');
        $keywords = [];
        foreach (['خرید لپ تاپ', 'قیمت لپ تاپ', 'خرید کفش', 'قیمت کفش'] as $text) {
            $keywords[] = Keyword::query()->create([
                'project_id' => $project->id,
                'display_text' => $text,
                'normalized_text' => $text,
                'status' => 'review',
            ]);
        }

        $create = app(CreateKeywordCluster::class);
        $a = $create->handle($actor, $project, 'A', $keywords[0], [$keywords[0]->id, $keywords[1]->id], KeywordIntent::Commercial);
        $b = $create->handle($actor, $project, 'B', $keywords[2], [$keywords[2]->id, $keywords[3]->id], KeywordIntent::Commercial);

        return [$actor, $project, $a, $b, $keywords];
    }
}
