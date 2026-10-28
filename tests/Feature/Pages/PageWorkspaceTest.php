<?php

namespace Tests\Feature\Pages;

use App\Enums\WorkspaceRole;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PageWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->member(WorkspaceRole::Owner);
        $this->project = Project::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'فروشگاه']);
    }

    private function member(WorkspaceRole $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        $this->workspace ??= Workspace::query()->create(['name' => 'فضای من']);
        Membership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function page(string $title, ?string $url = null, ?Project $project = null): int
    {
        $project ??= $this->project;

        return DB::table('pages')->insertGetId([
            'project_id' => $project->id, 'kind' => $url === null ? 'planned' : 'existing', 'title' => $title,
            'url' => $url, 'normalized_url' => $url, 'url_hash' => $url === null ? null : hash('sha256', $url),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Creates a cluster whose primary keyword is $primary, with $others as members, targeting $pageId.
     *
     * @param  list<string>  $others
     */
    private function target(int $pageId, string $primary, array $others = []): void
    {
        $keywordIds = [];
        foreach ([$primary, ...$others] as $text) {
            $keywordIds[] = DB::table('keywords')->insertGetId([
                'project_id' => $this->project->id, 'display_text' => $text, 'normalized_text' => $text,
                'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $cluster = DB::table('keyword_clusters')->insertGetId([
            'project_id' => $this->project->id, 'primary_keyword_id' => $keywordIds[0], 'name' => 'خوشه '.$primary,
            'review_state' => 'reviewed', 'lifecycle_state' => 'planning', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($keywordIds as $keywordId) {
            DB::table('keyword_cluster_members')->insert([
                'project_id' => $this->project->id, 'keyword_cluster_id' => $cluster, 'keyword_id' => $keywordId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('cluster_targets')->insert([
            'project_id' => $this->project->id, 'keyword_cluster_id' => $cluster, 'page_id' => $pageId,
            'type' => 'planned_page', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_pages_are_listed_with_their_target_and_checklist_score_and_can_be_filtered(): void
    {
        $shoes = $this->page('کفش', 'https://example.com/shoes');
        $this->target($shoes, 'خرید کفش');
        $this->page('درباره ما');
        DB::table('pages')->where('id', $shoes)->update(['on_page_status' => 'in_progress']);

        $this->actingAs($this->owner)->get(route('projects.pages.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/pages')
                ->has('pages.data', 2)
                ->where('pages.data.0.id', $shoes)
                ->where('pages.data.0.targets.0.primary_keyword', 'خرید کفش')
                ->where('pages.data.0.score', ['passed' => 4, 'applicable' => 7])
                ->where('pages.data.1.targets', [])
                ->where('canEdit', true));

        $this->actingAs($this->owner)->get(route('projects.pages.index', ['project' => $this->project, 'status' => 'in_progress']))
            ->assertInertia(fn (Assert $page) => $page->has('pages.data', 1)->where('pages.data.0.id', $shoes));
        $this->actingAs($this->owner)->get(route('projects.pages.index', ['project' => $this->project, 'kind' => 'planned', 'q' => 'درباره']))
            ->assertInertia(fn (Assert $page) => $page->has('pages.data', 1)->where('pages.data.0.title', 'درباره ما'));
        $this->actingAs($this->owner)->get(route('projects.pages.index', ['project' => $this->project, 'q' => '100%']))
            ->assertInertia(fn (Assert $page) => $page->has('pages.data', 0));
    }

    public function test_the_list_is_paginated_on_the_server(): void
    {
        foreach (range(1, 25) as $n) {
            $this->page('صفحه '.$n);
        }

        $this->actingAs($this->owner)->get(route('projects.pages.index', ['project' => $this->project, 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('pages.data', 5)->where('pages.total', 25));
    }

    public function test_saving_page_text_updates_the_checklist_and_is_recorded_once(): void
    {
        $shoes = $this->page('کفش', 'https://example.com/shoes');
        $this->target($shoes, 'خرید کفش', ['کفش ورزشی', 'قیمت کفش']);
        $payload = [
            'seo_title' => 'خرید کفش ورزشی مردانه با ارسال رایگان و ضمانت',
            'meta_description' => 'راهنمای خرید کفش ورزشی: مقایسه مدل‌ها، قیمت روز و نکته‌های انتخاب سایز برای پیاده‌روی و دویدن.',
            'h1' => 'خرید کفش ورزشی',
            'on_page_status' => 'done',
            'on_page_notes' => 'عکس‌ها را عوض کنم.',
        ];

        $this->actingAs($this->owner)->patch(route('projects.pages.update', [$this->project, $shoes]), $payload)
            ->assertRedirect(route('projects.pages.show', [$this->project, $shoes]));
        // Saving the same values again is not a change and leaves no second record.
        $this->actingAs($this->owner)->patch(route('projects.pages.update', [$this->project, $shoes]), $payload);

        $this->assertDatabaseHas('pages', ['id' => $shoes, 'h1' => 'خرید کفش ورزشی', 'on_page_status' => 'done']);
        $set = ChangeSet::query()->where('action', 'page.on_page_updated')->sole();
        $this->assertNull($set->changes[0]['before']['seo_title']);
        $this->assertSame('done', $set->changes[0]['after']['on_page_status']);
        $this->assertSame(1, ActivityEvent::query()->where('event', 'page.on_page_updated')->where('change_set_id', $set->id)->count());

        $this->actingAs($this->owner)->get(route('projects.pages.show', [$this->project, $shoes]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/page-detail')
                ->where('page.seo_title', $payload['seo_title'])
                ->where('score', ['passed' => 13, 'applicable' => 13])
                ->where('secondaryKeywords', ['کفش ورزشی', 'قیمت کفش']));
    }

    public function test_a_title_shared_with_another_page_is_flagged_after_normalization(): void
    {
        $first = $this->page('الف', 'https://example.com/a');
        $second = $this->page('ب', 'https://example.com/b');
        DB::table('pages')->where('id', $first)->update(['seo_title' => 'خرید کفش']);
        DB::table('pages')->where('id', $second)->update(['seo_title' => 'خريد  كفش']);

        $this->actingAs($this->owner)->get(route('projects.pages.show', [$this->project, $second]))
            ->assertInertia(fn (Assert $page) => $page->where('checklist.5', ['key' => 'title_unique', 'status' => 'fix']));
    }

    public function test_invalid_input_is_rejected_without_changes(): void
    {
        $id = $this->page('کفش');

        $this->actingAs($this->owner)->from(route('projects.pages.show', [$this->project, $id]))
            ->patch(route('projects.pages.update', [$this->project, $id]), ['on_page_status' => 'finished', 'seo_title' => str_repeat('a', 256)])
            ->assertSessionHasErrors(['on_page_status', 'seo_title']);

        $this->assertDatabaseHas('pages', ['id' => $id, 'seo_title' => null, 'on_page_status' => 'todo']);
        $this->assertSame(0, ChangeSet::query()->count());
    }

    public function test_viewers_read_but_cannot_edit_and_outsiders_and_other_projects_are_refused(): void
    {
        $id = $this->page('کفش');
        $viewer = $this->member(WorkspaceRole::Viewer);

        $this->actingAs($viewer)->get(route('projects.pages.show', [$this->project, $id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
        $this->actingAs($viewer)->patch(route('projects.pages.update', [$this->project, $id]), ['on_page_status' => 'done'])
            ->assertForbidden();

        $outsider = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        $this->actingAs($outsider)->get(route('projects.pages.index', $this->project))->assertForbidden();
        $this->actingAs($outsider)->get(route('projects.pages.show', [$this->project, $id]))->assertForbidden();

        // A page id from another project cannot be reached through this project.
        $other = Project::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'دیگری']);
        $foreign = $this->page('بیگانه', null, $other);
        $this->actingAs($this->owner)->get(route('projects.pages.show', [$this->project, $foreign]))->assertNotFound();
        $this->actingAs($this->owner)->patch(route('projects.pages.update', [$this->project, $foreign]), ['on_page_status' => 'done'])
            ->assertNotFound();
        $this->assertDatabaseHas('pages', ['id' => $foreign, 'on_page_status' => 'todo']);
    }
}
