<?php

namespace Tests\Feature\Pages;

use App\Domain\Pages\NormalizePageUrl;
use App\Enums\WorkspaceRole;
use App\Models\ChangeSet;
use App\Models\CrawlRun;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Crawl\CrawlerFactory;
use App\Services\Crawl\CrawlRunProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeCrawlTransport;
use Tests\TestCase;

class PageLiveCheckTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://example.test';

    private User $owner;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['crawler.default_delay_ms' => 0]);
        $this->workspace = Workspace::query()->create(['name' => 'فضای من']);
        $this->owner = $this->member(WorkspaceRole::Owner);
        $this->project = Project::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'سایت']);
    }

    private function member(WorkspaceRole $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        Membership::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** @param  array<string, mixed>  $fields */
    private function page(string $path, array $fields = []): int
    {
        $normalized = (new NormalizePageUrl)->normalize(self::SITE.$path);

        return DB::table('pages')->insertGetId($fields + [
            'project_id' => $this->project->id, 'kind' => 'existing', 'title' => $path,
            'url' => self::SITE.$path, 'normalized_url' => $normalized, 'url_hash' => hash('sha256', $normalized),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $routes */
    private function site(array $routes): FakeCrawlTransport
    {
        $transport = new FakeCrawlTransport($routes);
        $this->app->instance(CrawlerFactory::class, new CrawlerFactory($transport, static fn (): array => ['93.184.216.34']));

        return $transport;
    }

    private function drain(): void
    {
        $run = CrawlRun::query()->where('project_id', $this->project->id)->latest('id')->firstOrFail();
        $processor = app(CrawlRunProcessor::class);
        for ($i = 0; $i < 50; $i++) {
            if ($processor->processBatch($run->id) !== 'more') {
                return;
            }
        }
        $this->fail('The check did not settle.');
    }

    public function test_checking_the_site_reads_only_the_owners_pages_and_shows_problems_per_page(): void
    {
        $shoes = $this->page('/shoes', ['seo_title' => 'خرید کفش ورزشی', 'h1' => 'خرید کفش']);
        $gone = $this->page('/gone');
        $this->page('/draft', ['url' => null, 'normalized_url' => null, 'url_hash' => null, 'kind' => 'planned']);
        $transport = $this->site([
            self::SITE.'/shoes' => FakeCrawlTransport::html('<html><head><title>فروشگاه کفش</title><meta name="robots" content="noindex"></head><body><h1>خرید کفش</h1><a href="/elsewhere">x</a></body></html>'),
            self::SITE.'/gone' => FakeCrawlTransport::html('<html><body>nope</body></html>', 404),
        ]);

        $this->actingAs($this->owner)->get(route('projects.pages.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('check.checkable', 2)->where('check.active', null)->where('check.canCheck', true)
                ->where('pages.data.0.live', null));

        $this->actingAs($this->owner)->post(route('projects.pages.check', $this->project))
            ->assertRedirect(route('projects.pages.index', $this->project));
        $run = CrawlRun::query()->sole();
        $this->assertSame('list', $run->mode);
        $this->assertSame(0, $run->settings['max_depth']);

        $this->actingAs($this->owner)->get(route('projects.pages.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('check.active.id', $run->id)->where('check.active.discovered', 2));

        $this->drain();
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertNotContains(self::SITE.'/elsewhere', $transport->requests, 'a page check never follows links');

        $this->actingAs($this->owner)->get(route('projects.pages.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('check.active', null)
                ->where('pages.data.0.id', $shoes)
                ->where('pages.data.0.live.problems', 4)
                ->where('pages.data.1.live.problems', 1)
                ->where('pages.data.2.live', null));

        $this->actingAs($this->owner)->get(route('projects.pages.show', [$this->project, $shoes]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('live.status_code', 200)
                ->where('live.title', 'فروشگاه کفش')
                ->where('live.problems.0', ['key' => 'noindex', 'value' => null])
                ->where('live.problems.1', ['key' => 'title_differs', 'value' => 'فروشگاه کفش'])
                ->where('live.problems.2', ['key' => 'meta_missing', 'value' => null])
                ->where('live.problems.3.key', 'thin_content'));
        $this->actingAs($this->owner)->get(route('projects.pages.show', [$this->project, $gone]))
            ->assertInertia(fn (Assert $page) => $page->where('live.problems', [['key' => 'http_error', 'value' => 404]]));
    }

    public function test_the_latest_completed_check_wins(): void
    {
        $shoes = $this->page('/shoes');
        $this->site([self::SITE.'/shoes' => FakeCrawlTransport::html('<html><body>old</body></html>', 404)]);
        $this->actingAs($this->owner)->post(route('projects.pages.check', $this->project));
        $this->drain();
        $this->site([self::SITE.'/shoes' => FakeCrawlTransport::html('<html><head><title>تازه</title></head><body><h1>تازه</h1></body></html>')]);
        $this->actingAs($this->owner)->post(route('projects.pages.check', $this->project));
        $this->drain();

        $this->actingAs($this->owner)->get(route('projects.pages.show', [$this->project, $shoes]))
            ->assertInertia(fn (Assert $page) => $page->where('live.title', 'تازه'));
    }

    public function test_a_second_check_is_refused_while_one_is_running_and_projects_without_addresses_are_told_why(): void
    {
        $this->actingAs($this->owner)->post(route('projects.pages.check', $this->project))->assertSessionHasErrors('check');
        $this->assertSame(0, CrawlRun::query()->count());

        $this->page('/shoes');
        $this->actingAs($this->owner)->post(route('projects.pages.check', $this->project))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('projects.pages.check', $this->project))->assertSessionHasErrors('check');
        $this->assertSame(1, CrawlRun::query()->count());
    }

    public function test_live_values_fill_only_empty_fields_and_are_recorded(): void
    {
        $shoes = $this->page('/shoes', ['seo_title' => 'عنوان خودم']);
        $this->site([self::SITE.'/shoes' => FakeCrawlTransport::html('<html><head><title>عنوان سایت</title><meta name="description" content="توضیح سایت"></head><body><h1>سرتیتر سایت</h1></body></html>')]);
        $this->actingAs($this->owner)->post(route('projects.pages.check', $this->project));
        $this->drain();

        $this->actingAs($this->owner)->post(route('projects.pages.adopt-live', [$this->project, $shoes]))
            ->assertRedirect(route('projects.pages.show', [$this->project, $shoes]));
        $this->actingAs($this->owner)->post(route('projects.pages.adopt-live', [$this->project, $shoes]));

        $this->assertDatabaseHas('pages', ['id' => $shoes, 'seo_title' => 'عنوان خودم', 'meta_description' => 'توضیح سایت', 'h1' => 'سرتیتر سایت']);
        $set = ChangeSet::query()->where('action', 'page.live_values_adopted')->sole();
        $this->assertNull($set->changes[0]['before']['h1']);
    }

    public function test_adopting_before_any_check_is_refused(): void
    {
        $shoes = $this->page('/shoes');

        $this->actingAs($this->owner)->post(route('projects.pages.adopt-live', [$this->project, $shoes]))->assertSessionHasErrors('adopt');
        $this->assertSame(0, ChangeSet::query()->count());
    }

    public function test_viewers_and_outsiders_cannot_start_checks_or_adopt_values(): void
    {
        $shoes = $this->page('/shoes');
        $viewer = $this->member(WorkspaceRole::Viewer);
        $this->actingAs($viewer)->get(route('projects.pages.index', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('check.canCheck', false));
        $this->actingAs($viewer)->post(route('projects.pages.check', $this->project))->assertForbidden();
        $this->actingAs($viewer)->post(route('projects.pages.adopt-live', [$this->project, $shoes]))->assertForbidden();

        $outsider = User::factory()->create(['email_verified_at' => now(), 'onboarded_at' => now()]);
        $this->actingAs($outsider)->post(route('projects.pages.check', $this->project))->assertForbidden();
        $this->assertSame(0, CrawlRun::query()->count());
    }
}
