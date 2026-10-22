<?php

namespace Tests\Feature\Keywords;

use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Enums\WorkspaceRole;
use App\Models\Keyword;
use App\Models\KeywordClusterMember;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class KeywordClusterDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_creation_preserves_inputs_and_records_members_primary_lock_and_audit(): void
    {
        [$owner, $project, $primary, $secondary] = $this->fixture();
        $cluster = app(CreateKeywordCluster::class)->handle($owner, $project, '  خرید لپ تاپ  ', $primary,
            [$primary->id, $secondary->id, $secondary->id], KeywordIntent::Transactional, IntentConfidence::High, true);

        $this->assertSame('خرید لپ تاپ', $cluster->name);
        $this->assertSame($project->id, $cluster->project_id);
        $this->assertSame($primary->id, $cluster->primary_keyword_id);
        $this->assertSame(KeywordIntent::Transactional, $cluster->intent);
        $this->assertSame(IntentConfidence::High, $cluster->confidence);
        $this->assertSame(ClusterReviewState::Pending, $cluster->review_state);
        $this->assertTrue($cluster->fresh()->is_locked);
        $this->assertSame(2, $cluster->members()->count());
        $this->assertSame($primary->id, $cluster->primaryKeyword->id);
        $this->assertSame('خرید لپ تاپ', $primary->fresh()->display_text);
        $this->assertSame('review', $primary->fresh()->status->value);
        $this->assertDatabaseHas('change_sets', ['project_id' => $project->id, 'actor_id' => $owner->id, 'action' => 'keywords.cluster.create']);
        $this->assertDatabaseHas('activity_events', ['project_id' => $project->id, 'actor_id' => $owner->id, 'event' => 'keywords.cluster.created']);
    }

    public function test_primary_must_be_an_explicit_member(): void
    {
        [$owner, $project, $primary, $secondary] = $this->fixture();
        try {
            app(CreateKeywordCluster::class)->handle($owner, $project, 'Cluster', $primary, [$secondary->id]);
            $this->fail('Missing primary membership accepted.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('keyword_clusters', 0);
            $this->assertDatabaseCount('keyword_cluster_members', 0);
            $this->assertDatabaseCount('activity_events', 0);
        }
    }

    public function test_missing_and_malformed_member_ids_cannot_partially_create_a_cluster(): void
    {
        [$owner, $project, $primary] = $this->fixture();
        foreach ([[$primary->id, 999999], [$primary->id, '1'], [$primary->id, 0], []] as $ids) {
            try {
                app(CreateKeywordCluster::class)->handle($owner, $project, 'Cluster', $primary, $ids);
                $this->fail('Invalid membership accepted.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('keyword_clusters', 0);
                $this->assertDatabaseCount('keyword_cluster_members', 0);
            }
        }
    }

    public function test_names_are_nonempty_and_fit_storage(): void
    {
        [$owner, $project, $primary] = $this->fixture();
        foreach (['   ', str_repeat('الف', 100)] as $name) {
            try {
                app(CreateKeywordCluster::class)->handle($owner, $project, $name, $primary, [$primary->id]);
                $this->fail('Invalid name accepted.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('keyword_clusters', 0);
            }
        }
    }

    public function test_foreign_primary_and_members_are_rejected_even_for_an_actor_who_manages_both_projects(): void
    {
        [$owner, $project, $primary] = $this->fixture();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Second workspace');
        $foreignProject = app(CreateProject::class)->handle($owner, $workspace, 'foreign.example');
        $foreign = $this->keyword($foreignProject, 'کلمه خارجی');
        try {
            app(CreateKeywordCluster::class)->handle($owner, $project, 'Cluster', $primary, [$primary->id, $foreign->id]);
            $this->fail('Foreign member accepted.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('keyword_clusters', 0);
        }
        $this->expectException(AuthorizationException::class);
        app(CreateKeywordCluster::class)->handle($owner, $project, 'Cluster', $foreign, [$foreign->id]);
    }

    public function test_viewer_cannot_create_clusters_at_the_service_boundary(): void
    {
        [, $project, $primary] = $this->fixture();
        $viewer = User::factory()->create();
        Membership::query()->create(['workspace_id' => $project->workspace_id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);
        $this->expectException(AuthorizationException::class);
        app(CreateKeywordCluster::class)->handle($viewer, $project, 'Cluster', $primary, [$primary->id]);
    }

    public function test_creation_never_silently_moves_existing_memberships(): void
    {
        [$owner, $project, $primary, $secondary] = $this->fixture();
        $first = app(CreateKeywordCluster::class)->handle($owner, $project, 'First', $primary, [$primary->id]);
        try {
            app(CreateKeywordCluster::class)->handle($owner, $project, 'Second', $secondary, [$primary->id, $secondary->id]);
            $this->fail('Existing keyword silently moved.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('keyword_clusters', 1);
            $this->assertDatabaseCount('keyword_cluster_members', 1);
            $this->assertDatabaseHas('keyword_cluster_members', ['keyword_id' => $primary->id, 'keyword_cluster_id' => $first->id]);
            $this->assertDatabaseCount('change_sets', 1);
        }
    }

    public function test_database_rejects_duplicate_membership(): void
    {
        [$owner, $project, $primary] = $this->fixture();
        $cluster = app(CreateKeywordCluster::class)->handle($owner, $project, 'Cluster', $primary, [$primary->id]);
        $this->expectException(QueryException::class);
        KeywordClusterMember::query()->create(['project_id' => $project->id, 'keyword_cluster_id' => $cluster->id, 'keyword_id' => $primary->id]);
    }

    public function test_database_rejects_cross_project_membership(): void
    {
        [$owner, $project, $primary] = $this->fixture();
        [, , $foreign] = $this->fixture();
        $cluster = app(CreateKeywordCluster::class)->handle($owner, $project, 'Cluster', $primary, [$primary->id]);
        $this->expectException(QueryException::class);
        KeywordClusterMember::query()->create(['project_id' => $project->id, 'keyword_cluster_id' => $cluster->id, 'keyword_id' => $foreign->id]);
    }

    public function test_database_prevents_deleting_a_primary_keyword(): void
    {
        [$owner, $project, $primary] = $this->fixture();
        app(CreateKeywordCluster::class)->handle($owner, $project, 'Cluster', $primary, [$primary->id]);
        $this->expectException(QueryException::class);
        $primary->delete();
    }

    public function test_primary_membership_cannot_be_removed_or_reassigned(): void
    {
        [$owner, $project, $primary, $secondary] = $this->fixture();
        $cluster = app(CreateKeywordCluster::class)->handle($owner, $project, 'Cluster', $primary, [$primary->id]);
        $member = $cluster->members()->firstOrFail();
        try {
            $member->delete();
            $this->fail('Primary membership removed.');
        } catch (DomainException) {
            $this->assertDatabaseCount('keyword_cluster_members', 1);
        }
        $this->expectException(DomainException::class);
        $member->update(['keyword_id' => $secondary->id]);
    }

    public function test_primary_replacement_must_already_belong_to_the_cluster(): void
    {
        [$owner, $project, $primary, $secondary] = $this->fixture();
        $cluster = app(CreateKeywordCluster::class)->handle($owner, $project, 'Cluster', $primary, [$primary->id]);
        $this->expectException(DomainException::class);
        $cluster->update(['primary_keyword_id' => $secondary->id]);
    }

    /** @return array{User, Project, Keyword, Keyword} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'example.com');

        return [$owner, $project, $this->keyword($project, 'خرید لپ تاپ'), $this->keyword($project, 'قیمت لپ تاپ')];
    }

    private function keyword(Project $project, string $text): Keyword
    {
        return Keyword::query()->create(['project_id' => $project->id, 'display_text' => $text, 'normalized_text' => $text, 'status' => 'review']);
    }
}
