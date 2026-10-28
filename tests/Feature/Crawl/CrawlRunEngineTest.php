<?php

namespace Tests\Feature\Crawl;

use App\Enums\WorkspaceRole;
use App\Jobs\ProcessCrawlRun;
use App\Models\CrawlRun;
use App\Models\CrawlUrl;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Crawl\CrawlerFactory;
use App\Services\Crawl\CrawlRunControls;
use App\Services\Crawl\CrawlRunException;
use App\Services\Crawl\CrawlRunProcessor;
use App\Services\Crawl\StartCrawlRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeCrawlTransport;
use Tests\TestCase;

class CrawlRunEngineTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://example.test';

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['crawler.default_delay_ms' => 0]);
        $this->owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'فضا']);
        Membership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Owner]);
        $this->project = Project::query()->create(['workspace_id' => $workspace->id, 'name' => 'سایت']);
    }

    /** @param  list<string>  $addresses */
    private function site(array $routes, array $addresses = ['93.184.216.34']): FakeCrawlTransport
    {
        $transport = new FakeCrawlTransport($routes);
        $this->app->instance(CrawlerFactory::class, new CrawlerFactory($transport, static fn (): array => $addresses));

        return $transport;
    }

    /** @param  array<string, mixed>  $input */
    private function start(array $input = []): CrawlRun
    {
        return app(StartCrawlRun::class)->handle($this->project, $this->owner, $input + ['mode' => 'site', 'start_url' => self::SITE.'/']);
    }

    private function drain(CrawlRun $run): string
    {
        $processor = app(CrawlRunProcessor::class);
        for ($i = 0; $i < 50; $i++) {
            $state = $processor->processBatch($run->id);
            if ($state !== 'more') {
                return $state;
            }
        }
        $this->fail('The crawl did not settle.');
    }

    private function url(CrawlRun $run, string $path): CrawlUrl
    {
        return CrawlUrl::query()->where('crawl_run_id', $run->id)->where('normalized_url', self::SITE.$path)->sole();
    }

    public function test_site_crawl_discovers_links_and_sitemaps_honours_robots_and_never_leaves_the_site(): void
    {
        $transport = $this->site([
            self::SITE.'/robots.txt' => FakeCrawlTransport::text("User-agent: *\nDisallow: /private\nSitemap: ".self::SITE."/sitemap.xml\n"),
            self::SITE.'/sitemap.xml' => FakeCrawlTransport::text('<urlset><url><loc>'.self::SITE.'/from-sitemap</loc></url><url><loc>https://other.test/x</loc></url></urlset>', 200, 'application/xml'),
            self::SITE.'/' => FakeCrawlTransport::html('<html><head><title>خانه</title></head><body><a href="/a">a</a><a href="/private/x">p</a><a href="https://other.test/">o</a><a href="/a#top">a</a></body></html>'),
            self::SITE.'/a' => FakeCrawlTransport::redirect('/b'),
            self::SITE.'/b' => FakeCrawlTransport::html('<html><head><meta name="robots" content="noindex"></head><body>b</body></html>'),
            self::SITE.'/from-sitemap' => FakeCrawlTransport::html('<html><head><title>از نقشه</title></head><body>s</body></html>'),
        ]);

        $run = $this->start();
        Queue::assertPushed(ProcessCrawlRun::class, fn (ProcessCrawlRun $job): bool => $job->runId === $run->id);
        $this->assertSame('done', $this->drain($run));

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $home = $this->url($run, '/');
        $this->assertSame('fetched', $home->state);
        $this->assertSame('خانه', $home->title);
        $this->assertTrue($home->indexable);
        $this->assertSame(2, $home->internal_links - 1, 'fragment duplicates resolve to the same internal URL');
        $this->assertSame('blocked_by_robots', $this->url($run, '/private/x')->error_code);
        $redirected = $this->url($run, '/a');
        $this->assertSame(1, $redirected->redirect_count);
        $this->assertSame(self::SITE.'/b', $redirected->final_url);
        $this->assertFalse($this->url($run, '/b')->indexable, 'noindex pages are not indexable');
        $this->assertSame('از نقشه', $this->url($run, '/from-sitemap')->title);
        $this->assertSame(4, $run->fetched_count);
        $this->assertSame(1, $run->blocked_count);
        foreach ($transport->requests as $request) {
            $this->assertStringStartsWith(self::SITE.'/', $request, 'Only the project site is ever contacted.');
        }
        $this->assertNotContains(self::SITE.'/private/x', $transport->requests);
    }

    public function test_a_site_resolving_to_a_private_network_is_never_contacted(): void
    {
        $transport = $this->site([], ['10.0.0.7']);

        $run = $this->start();
        $this->drain($run);

        $this->assertSame([], $transport->requests);
        $this->assertSame('private_network', $this->url($run, '/')->error_code);
        $this->assertSame('failed', $this->url($run, '/')->state);
    }

    public function test_only_one_crawl_per_project_is_active(): void
    {
        $this->site([]);
        $this->start();

        $this->expectException(CrawlRunException::class);
        $this->start();
    }

    public function test_transient_failures_retry_with_backoff_then_succeed(): void
    {
        $calls = 0;
        $this->site([
            self::SITE.'/' => function () use (&$calls) {
                return ++$calls < 2 ? 'timeout' : FakeCrawlTransport::html('<title>ok</title>');
            },
        ]);

        $run = $this->start(['mode' => 'list', 'urls' => [self::SITE.'/']]);
        $this->assertSame('later', $this->drain($run));
        $this->assertSame('pending', $this->url($run, '/')->state);

        $this->travel(5)->minutes();
        $this->assertSame('done', $this->drain($run));
        $this->assertSame('fetched', $this->url($run, '/')->state);
        $this->assertSame(2, $this->url($run, '/')->attempts);
    }

    public function test_a_killed_worker_claim_is_reclaimed_and_nothing_is_fetched_twice(): void
    {
        $transport = $this->site([self::SITE.'/' => FakeCrawlTransport::html('<title>x</title>')]);
        $run = $this->start(['mode' => 'list', 'urls' => [self::SITE.'/']]);
        CrawlUrl::query()->where('crawl_run_id', $run->id)->update(['claim_token' => 'dead-worker', 'claimed_at' => now()]);

        $this->assertSame('later', app(CrawlRunProcessor::class)->processBatch($run->id), 'a fresh claim belongs to a live worker');
        $this->assertSame([], array_values(array_filter($transport->requests, fn (string $r): bool => $r === self::SITE.'/')));

        $this->travel(10)->minutes();
        $this->assertSame('done', $this->drain($run));
        $this->assertCount(1, array_filter($transport->requests, fn (string $r): bool => $r === self::SITE.'/'));
    }

    public function test_pause_resume_and_cancel_take_effect_at_url_boundaries(): void
    {
        $this->site([self::SITE.'/' => FakeCrawlTransport::html('<a href="/1">1</a><a href="/2">2</a>')]);
        $controls = app(CrawlRunControls::class);

        $run = $this->start();
        $controls->pause($run);
        $this->assertSame('done', app(CrawlRunProcessor::class)->processBatch($run->id));
        $this->assertSame('paused', $run->refresh()->status);
        $this->assertSame('pending', $this->url($run, '/')->state);

        $controls->resume($run);
        Queue::assertPushed(ProcessCrawlRun::class, 2);
        $this->assertSame('queued', $run->refresh()->status);

        $controls->cancel($run);
        $this->assertSame('cancelled', $run->refresh()->status, 'a queued run with no worker is cancelled at once');
        $this->assertSame('skipped', $this->url($run, '/')->state);

        $this->expectException(CrawlRunException::class);
        $controls->resume($run);
    }

    public function test_duplicate_workers_do_not_fetch_another_url_while_a_run_is_owned(): void
    {
        $run = $this->start(['mode' => 'list', 'urls' => [self::SITE.'/', self::SITE.'/second']]);
        $secondWorker = null;
        $transport = $this->site([
            self::SITE.'/' => function () use ($run, &$secondWorker) {
                $secondWorker = app(CrawlRunProcessor::class)->processBatch($run->id);
                $this->assertSame('pending', $this->url($run, '/second')->state, 'Only one request per run may be in flight.');

                return FakeCrawlTransport::html('<title>first</title>');
            },
            self::SITE.'/second' => FakeCrawlTransport::html('<title>second</title>'),
        ]);

        $this->assertSame('done', $this->drain($run));
        $this->assertSame('later', $secondWorker);
        $this->assertCount(1, array_filter($transport->requests, fn (string $url): bool => $url === self::SITE.'/second'));
    }

    public function test_sitemap_discovery_checkpoints_between_bounded_jobs(): void
    {
        $transport = $this->site([
            self::SITE.'/sitemap.xml' => FakeCrawlTransport::text('<sitemapindex><sitemap><loc>'.self::SITE.'/child.xml</loc></sitemap></sitemapindex>', 200, 'application/xml'),
            self::SITE.'/child.xml' => FakeCrawlTransport::text('<urlset><url><loc>'.self::SITE.'/found</loc></url></urlset>', 200, 'application/xml'),
            self::SITE.'/found' => FakeCrawlTransport::html('<title>found</title>'),
        ]);
        $run = $this->start(['mode' => 'sitemap']);

        $this->assertSame('more', app(CrawlRunProcessor::class)->processBatch($run->id));
        $this->assertNotContains(self::SITE.'/child.xml', $transport->requests, 'Discovery must yield instead of holding a worker across fifty network requests.');
        $this->assertSame('running', $run->refresh()->status);
        $this->assertSame('done', $this->drain($run));
        $this->assertSame('fetched', $this->url($run, '/found')->state);
        $this->assertCount(1, array_filter($transport->requests, fn (string $url): bool => $url === self::SITE.'/sitemap.xml'));
    }

    public function test_max_urls_caps_discovery(): void
    {
        $links = implode('', array_map(static fn (int $i): string => '<a href="/p'.$i.'">'.$i.'</a>', range(1, 20)));
        $this->site([self::SITE.'/' => FakeCrawlTransport::html('<body>'.$links.'</body>')]);

        $run = $this->start(['max_urls' => 5]);
        $this->drain($run);

        $this->assertSame(5, CrawlUrl::query()->where('crawl_run_id', $run->id)->count());
        $this->assertSame(5, $run->refresh()->discovered_count);
    }

    public function test_out_of_scope_list_urls_are_recorded_and_never_requested(): void
    {
        $transport = $this->site([self::SITE.'/in' => FakeCrawlTransport::html('<title>in</title>')]);

        $run = $this->start(['mode' => 'list', 'urls' => [self::SITE.'/in', 'https://other.test/out', 'not a url']]);
        $this->drain($run);

        $out = CrawlUrl::query()->where('crawl_run_id', $run->id)->where('normalized_url', 'https://other.test/out')->sole();
        $this->assertSame('skipped', $out->state);
        $this->assertSame('out_of_scope', $out->error_code);
        $this->assertNotContains('https://other.test/out', $transport->requests);
    }
}
