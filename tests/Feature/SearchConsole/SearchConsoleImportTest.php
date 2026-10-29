<?php

namespace Tests\Feature\SearchConsole;

use App\Enums\WorkspaceRole;
use App\Models\ChangeSet;
use App\Models\Membership;
use App\Models\Project;
use App\Models\SearchConsoleImport;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SearchConsoleImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 10:00:00');
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

    private function upload(string $csv, string $start = '2026-10-01', string $end = '2026-10-31', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->post(route('projects.performance.store', $this->project), [
            'file' => UploadedFile::fake()->createWithContent('Queries.csv', $csv),
            'period_start' => $start,
            'period_end' => $end,
        ]);
    }

    public function test_a_persian_queries_export_is_imported_with_totals_and_linked_opportunities(): void
    {
        $keyword = DB::table('keywords')->insertGetId([
            'project_id' => $this->project->id, 'display_text' => 'خرید کفش', 'normalized_text' => 'خرید کفش',
            'status' => 'ready', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // UTF-8 BOM and Persian headers as the Persian Search Console UI exports them.
        $csv = "\xEF\xBB\xBFپرسش‌های برتر,کلیک‌ها,نمایش‌ها,CTR,جایگاه\n"
            ."خريد كفش,۵,۴۰۰,1.25%,۷٫۲\n"
            ."کفش ورزشی,90,400,22.5%,1.2\n"
            .",3,4,75%,1\n"
            ."کفش ورزشی,1,1,100%,1\n";

        $this->upload($csv)->assertRedirect(route('projects.performance.index', ['project' => $this->project, 'kind' => 'queries']));

        $import = SearchConsoleImport::query()->sole();
        $this->assertSame(['queries', 2, 2], [$import->kind, $import->row_count, $import->skipped_count]);
        $this->assertDatabaseHas('search_console_rows', ['key_text' => 'خريد كفش', 'normalized_key' => 'خرید کفش', 'clicks' => 5]);
        $this->assertSame(1, ChangeSet::query()->where('action', 'search_console.imported')->count());

        $this->actingAs($this->owner)->get(route('projects.performance.index', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $view) => $view->component('projects/performance')
                ->where('summary.queries.totals.clicks', 95)
                ->where('summary.queries.totals.impressions', 800)
                ->where('summary.queries.totals.position', 4.2)
                ->where('summary.queries.totals.days', 31)
                ->where('summary.pages', null)
                ->where('opportunities.near_top.0.key', 'خريد كفش')
                ->where('opportunities.near_top.0.keyword_id', $keyword)
                ->has('rows.data', 2)
                ->where('rows.data.0.key', 'کفش ورزشی'));
    }

    public function test_declining_pages_compare_clicks_per_day_with_the_previous_pages_import(): void
    {
        $page = DB::table('pages')->insertGetId([
            'project_id' => $this->project->id, 'kind' => 'existing', 'title' => 'کفش',
            'url' => 'https://example.test/shoes', 'normalized_url' => 'https://example.test/shoes',
            'url_hash' => hash('sha256', 'https://example.test/shoes'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pages = fn (int $shoes, int $bags): string => "Top pages,Clicks,Impressions,CTR,Position\nhttps://example.test/shoes,{$shoes},1000,1%,5\nhttps://example.test/bags,{$bags},1000,1%,5\n";
        $this->upload($pages(300, 60), '2026-08-01', '2026-08-30')->assertSessionHasNoErrors();
        $this->upload($pages(50, 60), '2026-09-01', '2026-09-30')->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->get(route('projects.performance.index', ['project' => $this->project, 'kind' => 'pages']))
            ->assertInertia(fn (Assert $view) => $view
                ->has('opportunities.declining', 1)
                ->where('opportunities.declining.0.key', 'https://example.test/shoes')
                ->where('opportunities.declining.0.page_id', $page)
                ->where('summary.pages.previous.clicks', 360)
                ->has('imports', 2));
    }

    public function test_files_that_are_not_search_console_exports_or_bad_periods_are_rejected(): void
    {
        $this->upload("Keyword,Volume\nکفش,100\n")->assertSessionHasErrors('file');
        $this->upload("Top queries,Clicks,Impressions,CTR,Position\n,1,1,1,1\n")->assertSessionHasErrors('file');
        $this->upload("Top queries,Clicks,Impressions,CTR,Position\nکفش,1,2,50%,3\n", '2026-10-31', '2026-10-01')->assertSessionHasErrors('period_end');
        $this->upload("Top queries,Clicks,Impressions,CTR,Position\nکفش,1,2,50%,3\n", '2026-11-01', '2026-12-01')->assertSessionHasErrors('period_end');
        $this->assertSame(0, SearchConsoleImport::query()->count());
    }

    public function test_deleting_an_import_removes_its_rows_and_is_recorded(): void
    {
        $this->upload("Top queries,Clicks,Impressions,CTR,Position\nکفش,1,2,50%,3\n");
        $import = SearchConsoleImport::query()->sole();

        $this->actingAs($this->owner)->delete(route('projects.performance.destroy', [$this->project, $import]))->assertRedirect();

        $this->assertSame(0, DB::table('search_console_rows')->count());
        $set = ChangeSet::query()->where('action', 'search_console.deleted')->sole();
        $this->assertSame(1, $set->changes[0]['rows']);
    }

    public function test_viewers_read_only_and_other_projects_are_isolated(): void
    {
        $this->upload("Top queries,Clicks,Impressions,CTR,Position\nکفش,1,2,50%,3\n");
        $import = SearchConsoleImport::query()->sole();
        $viewer = $this->member(WorkspaceRole::Viewer);

        $this->actingAs($viewer)->get(route('projects.performance.index', $this->project))
            ->assertOk()->assertInertia(fn (Assert $view) => $view->where('canEdit', false)->has('rows.data', 1));
        $this->upload("Top queries,Clicks,Impressions,CTR,Position\nکفش,1,2,50%,3\n", as: $viewer)->assertForbidden();
        $this->actingAs($viewer)->delete(route('projects.performance.destroy', [$this->project, $import]))->assertForbidden();

        $outsider = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        $this->actingAs($outsider)->get(route('projects.performance.index', $this->project))->assertForbidden();
        $other = Project::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'دیگری']);
        $this->actingAs($this->owner)->delete(route('projects.performance.destroy', [$other, $import]))->assertNotFound();
        $this->assertSame(1, SearchConsoleImport::query()->count());
    }
}
