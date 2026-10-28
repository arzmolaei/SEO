<?php

namespace Tests\Feature\Projects;

use App\Enums\WorkspaceRole;
use App\Models\ActivityEvent;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Projects\ProjectGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectGuideTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        $workspace = Workspace::query()->create(['name' => 'فضای من']);
        Membership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Owner]);
        $this->project = Project::query()->create(['workspace_id' => $workspace->id, 'name' => 'فروشگاه']);
    }

    /** @return array<string, string> step key => status */
    private function statuses(): array
    {
        $guide = app(ProjectGuide::class)->for($this->project);

        return array_column($guide['steps'], 'status', 'key');
    }

    private function keyword(string $text): int
    {
        return DB::table('keywords')->insertGetId([
            'project_id' => $this->project->id, 'display_text' => $text, 'normalized_text' => $text,
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function cluster(int $keywordId, string $review = 'pending', string $lifecycle = 'planning'): int
    {
        return DB::table('keyword_clusters')->insertGetId([
            'project_id' => $this->project->id, 'primary_keyword_id' => $keywordId, 'name' => 'خوشه '.$keywordId,
            'review_state' => $review, 'lifecycle_state' => $lifecycle, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function review(int $keywordId, string $type): void
    {
        DB::table('review_items')->insert([
            'project_id' => $this->project->id, 'keyword_id' => $keywordId, 'type' => $type, 'status' => 'pending',
            'fingerprint' => str_repeat('a', 64), 'suggestion' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function map(int $clusterId): void
    {
        $page = DB::table('pages')->insertGetId([
            'project_id' => $this->project->id, 'kind' => 'planned', 'title' => 'صفحه '.$clusterId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cluster_targets')->insert([
            'project_id' => $this->project->id, 'keyword_cluster_id' => $clusterId, 'page_id' => $page,
            'type' => 'planned_page', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_empty_project_starts_with_importing_keywords_and_later_steps_wait(): void
    {
        $this->assertSame([
            'import' => 'todo', 'intent' => 'waiting', 'clusters' => 'waiting', 'mapping' => 'waiting',
            'conflicts' => 'waiting', 'ready' => 'waiting', 'export' => 'waiting', 'site_check' => 'todo',
        ], $this->statuses());
        $this->assertSame(['import', 'site_check'], app(ProjectGuide::class)->for($this->project)['next']);
    }

    public function test_statuses_follow_real_project_data_through_the_whole_path(): void
    {
        $first = $this->keyword('خرید کفش');
        $second = $this->keyword('کفش ورزشی');
        $this->review($first, 'intent');

        $statuses = $this->statuses();
        $this->assertSame('done', $statuses['import']);
        $this->assertSame('todo', $statuses['intent']);
        $this->assertSame('todo', $statuses['clusters'], 'keywords exist but no cluster yet');
        $this->assertSame('waiting', $statuses['mapping']);

        DB::table('review_items')->update(['status' => 'resolved']);
        $a = $this->cluster($first, 'reviewed');
        $b = $this->cluster($second, 'reviewed');
        $statuses = $this->statuses();
        $this->assertSame('done', $statuses['intent']);
        $this->assertSame('done', $statuses['clusters']);
        $this->assertSame('todo', $statuses['mapping']);

        $this->map($a);
        $this->map($b);
        $this->review($second, 'mapping_conflict');
        $statuses = $this->statuses();
        $this->assertSame('done', $statuses['mapping']);
        $this->assertSame('attention', $statuses['conflicts']);
        $this->assertSame('todo', $statuses['ready']);
        $this->assertSame('waiting', $statuses['export']);
        $this->assertSame('conflicts', app(ProjectGuide::class)->for($this->project)['next'][0], 'problems come first');

        DB::table('review_items')->update(['status' => 'resolved']);
        DB::table('keyword_clusters')->update(['lifecycle_state' => 'ready']);
        $statuses = $this->statuses();
        $this->assertSame('done', $statuses['conflicts']);
        $this->assertSame('done', $statuses['ready']);
        $this->assertSame('todo', $statuses['export']);
    }

    public function test_archived_clusters_do_not_count(): void
    {
        $keyword = $this->keyword('قدیمی');
        DB::table('keyword_clusters')->where('id', $this->cluster($keyword))->update(['archived_at' => now()]);

        $this->assertSame('todo', $this->statuses()['clusters']);
    }

    public function test_downloading_the_plan_completes_the_export_step(): void
    {
        $keyword = $this->keyword('کفش');
        $cluster = $this->cluster($keyword, 'reviewed');
        $this->map($cluster);
        DB::table('keyword_clusters')->update(['lifecycle_state' => 'ready']);
        $this->assertSame('todo', $this->statuses()['export']);

        $this->actingAs($this->owner)->get(route('projects.exports.seo-plan', $this->project))->assertOk()->streamedContent();

        $this->assertTrue(ActivityEvent::query()->where('project_id', $this->project->id)->where('event', ProjectGuide::EXPORT_EVENT)->exists());
        $this->assertSame('done', $this->statuses()['export']);
    }

    public function test_the_overview_shows_the_guide_and_only_to_project_members(): void
    {
        $this->actingAs($this->owner)->get(route('projects.overview', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/overview')
                ->has('guide.steps', 8)
                ->where('guide.steps.0.key', 'import')
                ->where('guide.steps.0.href', route('projects.keyword-imports.index', $this->project))
                ->where('guide.next.0', 'import')
                ->where('guide.progress.total', 8));

        $outsider = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        $this->actingAs($outsider)->get(route('projects.overview', $this->project))->assertForbidden();
    }
}
