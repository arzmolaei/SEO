<?php

namespace Tests\Feature\Review;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordIntent;
use App\Enums\WorkspaceRole;
use App\Jobs\RefreshProjectIntentReviews;
use App\Models\Keyword;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ReviewItem;
use App\Models\User;
use App\Services\Keywords\AssignKeywordIntent;
use App\Services\Projects\CreateProject;
use App\Services\Review\ResolveIntentReview;
use App\Services\Review\UpsertIntentReviewItems;
use App\Services\Workspaces\CreateWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class IntentReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_queues_all_confidences_by_default_and_is_idempotent(): void
    {
        [$owner, $project, $keyword] = $this->keyword('آموزش خرید لپ تاپ');
        $unknown = $this->addKeyword($project, 'لپ تاپ');
        $high = $this->addKeyword($project, 'خرید لپ تاپ');
        $service = app(UpsertIntentReviewItems::class);

        foreach ([$keyword, $unknown, $high] as $item) {
            $service->handle($owner, $project, $item);
            $service->handle($owner, $project, $item);
        }

        $this->assertDatabaseCount('review_items', 3);
        $this->assertDatabaseCount('keyword_intent_assignments', 0);
        $this->assertSame('medium', ReviewItem::query()->where('keyword_id', $keyword->id)->firstOrFail()->suggestion['confidence']);
        $this->assertSame('low', ReviewItem::query()->where('keyword_id', $unknown->id)->firstOrFail()->suggestion['confidence']);
        $this->assertSame('high', ReviewItem::query()->where('keyword_id', $high->id)->firstOrFail()->suggestion['confidence']);
    }

    public function test_high_confidence_auto_application_is_explicit_and_never_overwrites_user_intent(): void
    {
        config(['keyword-intelligence.intent.auto_apply_high_confidence' => true]);
        [$owner, $project, $keyword] = $this->keyword('خرید لپ تاپ');
        $service = app(UpsertIntentReviewItems::class);
        $service->handle($owner, $project, $keyword);
        $service->handle($owner, $project, $keyword);
        $this->assertDatabaseCount('review_items', 0);
        $this->assertDatabaseCount('keyword_intent_assignments', 1);
        $this->assertDatabaseHas('keyword_intent_assignments', ['source' => 'rule', 'intent' => 'transactional', 'is_current' => true]);

        app(AssignKeywordIntent::class)->handle($owner, $project, $keyword, KeywordIntent::Commercial, IntentConfidence::High, 'user');
        $service->handle($owner, $project, $keyword);
        $this->assertDatabaseCount('keyword_intent_assignments', 2);
        $this->assertDatabaseHas('keyword_intent_assignments', ['source' => 'user', 'intent' => 'commercial', 'is_current' => true]);
        $mixed = $this->addKeyword($project, 'آموزش خرید');
        $service->handle($owner, $project, $mixed);
        $this->assertDatabaseCount('review_items', 1);
    }

    public function test_accept_and_change_create_attributed_manual_assignments_and_resolution_history(): void
    {
        [$owner, $project, $keyword] = $this->keyword('آموزش خرید');
        $item = app(UpsertIntentReviewItems::class)->handle($owner, $project, $keyword);
        $this->assertInstanceOf(ReviewItem::class, $item);
        app(ResolveIntentReview::class)->handle($owner, $project, $item, $item->fingerprint, 'accept');
        $this->assertDatabaseHas('keyword_intent_assignments', ['keyword_id' => $keyword->id, 'source' => 'user', 'intent' => 'mixed', 'actor_id' => $owner->id]);
        $this->assertDatabaseHas('review_items', ['id' => $item->id, 'status' => 'resolved', 'resolution' => 'accept', 'resolved_by_id' => $owner->id]);
        $this->assertNotNull($item->fresh()->resolved_at);
        $this->assertDatabaseHas('activity_events', ['event' => 'review.intent.resolved', 'actor_id' => $owner->id, 'project_id' => $project->id]);
        $this->assertDatabaseHas('change_sets', ['action' => 'review.intent.resolve', 'actor_id' => $owner->id]);
        app(UpsertIntentReviewItems::class)->handle($owner, $project, $keyword);
        $this->assertSame('resolved', $item->fresh()->status);

        $other = $this->addKeyword($project, 'کلمه مبهم');
        $otherItem = app(UpsertIntentReviewItems::class)->handle($owner, $project, $other);
        app(ResolveIntentReview::class)->handle($owner, $project, $otherItem, $otherItem->fingerprint, 'change', KeywordIntent::Local);
        $this->assertDatabaseHas('keyword_intent_assignments', ['keyword_id' => $other->id, 'intent' => 'local', 'source' => 'user']);
    }

    public function test_skip_survives_retry_and_reopens_only_on_changed_evidence(): void
    {
        [$owner, $project, $keyword] = $this->keyword('لپ تاپ');
        $service = app(UpsertIntentReviewItems::class);
        $item = $service->handle($owner, $project, $keyword);
        app(ResolveIntentReview::class)->handle($owner, $project, $item, $item->fingerprint, 'skip');
        $service->handle($owner, $project, $keyword);
        $this->assertSame('resolved', $item->fresh()->status);
        $this->assertDatabaseCount('keyword_intent_assignments', 0);

        $keyword->update(['display_text' => 'آموزش خرید لپ تاپ']);
        $service->handle($owner, $project, $keyword);
        $this->assertDatabaseCount('review_items', 1);
        $this->assertSame('pending', $item->fresh()->status);
        $this->assertNotSame($item->fingerprint, $item->fresh()->fingerprint);
        $this->assertNull($item->fresh()->resolved_by_id);
    }

    public function test_stale_fingerprint_cannot_resolve_new_suggestion(): void
    {
        [$owner, $project, $keyword] = $this->keyword('لپ تاپ');
        $item = app(UpsertIntentReviewItems::class)->handle($owner, $project, $keyword);
        $keyword->update(['display_text' => 'آموزش خرید']);
        app(UpsertIntentReviewItems::class)->handle($owner, $project, $keyword);

        try {
            app(ResolveIntentReview::class)->handle($owner, $project, $item, $item->fingerprint, 'accept');
            $this->fail('Stale evidence must be rejected.');
        } catch (ConflictHttpException) {
            $this->assertDatabaseCount('keyword_intent_assignments', 0);
            $this->assertSame('pending', $item->fresh()->status);
        }
    }

    public function test_service_rejects_foreign_keywords_and_read_only_actors(): void
    {
        [$owner, $project] = $this->keyword('آموزش خرید');
        [, , $foreign] = $this->keyword('خرید');
        try {
            app(UpsertIntentReviewItems::class)->handle($owner, $project, $foreign);
            $this->fail('Foreign keyword accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('review_items', 0);
        }
        $viewer = $this->viewer($project);
        $this->expectException(AuthorizationException::class);
        app(UpsertIntentReviewItems::class)->handle($viewer, $project, $foreign);
    }

    public function test_http_queue_is_paginated_and_rejects_guessed_ids_and_viewer_writes(): void
    {
        [$owner, $project, $keyword] = $this->keyword('آموزش خرید');
        $item = app(UpsertIntentReviewItems::class)->handle($owner, $project, $keyword);
        for ($i = 0; $i < 26; $i++) {
            app(UpsertIntentReviewItems::class)->handle($owner, $project, $this->addKeyword($project, 'کلمه '.$i));
        }
        [$otherOwner, $foreignProject, $foreign] = $this->keyword('خرید');
        $foreignItem = app(UpsertIntentReviewItems::class)->handle($otherOwner, $foreignProject, $foreign);
        $url = "/projects/{$project->id}/reviews";
        $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('projects/review-queue')->has('reviews.data', 25)
            ->where('reviews.total', 27)->where('canManage', true));
        $this->actingAs($otherOwner)->get($url)->assertForbidden();
        $this->actingAs($owner)->post($url.'/'.$foreignItem->id.'/resolve', ['fingerprint' => $foreignItem->fingerprint, 'decision' => 'accept'])->assertForbidden();
        $viewer = $this->viewer($project);
        $this->actingAs($viewer)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false));
        $this->post($url.'/refresh')->assertForbidden();
        $this->post($url.'/'.$item->id.'/resolve', ['fingerprint' => $item->fingerprint, 'decision' => 'accept'])->assertForbidden();
        $this->assertDatabaseCount('keyword_intent_assignments', 0);
    }

    public function test_http_resolution_validation_stale_conflict_and_repeated_decision(): void
    {
        [$owner, $project, $keyword] = $this->keyword('لپ تاپ');
        $item = app(UpsertIntentReviewItems::class)->handle($owner, $project, $keyword);
        $url = "/projects/{$project->id}/reviews/{$item->id}/resolve";
        $this->actingAs($owner)->postJson($url, ['decision' => 'invent'])->assertUnprocessable();
        $this->postJson($url, ['fingerprint' => $item->fingerprint, 'decision' => 'change', 'intent' => 'invent'])->assertUnprocessable();
        $this->postJson($url, ['fingerprint' => str_repeat('a', 64), 'decision' => 'accept'])->assertConflict();
        $this->post($url, ['fingerprint' => $item->fingerprint, 'decision' => 'change', 'intent' => 'commercial'])->assertRedirect();
        $this->postJson($url, ['fingerprint' => $item->fingerprint, 'decision' => 'accept'])->assertConflict();
        $this->assertDatabaseCount('keyword_intent_assignments', 1);
    }

    public function test_refresh_dispatches_a_retry_safe_job_and_revoked_access_is_rechecked(): void
    {
        [$owner, $project, $keyword] = $this->keyword('آموزش خرید');
        Queue::fake();
        $this->actingAs($owner)->post("/projects/{$project->id}/reviews/refresh")->assertRedirect();
        Queue::assertPushed(RefreshProjectIntentReviews::class);
        $job = new RefreshProjectIntentReviews($owner->id, $project->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertDatabaseCount('review_items', 1);
        Membership::query()->where('workspace_id', $project->workspace_id)->delete();
        $this->expectException(AuthorizationException::class);
        app()->call([$job, 'handle']);
    }

    /** @return array{User, Project, Keyword} */
    private function keyword(string $text): array
    {
        $owner = User::factory()->create();
        $workspace = app(CreateWorkspace::class)->handle($owner, 'Studio');
        $project = app(CreateProject::class)->handle($owner, $workspace, 'example.com');

        return [$owner, $project, $this->addKeyword($project, $text)];
    }

    private function addKeyword(Project $project, string $text): Keyword
    {
        return Keyword::query()->create(['project_id' => $project->id, 'display_text' => $text, 'normalized_text' => $text, 'status' => 'review']);
    }

    private function viewer(Project $project): User
    {
        $viewer = User::factory()->create();
        Membership::query()->create(['workspace_id' => $project->workspace_id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);

        return $viewer;
    }
}
