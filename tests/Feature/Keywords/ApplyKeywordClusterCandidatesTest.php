<?php

namespace Tests\Feature\Keywords;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Domain\Review\ReviewItemType;
use App\Models\ActivityEvent;
use App\Models\Keyword;
use App\Models\Project;
use App\Models\ReviewItem;
use App\Models\User;
use App\Services\Keywords\ApplyKeywordClusterCandidates;
use App\Services\Keywords\AssignKeywordIntent;
use App\Services\Keywords\CreateKeywordCluster;
use App\Services\Keywords\GenerateKeywordClusterCandidates;
use App\Services\Projects\CreateProject;
use App\Services\Review\ResolveClusterReview;
use App\Services\Review\ResolveIntentReview;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ApplyKeywordClusterCandidatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_confidence_requires_opt_in_and_repeat_is_idempotent_with_audit_source(): void
    {
        [$actor, $project, $a, $b, $candidate] = $this->fixture();
        app(ApplyKeywordClusterCandidates::class)->handle($actor, $project, [$candidate]);
        $this->assertDatabaseCount('keyword_clusters', 0);
        $this->assertDatabaseCount('review_items', 1);
        // A policy change must not bypass an already queued human decision.
        config(['keyword-intelligence.clustering.auto_apply_high_confidence' => true]);
        app(ApplyKeywordClusterCandidates::class)->handle($actor, $project, [$candidate]);
        $this->assertDatabaseCount('keyword_clusters', 0);
        $review = ReviewItem::query()->firstOrFail();
        app(ResolveClusterReview::class)->handle($actor, $project, $review, $review->fingerprint, 'accept');
        app(ApplyKeywordClusterCandidates::class)->handle($actor, $project, [$candidate]);
        $this->assertDatabaseCount('keyword_clusters', 1);
        $this->assertDatabaseHas('keyword_clusters', ['origin' => 'user']);
        $this->assertDatabaseCount('keyword_cluster_members', 2);
        $this->assertDatabaseHas('activity_events', ['event' => 'review.cluster.resolved']);
    }

    public function test_opted_in_high_confidence_application_is_idempotent(): void
    {
        config(['keyword-intelligence.clustering.auto_apply_high_confidence' => true]);
        [$actor, $project, , , $candidate] = $this->fixture();
        $apply = app(ApplyKeywordClusterCandidates::class);
        $apply->handle($actor, $project, [$candidate]);
        $apply->handle($actor, $project, [$candidate]);
        $this->assertDatabaseCount('keyword_clusters', 1);
        $this->assertDatabaseCount('review_items', 0);
        $this->assertDatabaseHas('keyword_clusters', ['origin' => 'lexical']);
        $this->assertDatabaseHas('activity_events', ['event' => 'keywords.cluster.automated']);
    }

    public function test_medium_confidence_goes_to_review_even_when_automation_is_enabled(): void
    {
        config(['keyword-intelligence.clustering.auto_apply_high_confidence' => true]);
        [$actor, $project, , , $candidate] = $this->fixture('خرید لپ تاپ گیمینگ');
        app(ApplyKeywordClusterCandidates::class)->handle($actor, $project, [$candidate]);
        app(ApplyKeywordClusterCandidates::class)->handle($actor, $project, [$candidate]);
        $this->assertDatabaseCount('keyword_clusters', 0);
        $this->assertDatabaseCount('review_items', 1);
        $this->assertSame(ReviewItemType::Cluster, ReviewItem::query()->firstOrFail()->type);
    }

    public function test_locked_and_manual_memberships_are_never_moved(): void
    {
        config(['keyword-intelligence.clustering.auto_apply_high_confidence' => true]);
        [$actor, $project, $a, $b, $candidate] = $this->fixture();
        $manual = app(CreateKeywordCluster::class)->handle($actor, $project, 'Human choice', $a, [$a->id], locked: true);
        app(ApplyKeywordClusterCandidates::class)->handle($actor, $project, [$candidate]);
        $this->assertDatabaseCount('keyword_clusters', 1);
        $this->assertDatabaseCount('keyword_cluster_members', 1);
        $this->assertTrue($manual->fresh()->is_locked);
        $this->assertDatabaseHas('keyword_cluster_members', ['keyword_id' => $a->id, 'keyword_cluster_id' => $manual->id]);
    }

    public function test_foreign_candidate_rolls_back_earlier_candidate_in_the_same_call(): void
    {
        config(['keyword-intelligence.clustering.auto_apply_high_confidence' => true]);
        [$actor, $project, , , $valid] = $this->fixture();
        [, , , , $foreign] = $this->fixture();
        try {
            app(ApplyKeywordClusterCandidates::class)->handle($actor, $project, [$valid, $foreign]);
            $this->fail('Cross-project candidate accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('keyword_clusters', 0);
            $this->assertDatabaseCount('keyword_cluster_members', 0);
        }
    }

    public function test_stale_snapshot_cannot_be_applied(): void
    {
        [$actor, $project, $a, , $candidate] = $this->fixture();
        $a->update(['display_text' => 'خرید بیمه خودرو']);
        $this->expectException(ConflictHttpException::class);
        app(ApplyKeywordClusterCandidates::class)->handle($actor, $project, [$candidate]);
    }

    public function test_skip_stays_closed_until_evidence_changes_and_intent_resolver_rejects_cluster_items(): void
    {
        [$actor, $project, $a, $b, $candidate] = $this->fixture();
        $apply = app(ApplyKeywordClusterCandidates::class);
        $apply->handle($actor, $project, [$candidate]);
        $review = ReviewItem::query()->firstOrFail();
        app(ResolveClusterReview::class)->handle($actor, $project, $review, $review->fingerprint, 'skip');
        $apply->handle($actor, $project, [$candidate]);
        $this->assertSame('resolved', $review->fresh()->status);
        $b->update(['display_text' => 'خرید لپ تاپ گیمینگ']);
        $new = $this->candidate($project, [$a, $b]);
        $apply->handle($actor, $project, [$new]);
        $this->assertSame('pending', $review->fresh()->status);
        $this->assertDatabaseCount('review_items', 1);
        $this->expectException(ConflictHttpException::class);
        app(ResolveIntentReview::class)->handle($actor, $project, $review->fresh(), $review->fresh()->fingerprint, 'accept');
    }

    public function test_cluster_suggestion_is_visible_and_can_be_accepted_at_http_boundary(): void
    {
        [$actor, $project, , , $candidate] = $this->fixture();
        app(ApplyKeywordClusterCandidates::class)->handle($actor, $project, [$candidate]);
        $review = ReviewItem::query()->firstOrFail();
        $this->actingAs($actor)->get("/projects/{$project->id}/reviews")->assertOk()
            ->assertInertia(fn ($page) => $page->has('reviews.data', 1)->where('reviews.data.0.type', 'cluster')->missing('reviews.data.0.suggestion.candidate'));
        $this->post("/projects/{$project->id}/reviews/{$review->id}/resolve", ['decision' => 'accept', 'fingerprint' => $review->fingerprint])->assertRedirect();
        $this->assertDatabaseCount('keyword_clusters', 1);
        $this->assertSame('resolved', $review->fresh()->status);
    }

    public function test_later_stale_candidate_rolls_back_all_mutations_including_audit(): void
    {
        config(['keyword-intelligence.clustering.auto_apply_high_confidence' => true]);
        [$actor, $project, , , $first] = $this->fixture();
        $items = [];
        foreach (['خرید کفش ورزشی', 'قیمت کفش ورزشی'] as $text) {
            $k = Keyword::query()->create(['project_id' => $project->id, 'display_text' => $text, 'normalized_text' => $text, 'status' => 'review']);
            app(AssignKeywordIntent::class)->handle($actor, $project, $k, KeywordIntent::Transactional, IntentConfidence::High, 'user');
            $items[] = $k;
        }
        $second = $this->candidate($project, $items);
        $items[0]->update(['display_text' => 'بیمه خودرو']);
        $auditCount = ActivityEvent::query()->count();
        try {
            app(ApplyKeywordClusterCandidates::class)->handle($actor, $project, [$first, $second]);
            $this->fail('Stale evidence accepted.');
        } catch (ConflictHttpException) {
            $this->assertDatabaseCount('keyword_clusters', 0);
            $this->assertDatabaseCount('keyword_cluster_members', 0);
            $this->assertDatabaseCount('activity_events', $auditCount);
        }
    }

    private function fixture(string $second = 'قیمت لپ تاپ'): array
    {
        $actor = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($actor, 'Studio');
        $project = app(CreateProject::class)->handle($actor, $workspace, 'example.com');
        $items = [];
        foreach (['خرید لپ تاپ', $second] as $text) {
            $keyword = Keyword::query()->create(['project_id' => $project->id, 'display_text' => $text, 'normalized_text' => $text, 'status' => 'review']);
            app(AssignKeywordIntent::class)->handle($actor, $project, $keyword, KeywordIntent::Transactional, IntentConfidence::High, 'user');
            $items[] = $keyword;
        }

        return [$actor, $project, ...$items, $this->candidate($project, $items)];
    }

    private function candidate(Project $project, array $items): mixed
    {
        return app(GenerateKeywordClusterCandidates::class)->generate(array_map(fn ($k) => [
            'id' => $k->id, 'project_id' => $project->id, 'text' => $k->display_text, 'intent' => KeywordIntent::Transactional,
        ], $items))[0];
    }
}
