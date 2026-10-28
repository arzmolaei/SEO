<?php

namespace Tests\Feature\Crawl;

use App\Enums\WorkspaceRole;
use App\Models\CrawlChange;
use App\Models\CrawlRun;
use App\Models\CrawlUrl;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Crawl\CrawlRunComparator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CrawlRunComparatorTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->project = $this->project('سایت');
    }

    private function project(string $name): Project
    {
        $workspace = Workspace::query()->create(['name' => $name]);
        Membership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Owner]);

        return Project::query()->create(['workspace_id' => $workspace->id, 'name' => $name]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $urls  path => attributes
     * @param  array<string, mixed>  $overrides
     */
    private function run(array $urls, array $overrides = [], ?Project $project = null): CrawlRun
    {
        $run = CrawlRun::query()->create($overrides + [
            'project_id' => ($project ?? $this->project)->id,
            'created_by_id' => $this->owner->id,
            'mode' => 'site',
            'start_url' => 'https://example.test/',
            'normalized_start_url' => 'https://example.test/',
            'allowed_hosts' => ['example.test'],
            'settings' => ['include' => [], 'exclude' => [], 'max_depth' => 10, 'max_urls' => 5000, 'delay_ms' => 0, 'include_www' => false],
            'status' => 'completed',
        ]);
        $rows = [];
        foreach ($urls as $path => $attributes) {
            $url = 'https://example.test'.$path;
            $rows[] = $attributes + [
                'crawl_run_id' => $run->id,
                'url' => $url,
                'normalized_url' => $url,
                'url_hash' => hash('sha256', $url),
                'depth' => 0,
                'source' => 'link',
                'state' => 'fetched',
                'status_code' => 200,
                'title' => null,
                'meta_description' => null,
                'canonical' => null,
                'indexable' => true,
                'internal_links' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            CrawlUrl::query()->insert($chunk);
        }

        return $run;
    }

    private function compare(CrawlRun $run): CrawlRun
    {
        $comparator = app(CrawlRunComparator::class);
        for ($i = 0; $i < 100; $i++) {
            if ($comparator->compare($run)) {
                return $run->refresh();
            }
            $run->refresh();
        }
        $this->fail('The comparison did not finish.');
    }

    /** @return array<string, list<string>> change type => paths */
    private function changes(CrawlRun $run): array
    {
        $grouped = [];
        foreach (CrawlChange::query()->where('crawl_run_id', $run->id)->orderBy('normalized_url')->get() as $change) {
            $grouped[$change->change_type][] = substr($change->normalized_url, strlen('https://example.test'));
        }
        ksort($grouped);

        return $grouped;
    }

    public function test_the_first_crawl_has_no_baseline(): void
    {
        $run = $this->compare($this->run(['/' => []]));

        $this->assertSame('no_baseline', $run->comparison_status);
        $this->assertSame(0, CrawlChange::query()->count());
    }

    public function test_every_change_type_is_detected_and_whitespace_only_text_is_not_a_change(): void
    {
        $baseline = $this->run([
            '/' => ['title' => 'خانه   سایت', 'meta_description' => 'توضیح'],
            '/a' => ['title' => 'قدیمی', 'canonical' => 'https://example.test/a'],
            '/b' => ['indexable' => true, 'internal_links' => 3],
            '/gone' => [],
            '/was-failing' => ['state' => 'failed', 'status_code' => null],
        ]);
        $current = $this->run([
            '/' => ['title' => ' خانه سایت ', 'meta_description' => 'توضیح'],
            '/a' => ['title' => 'جدید', 'canonical' => 'https://example.test/a-new', 'status_code' => 301],
            '/b' => ['indexable' => false, 'internal_links' => 5],
            '/new' => [],
            '/was-failing' => ['title' => 'اکنون پاسخ داد'],
            '/now-failing' => ['state' => 'failed', 'status_code' => null],
        ]);

        $current = $this->compare($current);

        $this->assertSame('compared', $current->comparison_status);
        $this->assertSame($baseline->id, $current->baseline_run_id);
        $this->assertSame([
            'added' => ['/new'],
            'canonical' => ['/a'],
            'indexability' => ['/b'],
            'internal_links' => ['/b'],
            'removed' => ['/gone'],
            'status' => ['/a'],
            'title' => ['/a'],
        ], $this->changes($current));
        $title = CrawlChange::query()->where('crawl_run_id', $current->id)->where('change_type', 'title')->sole();
        $this->assertSame(['value' => 'قدیمی'], $title->before);
        $this->assertSame(['value' => 'جدید'], $title->after);
        $this->assertSame(1, $current->change_counts['removed']);
        $this->assertTrue($current->change_counts['removed_authoritative']);
    }

    public function test_a_differently_scoped_crawl_is_never_compared(): void
    {
        $this->run(['/' => []]);
        $current = $this->compare($this->run(['/' => ['title' => 'x']], ['settings' => ['include' => [], 'exclude' => ['/blog'], 'max_depth' => 10, 'max_urls' => 5000, 'delay_ms' => 0, 'include_www' => false]]));

        $this->assertSame('scope_changed', $current->comparison_status);
        $this->assertSame([], $this->changes($current));
    }

    public function test_partial_baselines_and_other_projects_are_never_used(): void
    {
        $this->run(['/' => ['title' => 'لغو شده']], ['status' => 'cancelled']);
        $this->run(['/' => ['title' => 'پروژه دیگر']], [], $this->project('دیگر'));

        $current = $this->compare($this->run(['/' => ['title' => 'فعلی']]));

        $this->assertSame('no_baseline', $current->comparison_status);
    }

    public function test_a_capped_crawl_never_asserts_removed_urls(): void
    {
        $this->run(['/' => [], '/a' => [], '/b' => []]);
        $settings = ['include' => [], 'exclude' => [], 'max_depth' => 10, 'max_urls' => 2, 'delay_ms' => 0, 'include_www' => false];
        $current = $this->compare($this->run(['/' => [], '/a' => []], ['settings' => $settings]));

        $this->assertSame('scope_changed', $current->comparison_status, 'a different URL cap is a different scope');

        $baseline = $this->run(['/' => [], '/a' => [], '/b' => []], ['settings' => $settings]);
        $capped = $this->compare($this->run(['/' => [], '/c' => []], ['settings' => $settings]));
        $this->assertSame($baseline->id, $capped->baseline_run_id);
        $this->assertSame(['added' => ['/c']], $this->changes($capped));
        $this->assertFalse($capped->change_counts['removed_authoritative']);
    }

    public function test_large_runs_compare_in_resumable_batches(): void
    {
        $count = CrawlRunComparator::BATCH * 2 + 37;
        $before = [];
        $after = [];
        for ($i = 0; $i < $count; $i++) {
            $before['/p'.$i] = ['title' => 'قبل '.$i];
            $after['/p'.$i] = ['title' => 'بعد '.$i];
        }
        $this->run($before);
        $current = $this->run($after);

        $comparator = app(CrawlRunComparator::class);
        $this->assertFalse($comparator->compare($current), 'one call handles one bounded batch');
        $current->refresh();
        $this->assertSame('current', $current->comparison_phase);
        $this->assertNotNull($current->comparison_cursor);

        $current = $this->compare($current);
        $this->assertSame($count, CrawlChange::query()->where('crawl_run_id', $current->id)->where('change_type', 'title')->count());
        $this->assertSame(['title' => $count, 'removed_authoritative' => true], $current->change_counts);
    }

    public function test_the_crawl_page_lists_changes_server_side_and_only_within_the_project(): void
    {
        $this->run(['/' => ['title' => 'قدیم'], '/gone' => []]);
        $current = $this->compare($this->run(['/' => ['title' => 'جدید'], '/new' => []]));
        $base = "/projects/{$this->project->id}/crawls";

        $this->actingAs($this->owner)->get($base.'?run='.$current->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activeRun.comparison_status', 'compared')
                ->where('activeRun.change_counts.title', 1)
                ->has('changes.data', 3));

        $this->get($base.'?run='.$current->id.'&change_type=title')
            ->assertInertia(fn (Assert $page) => $page
                ->has('changes.data', 1)
                ->where('changes.data.0.change_type', 'title')
                ->where('changes.data.0.before.value', 'قدیم')
                ->where('changes.data.0.after.value', 'جدید'));

        $this->get($base.'?run='.$current->id.'&change_type=bogus')->assertSessionHasErrors('change_type');

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get($base.'?run='.$current->id)->assertForbidden();
        $other = $this->project('دیگر');
        $this->actingAs($this->owner)->get("/projects/{$other->id}/crawls?run={$current->id}")->assertNotFound();
    }
}
