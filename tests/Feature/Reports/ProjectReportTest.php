<?php

namespace Tests\Feature\Reports;

use App\Enums\WorkspaceRole;
use App\Models\ContentBrief;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SearchConsole\ImportSearchConsoleCsv;
use App\Services\Tasks\ProjectTasks;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-30 12:00:00');
        $this->workspace = Workspace::query()->create(['name' => 'فضای من']);
        $this->owner = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        Membership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Owner]);
        $this->project = Project::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'فروشگاه']);
    }

    private function seedProject(): void
    {
        $keyword = DB::table('keywords')->insertGetId([
            'project_id' => $this->project->id, 'display_text' => 'خرید کفش', 'normalized_text' => 'خرید کفش',
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cluster = DB::table('keyword_clusters')->insertGetId([
            'project_id' => $this->project->id, 'primary_keyword_id' => $keyword, 'name' => 'کفش',
            'review_state' => 'reviewed', 'lifecycle_state' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $page = DB::table('pages')->insertGetId([
            'project_id' => $this->project->id, 'kind' => 'planned', 'title' => 'کفش', 'on_page_status' => 'done',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pages')->insert([
            'project_id' => $this->project->id, 'kind' => 'existing', 'title' => 'خانه', 'url' => 'https://example.test/',
            'normalized_url' => 'https://example.test/', 'url_hash' => hash('sha256', 'https://example.test/'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cluster_targets')->insert([
            'project_id' => $this->project->id, 'keyword_cluster_id' => $cluster, 'page_id' => $page,
            'type' => 'planned_page', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['published', '2026-11-10'], ['published', '2026-09-01'], ['draft', null]] as $n => [$status, $published]) {
            ContentBrief::query()->create([
                'project_id' => $this->project->id, 'title' => 'بریف '.$n, 'primary_keyword' => 'خرید کفش',
                'secondary_keywords' => [], 'headings' => [], 'status' => $status, 'published_on' => $published,
            ]);
        }
        $tasks = app(ProjectTasks::class);
        $done = $tasks->create($this->project, $this->owner, ['title' => 'انجام‌شده']);
        $tasks->update($this->project, $done, $this->owner, ['status' => 'done']);
        $tasks->create($this->project, $this->owner, ['title' => 'عقب‌افتاده', 'due_on' => '2026-11-01']);

        $csv = storage_path('framework/testing/report-queries.csv');
        File::ensureDirectoryExists(dirname($csv));
        File::put($csv, "Top queries,Clicks,Impressions,CTR,Position\nخرید کفش,40,1000,4%,5.0\nکفش ارزان,10,1000,1%,15.0\n");
        app(ImportSearchConsoleCsv::class)->handle($this->project, $this->owner, $csv, 'Queries.csv', CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-28'));
        File::delete($csv);
    }

    public function test_the_report_summarises_the_project_for_the_period(): void
    {
        $this->seedProject();

        $this->actingAs($this->owner)->get(route('projects.reports.index', ['project' => $this->project, 'from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()
            ->assertInertia(fn (Assert $view) => $view->component('projects/report')
                ->where('report.period', ['from' => '2026-11-01', 'to' => '2026-11-30'])
                ->where('report.path.progress.total', 8)
                ->where('report.keywords', ['keywords' => 1, 'clusters' => 1, 'mapped' => 1, 'ready' => 1])
                ->where('report.pages.existing', 1)
                ->where('report.pages.planned', 1)
                ->where('report.pages.on_page', ['todo' => 1, 'in_progress' => 0, 'done' => 1])
                ->where('report.pages.last_check', null)
                ->where('report.content.published', 2)
                ->where('report.content.published_in_period', 1)
                ->where('report.tasks', ['open' => 1, 'overdue' => 1, 'done_in_period' => 1, 'created_in_period' => 2])
                ->where('report.performance.totals.clicks', 50)
                ->where('report.performance.totals.position', 10)
                ->where('report.performance.top_queries.0.key', 'خرید کفش')
                ->where('report.activity.0', ['event' => 'task.created', 'count' => 2]));
    }

    public function test_the_default_period_is_the_last_30_days_and_the_range_is_bounded(): void
    {
        $this->actingAs($this->owner)->get(route('projects.reports.index', $this->project))
            ->assertInertia(fn (Assert $view) => $view->where('report.period', ['from' => '2026-11-01', 'to' => '2026-11-30'])
                ->where('report.performance', null));
        $this->actingAs($this->owner)->get(route('projects.reports.index', ['project' => $this->project, 'from' => '2015-01-01', 'to' => '2026-11-30']))
            ->assertInertia(fn (Assert $view) => $view->where('report.period.from', '2024-11-30'));
        $this->actingAs($this->owner)->get(route('projects.reports.index', ['project' => $this->project, 'from' => '2026-11-30', 'to' => '2026-11-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_the_report_downloads_as_a_persian_csv(): void
    {
        $this->seedProject();

        $response = $this->actingAs($this->owner)->get(route('projects.reports.csv', ['project' => $this->project, 'from' => '2026-11-01', 'to' => '2026-11-30']));

        $response->assertOk();
        $this->assertStringContainsString('seo-report-'.$this->project->id.'-2026-11-01-2026-11-30.csv', (string) $response->headers->get('content-disposition'));
        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('"کارهای انجام‌شده در این دوره",tasks.done_in_period,1', $body);
        $this->assertStringContainsString('"کار انجام‌شده: ساخت کار",activity.task.created,2', $body);
    }

    public function test_only_project_members_see_the_report(): void
    {
        $viewer = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        Membership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $viewer->id, 'role' => WorkspaceRole::Viewer]);
        $this->actingAs($viewer)->get(route('projects.reports.index', $this->project))->assertOk();

        $outsider = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        $this->actingAs($outsider)->get(route('projects.reports.index', $this->project))->assertForbidden();
        $this->actingAs($outsider)->get(route('projects.reports.csv', $this->project))->assertForbidden();
        $this->assertSame(0, ProjectTask::query()->count());
    }
}
