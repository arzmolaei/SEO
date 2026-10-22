<?php

namespace Tests\Feature\Keywords;

use App\Domain\Keywords\ClusterTargetType;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Keyword;
use App\Models\User;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Keywords\MapClusterToTarget;
use App\Services\Keywords\MergeKeywordClusters;
use App\Services\Keywords\SetKeywordClusterLock;
use App\Services\Keywords\UndoKeywordClusterChangeSet;
use App\Services\Pages\CreatePlannedPage;
use App\Services\Pages\PromotePlannedPage;
use App\Services\Pages\RegisterExistingPage;
use App\Services\Projects\CreateProject;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class KeywordMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_target_type_is_validated_and_remaps_retain_history(): void
    {
        [$actor,$project,$cluster] = $this->fixture();
        $existing = app(RegisterExistingPage::class)->handle($actor, $project, 'https://example.com/a');
        $planned = app(CreatePlannedPage::class)->handle($actor, $project, 'Planned');
        $service = app(MapClusterToTarget::class);
        $originalId = null;
        foreach ([ClusterTargetType::ExistingPage, ClusterTargetType::PlannedPage, ClusterTargetType::MergeTarget, ClusterTargetType::RedirectTarget, ClusterTargetType::Ignore] as $type) {
            $page = $type === ClusterTargetType::Ignore ? null : ($type === ClusterTargetType::PlannedPage ? $planned : $existing);
            $mapping = $service->handle($actor, $project, $cluster, $type, $page);
            $originalId ??= $mapping->id;
            $this->assertSame($originalId, $mapping->id);
            $this->assertSame($type, $mapping->type);
            $this->assertSame($page?->id, $mapping->page_id);
        }
        $this->assertDatabaseCount('cluster_targets', 1);
        $this->assertSame(5, ActivityEvent::query()->where('event', 'keywords.mapping.set')->count());
        $this->assertSame(5, ChangeSet::query()->where('action', 'keywords.mapping.set')->count());
    }

    public function test_identical_mapping_is_a_noop_and_does_not_increment_revision(): void
    {
        [$actor,$project,$cluster] = $this->fixture();
        $map = app(MapClusterToTarget::class);
        $a = $map->handle($actor, $project, $cluster, ClusterTargetType::Ignore);
        $revision = $cluster->fresh()->revision;
        $b = $map->handle($actor, $project, $cluster, ClusterTargetType::Ignore);
        $this->assertSame($a->id, $b->id);
        $this->assertSame($revision, $cluster->fresh()->revision);
        $this->assertSame(1, ActivityEvent::query()->where('event', 'keywords.mapping.set')->count());
    }

    public function test_wrong_page_kind_and_missing_or_extraneous_pages_are_rejected(): void
    {
        [$actor,$project,$cluster] = $this->fixture();
        $planned = app(CreatePlannedPage::class)->handle($actor, $project, 'Planned');
        foreach ([[ClusterTargetType::ExistingPage, $planned], [ClusterTargetType::Ignore, $planned], [ClusterTargetType::PlannedPage, null]] as [$type,$page]) {
            try {
                app(MapClusterToTarget::class)->handle($actor, $project, $cluster, $type, $page);
                $this->fail('Invalid target accepted.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('cluster_targets', 0);
            }
        }
    }

    public function test_foreign_pages_are_rejected_at_service_boundary(): void
    {
        [$actor,$project,$cluster] = $this->fixture();
        [$other,$otherProject] = $this->fixture();
        $page = app(CreatePlannedPage::class)->handle($other, $otherProject, 'Foreign');
        $this->expectException(AuthorizationException::class);
        app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::PlannedPage, $page);
    }

    public function test_promotion_keeps_mapping_identity_and_updates_target_type(): void
    {
        [$actor,$project,$cluster] = $this->fixture();
        $page = app(CreatePlannedPage::class)->handle($actor, $project, 'Planned');
        $mapping = app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::PlannedPage, $page);
        app(PromotePlannedPage::class)->handle($actor, $project, $page, 'https://example.com/new');
        $this->assertSame($mapping->id, $mapping->fresh()->id);
        $this->assertSame($page->id, $mapping->fresh()->page_id);
        $this->assertSame(ClusterTargetType::ExistingPage, $mapping->fresh()->type);
    }

    public function test_new_mapping_invalidates_old_membership_undo(): void
    {
        [$actor,$project,$cluster] = $this->fixture();
        $old = app(SetKeywordClusterLock::class)->handle($actor, $project, $cluster, true);
        app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::Ignore);
        $this->expectException(ConflictHttpException::class);
        app(UndoKeywordClusterChangeSet::class)->handle($actor, $project, $old);
    }

    public function test_database_protects_mapped_page_from_deletion(): void
    {
        [$actor,$project,$cluster] = $this->fixture();
        $page = app(CreatePlannedPage::class)->handle($actor, $project, 'Planned');
        app(MapClusterToTarget::class)->handle($actor, $project, $cluster, ClusterTargetType::PlannedPage, $page);
        $this->expectException(QueryException::class);
        $page->delete();
    }

    public function test_promotion_invalidates_historical_cluster_undo_exactly_once(): void
    {
        [$actor, $project, $source] = $this->fixture();
        $keyword = Keyword::query()->create(['project_id' => $project->id, 'display_text' => 'خرید کفش', 'normalized_text' => 'خرید کفش', 'status' => 'review']);
        $target = app(CreateKeywordCluster::class)->handle($actor, $project, 'Target', $keyword, [$keyword->id]);
        $page = app(CreatePlannedPage::class)->handle($actor, $project, 'Planned');
        app(MapClusterToTarget::class)->handle($actor, $project, $source, ClusterTargetType::PlannedPage, $page);
        $merge = app(MergeKeywordClusters::class)->handle($actor, $project, $target, [$source->id]);
        $revision = $source->fresh()->revision;
        app(PromotePlannedPage::class)->handle($actor, $project, $page, 'https://example.com/new');
        $this->assertSame($revision + 1, $source->fresh()->revision);
        $this->expectException(ConflictHttpException::class);
        app(UndoKeywordClusterChangeSet::class)->handle($actor, $project, $merge);
    }

    private function fixture(): array
    {
        $actor = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($actor, 'Studio');
        $project = app(CreateProject::class)->handle($actor, $workspace, 'example.com');
        $keyword = Keyword::query()->create(['project_id' => $project->id, 'display_text' => 'خرید لپ تاپ', 'normalized_text' => 'خرید لپ تاپ', 'status' => 'review']);
        $cluster = app(CreateKeywordCluster::class)->handle($actor, $project, 'Cluster', $keyword, [$keyword->id]);

        return [$actor, $project, $cluster];
    }
}
