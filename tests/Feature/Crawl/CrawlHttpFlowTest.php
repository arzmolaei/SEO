<?php

namespace Tests\Feature\Crawl;

use App\Enums\WorkspaceRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\ProcessCrawlRun;
use App\Models\CrawlRun;
use App\Models\CrawlUrl;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Crawl\CrawlRunControls;
use App\Services\Crawl\CrawlRunProcessor;
use App\Services\Crawl\StartCrawlRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CrawlHttpFlowTest extends TestCase
{
    use RefreshDatabase;

    private QueueManager $realQueue;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->realQueue = app('queue');
        Queue::fake();
        $this->owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'فضای خزش']);
        Membership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Owner]);
        $this->project = Project::query()->create(['workspace_id' => $workspace->id, 'name' => 'سایت']);
    }

    private function createRun(): CrawlRun
    {
        return app(StartCrawlRun::class)->handle($this->project, $this->owner, ['mode' => 'site', 'start_url' => 'https://example.test/']);
    }

    public function test_owner_starts_a_queued_crawl_and_reads_server_truth(): void
    {
        $this->actingAs($this->owner)->post("/projects/{$this->project->id}/crawls", [
            'mode' => 'site', 'start_url' => 'HTTPS://Example.test/Original#part',
            'max_urls' => 50, 'max_depth' => 3, 'include_www' => true,
        ])->assertRedirect();
        $run = CrawlRun::query()->sole();
        $this->assertSame('HTTPS://Example.test/Original#part', $run->start_url);
        $this->assertSame('https://example.test/Original', $run->normalized_start_url);
        $this->assertSame('queued', $run->status);
        Queue::assertPushed(ProcessCrawlRun::class, 1);

        $this->get("/projects/{$this->project->id}/crawls")
            ->assertInertia(fn (Assert $page) => $page->component('projects/crawls')
                ->where('canCrawl', true)->where('activeRun.status', 'queued')
                ->where('activeRun.fetched_count', 0)->has('urls.data', 1)
                ->missing('urls.data.0.claim_token')->missing('activeRun.sitemap_queue'));
    }

    public function test_viewers_can_read_but_cannot_start_or_control_crawls_and_outsiders_cannot_read(): void
    {
        $run = $this->createRun();
        $viewer = User::factory()->create();
        Membership::query()->create(['workspace_id' => $this->project->workspace_id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);
        $base = "/projects/{$this->project->id}/crawls";
        $this->actingAs($viewer)->get($base)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCrawl', false));
        $this->post($base, ['mode' => 'site', 'start_url' => 'https://example.test/'])->assertForbidden();
        foreach (['pause', 'resume', 'cancel'] as $action) {
            $this->post($base."/{$run->id}/{$action}")->assertForbidden();
        }
        $this->actingAs(User::factory()->create())->get($base)->assertForbidden();
        $this->assertSame('queued', $run->refresh()->status);
    }

    public function test_foreign_run_ids_are_never_resolved_through_another_project(): void
    {
        $run = $this->createRun();
        $other = Project::query()->create(['workspace_id' => $this->project->workspace_id, 'name' => 'دیگر']);
        $base = "/projects/{$other->id}/crawls";
        $this->actingAs($this->owner)->get($base.'?run='.$run->id)->assertNotFound();
        foreach (['pause', 'resume', 'cancel'] as $action) {
            $this->post($base."/{$run->id}/{$action}")->assertNotFound();
        }
        $this->assertSame('queued', $run->refresh()->status);
    }

    public function test_invalid_input_is_rejected_before_creating_or_queueing_a_run(): void
    {
        $base = "/projects/{$this->project->id}/crawls";
        $this->actingAs($this->owner)->post($base, ['mode' => 'site', 'start_url' => 'https://user:secret@example.test/'])
            ->assertSessionHasErrors('start_url');
        $this->post($base, ['mode' => 'list', 'start_url' => 'https://example.test/', 'urls' => ''])
            ->assertSessionHasErrors('urls');
        $this->post($base, ['mode' => 'invented', 'start_url' => 'https://example.test/', 'max_urls' => 0])
            ->assertSessionHasErrors(['mode', 'max_urls']);
        $this->assertDatabaseCount('crawl_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_invalid_path_prefixes_are_rejected_instead_of_silently_crawling_everything(): void
    {
        $this->actingAs($this->owner)->post("/projects/{$this->project->id}/crawls", [
            'mode' => 'site', 'start_url' => 'https://example.test/', 'include' => 'blog',
        ])->assertSessionHasErrors('include');
        $this->assertDatabaseCount('crawl_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_owner_controls_are_attributed_and_duplicate_resume_does_not_enqueue_twice(): void
    {
        $run = $this->createRun();
        $base = "/projects/{$this->project->id}/crawls/{$run->id}";
        $this->actingAs($this->owner)->post($base.'/pause')->assertRedirect();
        app(CrawlRunProcessor::class)->processBatch($run->id);
        $this->assertSame('paused', $run->refresh()->status);
        $this->post($base.'/resume')->assertRedirect();
        $this->post($base.'/resume')->assertSessionHasErrors('crawl');
        Queue::assertPushed(ProcessCrawlRun::class, 2);
        $this->post($base.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $run->refresh()->status);
        foreach (['started', 'pause_requested', 'resumed', 'cancel_requested'] as $action) {
            $this->assertDatabaseHas('activity_events', ['project_id' => $this->project->id, 'actor_id' => $this->owner->id, 'event' => 'crawl.'.$action]);
        }
    }

    public function test_a_failed_database_queue_insert_rolls_back_start_and_its_audit_records(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.connection' => null]);
        Queue::swap($this->realQueue);
        Schema::rename('jobs', 'unavailable_jobs');
        try {
            try {
                $this->createRun();
                $this->fail('The missing queue table must reject dispatch.');
            } catch (QueryException) {
                $this->assertDatabaseCount('crawl_runs', 0);
                $this->assertDatabaseCount('crawl_urls', 0);
                $this->assertDatabaseCount('activity_events', 0);
                $this->assertDatabaseCount('change_sets', 0);
            }
        } finally {
            Schema::rename('unavailable_jobs', 'jobs');
        }
    }

    public function test_a_failed_resume_dispatch_keeps_the_crawl_paused(): void
    {
        $run = $this->createRun();
        $run->forceFill(['status' => 'paused', 'pause_requested_at' => now()])->save();
        config(['queue.default' => 'database', 'queue.connections.database.connection' => null]);
        Queue::swap($this->realQueue);
        Schema::rename('jobs', 'unavailable_jobs');
        try {
            try {
                app(CrawlRunControls::class)->resume($run, $this->owner);
                $this->fail('The missing queue table must reject dispatch.');
            } catch (QueryException) {
                $this->assertSame('paused', $run->refresh()->status);
                $this->assertNotNull($run->pause_requested_at);
                $this->assertDatabaseMissing('activity_events', ['event' => 'crawl.resumed']);
            }
        } finally {
            Schema::rename('unavailable_jobs', 'jobs');
        }
    }

    public function test_url_results_are_filtered_sorted_paginated_and_safe_to_poll(): void
    {
        $run = $this->createRun();
        CrawlUrl::query()->where('crawl_run_id', $run->id)->delete();
        for ($i = 0; $i < 60; $i++) {
            CrawlUrl::query()->create([
                'crawl_run_id' => $run->id, 'url' => 'https://example.test/'.$i,
                'normalized_url' => 'https://example.test/'.$i, 'url_hash' => hash('sha256', (string) $i),
                'source' => 'link', 'state' => 'fetched', 'status_code' => $i % 2 === 0 ? 200 : 404,
                'indexable' => $i % 2 === 0, 'title' => 'عنوان '.$i, 'depth' => $i,
            ]);
        }
        $base = "/projects/{$this->project->id}/crawls?run={$run->id}";
        $this->actingAs($this->owner)->get($base.'&status_class=4xx&indexable=no&sort=depth&direction=desc')
            ->assertInertia(fn (Assert $page) => $page->has('urls.data', 25)->where('urls.total', 30)
                ->where('urls.data.0.depth', 59)->where('urls.data.0.status_code', 404));
        $this->get($base.'&status_class=4xx&indexable=no&page=2')
            ->assertInertia(fn (Assert $page) => $page->has('urls.data', 5));
        $this->get($base, ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')), 'X-Inertia-Partial-Component' => 'projects/crawls', 'X-Inertia-Partial-Data' => 'activeRun,urls'])
            ->assertOk()->assertJsonMissingPath('props.runs')->assertJsonPath('props.activeRun.status', 'queued');
        $this->get($base.'&sort=claim_token')->assertSessionHasErrors('sort');
    }
}
