<?php

namespace Tests\Feature\Content;

use App\Enums\WorkspaceRole;
use App\Models\ChangeSet;
use App\Models\ContentBrief;
use App\Models\ImportBatch;
use App\Models\KeywordMetric;
use App\Models\KeywordSource;
use App\Models\Membership;
use App\Models\Project;
use App\Models\StagedKeywordRow;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContentBriefTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'فضای من']);
        $this->owner = $this->member(WorkspaceRole::Owner);
        $this->project = Project::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'فروشگاه']);
    }

    private function member(WorkspaceRole $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        Membership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /**
     * @param  array<string, ?int>  $others  keyword => search volume
     */
    private function cluster(string $primary, array $others = [], ?Project $project = null, string $intent = 'informational'): int
    {
        $project ??= $this->project;
        $ids = [];
        foreach ([$primary => null, ...$others] as $text => $volume) {
            $id = DB::table('keywords')->insertGetId([
                'project_id' => $project->id, 'display_text' => $text, 'normalized_text' => $text,
                'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($volume !== null) {
                $this->volume($project, $id, $text, $volume);
            }
            $ids[] = $id;
        }
        $cluster = DB::table('keyword_clusters')->insertGetId([
            'project_id' => $project->id, 'primary_keyword_id' => $ids[0], 'name' => 'خوشه '.$primary, 'intent' => $intent,
            'review_state' => 'reviewed', 'lifecycle_state' => 'planning', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($ids as $id) {
            DB::table('keyword_cluster_members')->insert([
                'project_id' => $project->id, 'keyword_cluster_id' => $cluster, 'keyword_id' => $id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $cluster;
    }

    private function volume(Project $project, int $keywordId, string $text, int $volume): void
    {
        $batch = ImportBatch::query()->create([
            'project_id' => $project->id, 'source' => 'manual_csv', 'original_filename' => 'k.csv',
            'mapping' => ['keyword' => 'keyword'], 'status' => 'committed',
        ]);
        $row = StagedKeywordRow::query()->create([
            'import_batch_id' => $batch->id, 'row_number' => 1, 'keyword_original' => $text,
            'raw_payload' => ['keyword' => $text], 'mapped_payload' => ['keyword' => $text], 'is_valid' => true,
        ]);
        $source = KeywordSource::query()->create([
            'keyword_id' => $keywordId, 'import_batch_id' => $batch->id, 'staged_keyword_row_id' => $row->id,
            'source' => 'manual_csv', 'original_text' => $text, 'raw_payload' => [],
        ]);
        KeywordMetric::query()->create([
            'keyword_id' => $keywordId, 'keyword_source_id' => $source->id, 'metric' => 'volume',
            'value' => $volume, 'observed_at' => now(),
        ]);
    }

    public function test_a_brief_is_generated_from_a_cluster_once_with_keywords_ordered_by_volume(): void
    {
        $cluster = $this->cluster('خرید کفش', ['کفش ارزان' => 10, 'قیمت کفش' => 500, 'کفش چیست' => null]);
        $page = DB::table('pages')->insertGetId(['project_id' => $this->project->id, 'kind' => 'planned', 'title' => 'کفش', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cluster_targets')->insert(['project_id' => $this->project->id, 'keyword_cluster_id' => $cluster, 'page_id' => $page, 'type' => 'planned_page', 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->actingAs($this->owner)->post(route('projects.content.store', $this->project), ['cluster_id' => $cluster]);
        $brief = ContentBrief::query()->sole();
        $response->assertRedirect(route('projects.content.show', [$this->project, $brief]));
        $this->actingAs($this->owner)->post(route('projects.content.store', $this->project), ['cluster_id' => $cluster])
            ->assertRedirect(route('projects.content.show', [$this->project, $brief]));

        $this->assertSame(1, ContentBrief::query()->count());
        $this->assertSame('خرید کفش', $brief->primary_keyword);
        $this->assertSame(['قیمت کفش', 'کفش ارزان', 'کفش چیست'], $brief->secondary_keywords);
        $this->assertSame(['قیمت کفش', 'کفش ارزان', 'پرسش‌های متداول', 'کفش چیست؟'], $brief->headings);
        $this->assertSame('informational', $brief->intent);
        $this->assertSame($page, $brief->page_id);
        $this->assertSame('draft', $brief->status);
        $this->assertSame(1, ChangeSet::query()->where('action', 'content_brief.created')->count());

        $this->actingAs($this->owner)->get(route('projects.content.show', [$this->project, $brief]))
            ->assertOk()
            ->assertInertia(fn (Assert $view) => $view->component('projects/content-brief')
                ->where('brief.headings.0', 'قیمت کفش')->where('page.id', $page)->where('canEdit', true));
    }

    public function test_clusters_of_other_projects_and_archived_clusters_cannot_be_used(): void
    {
        $other = Project::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'دیگری']);
        $foreign = $this->cluster('بیگانه', [], $other);
        $archived = $this->cluster('بایگانی');
        DB::table('keyword_clusters')->where('id', $archived)->update(['archived_at' => now()]);

        foreach ([$foreign, $archived] as $cluster) {
            $this->actingAs($this->owner)->post(route('projects.content.store', $this->project), ['cluster_id' => $cluster])
                ->assertSessionHasErrors('cluster');
        }
        $this->assertSame(0, ContentBrief::query()->count());
    }

    public function test_editing_moves_the_brief_through_its_statuses_with_dates_and_audit(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        $brief = $this->generated();
        $update = fn (array $data) => $this->actingAs($this->owner)->patch(route('projects.content.update', [$this->project, $brief]), $data + [
            'title' => 'راهنمای خرید کفش', 'headings' => ['بخش یک', '  ', 'بخش دو'], 'notes' => 'لحن دوستانه',
            'status' => 'draft', 'due_on' => '2026-11-10', 'published_on' => null,
        ]);

        $update([])->assertRedirect(route('projects.content.show', [$this->project, $brief]));
        $brief->refresh();
        $this->assertSame(['بخش یک', 'بخش دو'], $brief->headings);
        $this->assertSame('2026-11-10', $brief->due_on?->toDateString());

        $update(['status' => 'review']);
        $update(['status' => 'published']);
        $this->assertSame('2026-11-02', $brief->refresh()->published_on?->toDateString(), 'publishing without a date uses today');
        $update(['status' => 'published', 'published_on' => '2026-11-02']);
        Carbon::setTestNow('2026-11-05 10:00:00');
        $update(['status' => 'published', 'notes' => 'ویرایش بعد از انتشار']);
        $this->assertSame('2026-11-02', $brief->refresh()->published_on?->toDateString(), 'a later save without a date keeps the publication date');

        $this->assertSame(4, ChangeSet::query()->where('action', 'content_brief.updated')->count(), 'an unchanged save is not recorded');
        $this->actingAs($this->owner)->patch(route('projects.content.update', [$this->project, $brief]), ['title' => '', 'headings' => [], 'status' => 'done', 'due_on' => '10/11/2026'])
            ->assertSessionHasErrors(['title', 'status', 'due_on']);
    }

    public function test_the_calendar_groups_open_briefs_by_due_date_and_lists_recent_publications(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        $make = function (string $title, ?string $due, string $status = 'draft', ?string $published = null): void {
            ContentBrief::query()->create([
                'project_id' => $this->project->id, 'title' => $title, 'primary_keyword' => $title, 'secondary_keywords' => [],
                'headings' => [], 'status' => $status, 'due_on' => $due, 'published_on' => $published,
            ]);
        };
        $make('دیرشده', '2026-10-30');
        $make('این هفته', '2026-11-05', 'review');
        $make('این ماه', '2026-11-20');
        $make('بعدها', '2027-01-15');
        $make('بی‌تاریخ', null);
        $make('منتشرشده', '2026-10-20', 'published', '2026-10-25');
        $make('قدیمی', null, 'published', '2026-08-01');

        $this->actingAs($this->owner)->get(route('projects.content.index', $this->project))
            ->assertInertia(fn (Assert $view) => $view->component('projects/content')
                ->where('agenda.overdue.items.0.title', 'دیرشده')
                ->where('agenda.week.items.0.title', 'این هفته')
                ->where('agenda.month.items.0.title', 'این ماه')
                ->where('agenda.later.items.0.title', 'بعدها')
                ->where('agenda.unscheduled.items.0.title', 'بی‌تاریخ')
                ->where('agenda.published.count', 1)
                ->where('agenda.published.items.0.title', 'منتشرشده')
                ->has('briefs.data', 7)
                ->where('briefs.data.0.title', 'منتشرشده'));

        $this->actingAs($this->owner)->get(route('projects.content.index', ['project' => $this->project, 'status' => 'review']))
            ->assertInertia(fn (Assert $view) => $view->has('briefs.data', 1));
    }

    public function test_cluster_options_offer_clusters_without_a_brief_first_and_search(): void
    {
        $withBrief = $this->generated();
        $this->cluster('سئو سایت');

        $this->actingAs($this->owner)->get(route('projects.content.index', $this->project))
            ->assertInertia(fn (Assert $view) => $view
                ->where('clusterOptions.0.primary_keyword', 'سئو سایت')->where('clusterOptions.0.has_brief', false)
                ->where('clusterOptions.1.has_brief', true));
        $this->actingAs($this->owner)->get(route('projects.content.index', ['project' => $this->project, 'cluster_q' => 'سئو']))
            ->assertInertia(fn (Assert $view) => $view->has('clusterOptions', 1));
        $this->assertNotNull($withBrief->id);
    }

    public function test_deleting_keeps_a_recoverable_record(): void
    {
        $brief = $this->generated();

        $this->actingAs($this->owner)->delete(route('projects.content.destroy', [$this->project, $brief]))
            ->assertRedirect(route('projects.content.index', $this->project));

        $this->assertSame(0, ContentBrief::query()->count());
        $set = ChangeSet::query()->where('action', 'content_brief.deleted')->sole();
        $this->assertSame('خرید کفش', $set->changes[0]['before']['primary_keyword']);
    }

    public function test_viewers_read_only_and_other_projects_are_isolated(): void
    {
        $brief = $this->generated();
        $viewer = $this->member(WorkspaceRole::Viewer);
        $this->actingAs($viewer)->get(route('projects.content.show', [$this->project, $brief]))
            ->assertOk()->assertInertia(fn (Assert $view) => $view->where('canEdit', false));
        $this->actingAs($viewer)->post(route('projects.content.store', $this->project), ['cluster_id' => $brief->keyword_cluster_id])->assertForbidden();
        $this->actingAs($viewer)->delete(route('projects.content.destroy', [$this->project, $brief]))->assertForbidden();

        $outsider = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        $this->actingAs($outsider)->get(route('projects.content.index', $this->project))->assertForbidden();

        $other = Project::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'دیگری']);
        $this->actingAs($this->owner)->get(route('projects.content.show', [$other, $brief]))->assertNotFound();
        $this->assertSame(1, ContentBrief::query()->count());
    }

    private function generated(): ContentBrief
    {
        $cluster = $this->cluster('خرید کفش', ['قیمت کفش' => 100]);
        $this->actingAs($this->owner)->post(route('projects.content.store', $this->project), ['cluster_id' => $cluster]);

        return ContentBrief::query()->where('keyword_cluster_id', $cluster)->sole();
    }
}
