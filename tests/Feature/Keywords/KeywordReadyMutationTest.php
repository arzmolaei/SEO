<?php

namespace Tests\Feature\Keywords;

use App\Domain\Keywords\ClusterLifecycleState;
use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Keywords\ClusterTargetType;
use App\Domain\Keywords\KeywordIntent;
use App\Models\Keyword;
use App\Models\User;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Keywords\MapClusterToTarget;
use App\Services\Keywords\MoveKeywordBetweenClusters;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeywordReadyMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_change_invalidates_a_ready_cluster(): void
    {
        $actor = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($actor, 'Studio');
        $project = app(CreateProject::class)->handle($actor, $workspace, 'example.com');

        $primary = $this->keyword($project->id, 'کفش ورزشی');
        $secondary = $this->keyword($project->id, 'کفش ورزشی زنانه');
        $other = $this->keyword($project->id, 'لپ تاپ');

        $source = app(CreateKeywordCluster::class)->handle(
            $actor,
            $project,
            'کفش ورزشی',
            $primary,
            [$primary->id, $secondary->id],
            KeywordIntent::Commercial,
        );
        $target = app(CreateKeywordCluster::class)->handle(
            $actor,
            $project,
            'لپ تاپ',
            $other,
            [$other->id],
            KeywordIntent::Commercial,
        );

        $source->update(['review_state' => ClusterReviewState::Reviewed]);
        app(MapClusterToTarget::class)->handle($actor, $project, $source, ClusterTargetType::Ignore);
        $source->update(['lifecycle_state' => ClusterLifecycleState::Ready]);

        app(MoveKeywordBetweenClusters::class)->handle($actor, $project, $secondary, $target);

        $this->assertSame(ClusterLifecycleState::Planning, $source->fresh()->lifecycle_state);
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
