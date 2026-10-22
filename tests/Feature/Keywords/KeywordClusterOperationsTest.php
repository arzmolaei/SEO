<?php

namespace Tests\Feature\Keywords;

use App\Models\Keyword;
use App\Models\User;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Keywords\MergeKeywordClusters;
use App\Services\Keywords\MoveKeywordBetweenClusters;
use App\Services\Keywords\SetKeywordClusterLock;
use App\Services\Keywords\SplitKeywordCluster;
use App\Services\Keywords\UndoKeywordClusterChangeSet;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class KeywordClusterOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_preserves_identity_and_audits_then_undo_restores_members(): void
    {
        [$actor, $project, $a, $b, $keywords] = $this->fixture();
        $set = app(MergeKeywordClusters::class)->handle($actor, $project, $a, [$b->id]);
        $this->assertSame(4, $a->members()->count());
        $this->assertNotNull($b->fresh()->archived_at);
        $this->assertSame(0, $b->members()->count());
        $this->assertSame($actor->id, $set->actor_id);
        $this->assertDatabaseHas('activity_events', ['event' => 'keywords.clusters.merge', 'actor_id' => $actor->id]);
        app(UndoKeywordClusterChangeSet::class)->handle($actor, $project, $set);
        $this->assertSame(2, $a->members()->count());
        $this->assertSame(2, $b->members()->count());
        $this->assertNull($b->fresh()->archived_at);
        $this->assertNotNull($set->fresh()->undone_at);
        $this->expectException(ConflictHttpException::class);
        app(UndoKeywordClusterChangeSet::class)->handle($actor, $project, $set);
    }

    public function test_move_preserves_primary_invariant_and_can_be_undone(): void
    {
        [$actor, $project, $a, $b, $keywords] = $this->fixture();
        $set = app(MoveKeywordBetweenClusters::class)->handle($actor, $project, $keywords[0], $b, $keywords[1]);
        $this->assertSame($keywords[1]->id, $a->fresh()->primary_keyword_id);
        $this->assertSame(1, $a->members()->count());
        $this->assertSame(3, $b->members()->count());
        $this->assertSame('user', $b->fresh()->origin);
        app(UndoKeywordClusterChangeSet::class)->handle($actor, $project, $set);
        $this->assertSame($keywords[0]->id, $a->fresh()->primary_keyword_id);
        $this->assertSame(2, $b->members()->count());
    }

    public function test_primary_move_without_explicit_remaining_replacement_is_rejected(): void
    {
        [$actor, $project, $a, $b, $keywords] = $this->fixture();
        $this->expectException(ConflictHttpException::class);
        app(MoveKeywordBetweenClusters::class)->handle($actor, $project, $keywords[0], $b);
    }

    public function test_split_reuses_creation_contract_and_undo_removes_created_cluster(): void
    {
        [$actor, $project, $a, , $keywords] = $this->fixture();
        $set = app(SplitKeywordCluster::class)->handle($actor, $project, $a, [$keywords[1]->id], 'Split', $keywords[1]);
        $this->assertDatabaseCount('keyword_clusters', 3);
        $this->assertSame(1, $a->members()->count());
        app(UndoKeywordClusterChangeSet::class)->handle($actor, $project, $set);
        $this->assertDatabaseCount('keyword_clusters', 2);
        $this->assertSame(2, $a->members()->count());
    }

    public function test_locked_cluster_requires_explicit_unlock_and_same_lock_is_noop(): void
    {
        [$actor, $project, $a, $b, $keywords] = $this->fixture();
        $lock = app(SetKeywordClusterLock::class)->handle($actor, $project, $a, true);
        $this->assertTrue($a->fresh()->is_locked);
        $this->assertNull(app(SetKeywordClusterLock::class)->handle($actor, $project, $a, true));
        try {
            app(MoveKeywordBetweenClusters::class)->handle($actor, $project, $keywords[1], $b);
            $this->fail('Locked membership changed.');
        } catch (ConflictHttpException) {
            $this->assertSame(2, $a->members()->count());
        }
        app(UndoKeywordClusterChangeSet::class)->handle($actor, $project, $lock);
        $this->assertFalse($a->fresh()->is_locked);
    }

    public function test_undo_refuses_to_overwrite_an_intervening_operation(): void
    {
        [$actor, $project, $a, $b, $keywords] = $this->fixture();
        $set = app(MoveKeywordBetweenClusters::class)->handle($actor, $project, $keywords[1], $b);
        app(SetKeywordClusterLock::class)->handle($actor, $project, $b, true);
        $this->expectException(ConflictHttpException::class);
        app(UndoKeywordClusterChangeSet::class)->handle($actor, $project, $set);
    }

    public function test_foreign_cluster_ids_are_rejected_without_mutation(): void
    {
        [$actor, $project, $a] = $this->fixture();
        [, , $foreign] = $this->fixture();
        $this->expectException(AuthorizationException::class);
        app(MergeKeywordClusters::class)->handle($actor, $project, $a, [$foreign->id]);
    }

    public function test_outsider_cannot_lock_or_undo_by_guessed_identity(): void
    {
        [$actor, $project, $a] = $this->fixture();
        $outsider = User::factory()->create();
        $this->expectException(AuthorizationException::class);
        app(SetKeywordClusterLock::class)->handle($outsider, $project, $a, true);
    }

    private function fixture(): array
    {
        $actor = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($actor, 'Studio');
        $project = app(CreateProject::class)->handle($actor, $workspace, 'example.com');
        $keywords = [];
        foreach (['خرید لپ تاپ', 'قیمت لپ تاپ', 'خرید کفش', 'قیمت کفش'] as $text) {
            $keywords[] = Keyword::query()->create(['project_id' => $project->id, 'display_text' => $text, 'normalized_text' => $text, 'status' => 'review']);
        }
        $create = app(CreateKeywordCluster::class);
        $a = $create->handle($actor, $project, 'A', $keywords[0], [$keywords[0]->id, $keywords[1]->id]);
        $b = $create->handle($actor, $project, 'B', $keywords[2], [$keywords[2]->id, $keywords[3]->id]);

        return [$actor, $project, $a, $b, $keywords];
    }
}
