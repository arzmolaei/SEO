<?php

namespace Tests\Feature\Acceptance;

use App\Enums\WorkspaceRole;
use App\Models\CrawlRun;
use App\Models\CrawlUrl;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Crawl\StartCrawlRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CrawlRunMariaDbAcceptanceTest extends TestCase
{
    private string $directory;

    private User $owner;

    private Project $project;

    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('BACKUP_RESTORE_CONNECTED') !== '1') {
            $this->markTestSkipped('Crawl worker concurrency requires the connected MariaDB gate.');
        }
        $this->directory = storage_path('framework/testing/crawl-connected-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->directory, 0700);
        config(['database.connections.crawl_connected' => [
            'driver' => 'mariadb',
            'host' => getenv('BACKUP_SOURCE_DB_HOST'), 'port' => getenv('BACKUP_SOURCE_DB_PORT'),
            'database' => getenv('BACKUP_SOURCE_DB_DATABASE'), 'username' => getenv('BACKUP_SOURCE_DB_USERNAME'),
            'password' => getenv('BACKUP_SOURCE_DB_PASSWORD'), 'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]]);
        DB::setDefaultConnection('crawl_connected');
        $this->assertStringContainsString('10.11.', (string) DB::selectOne('SELECT VERSION() AS version')->version);
        Artisan::call('migrate:fresh', ['--database' => 'crawl_connected', '--force' => true]);
        Queue::fake();
        $this->owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'همزمانی خزش']);
        Membership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Owner]);
        $this->project = Project::query()->create(['workspace_id' => $workspace->id, 'name' => 'سایت']);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop(0, 9);
        }
        if (isset($this->directory)) {
            DB::connection('crawl_connected')->getSchemaBuilder()->dropAllTables();
            DB::purge('crawl_connected');
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function createRun(): CrawlRun
    {
        return app(StartCrawlRun::class)->handle($this->project, $this->owner, [
            'mode' => 'list', 'start_url' => 'https://example.test/',
            'urls' => ['https://example.test/', 'https://example.test/second'],
        ]);
    }

    private function worker(string $action, int $id): Process
    {
        $worker = new Process([PHP_BINARY, base_path('tests/Support/crawl-run-worker.php'), $action, (string) $id, $this->directory, (string) $this->owner->id], base_path(), ['APP_ENV' => 'testing', 'INSTALLER_ASSUME_INSTALLED' => 'true'], timeout: 25);
        $worker->start();
        $this->workers[] = $worker;

        return $worker;
    }

    /** @param callable(): bool $condition */
    private function await(callable $condition): void
    {
        $deadline = microtime(true) + 15;
        while (! $condition()) {
            if (microtime(true) >= $deadline) {
                $this->fail('Separate-process crawl barrier timed out.');
            }
            usleep(10_000);
        }
    }

    /** @return array<string, string> */
    private function workerResult(Process $worker): array
    {
        $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());

        return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_two_processes_cannot_fetch_in_parallel_or_complete_an_owned_claim(): void
    {
        $run = $this->createRun();
        touch($this->directory.'/hold');
        $first = $this->worker('process', $run->id);
        $this->await(fn (): bool => is_file($this->directory.'/entered'));
        $second = $this->worker('process', $run->id);
        $this->assertSame('later', $this->workerResult($second)['next']);
        $this->assertSame('running', $run->refresh()->status);
        $this->assertSame(2, $run->urls()->where('state', 'pending')->count());
        $this->assertNotNull($run->urls()->whereNotNull('claim_token')->first());
        $this->assertSame("/\n", File::get($this->directory.'/requests'));
        touch($this->directory.'/release');
        $this->assertSame('done', $this->workerResult($first)['next']);
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame(2, $run->fetched_count);
        $this->assertSame(2, $run->urls()->whereNotNull('page_id')->count());
        $this->assertSame("/\n/second\n", File::get($this->directory.'/requests'));
    }

    public function test_a_killed_process_releases_ownership_and_reclaims_only_unfinished_snapshots(): void
    {
        $run = $this->createRun();
        touch($this->directory.'/hold');
        $first = $this->worker('process', $run->id);
        $this->await(fn (): bool => is_file($this->directory.'/entered'));
        $first->stop(0, 9);
        $this->assertSame(1, $run->urls()->whereNotNull('claim_token')->count());
        CrawlUrl::query()->where('crawl_run_id', $run->id)->whereNotNull('claim_token')->update(['claimed_at' => now()->subMinutes(10)]);
        touch($this->directory.'/release');
        $this->assertSame('done', $this->workerResult($this->worker('process', $run->id))['next']);
        $this->assertSame(2, $run->urls()->count());
        $this->assertSame(2, $run->refresh()->fetched_count);
        $this->assertSame(0, $run->urls()->whereNotNull('claim_token')->count());
        // The unfinished GET may repeat after a hard kill; completed snapshots do not.
        $this->assertSame("/\n/\n/second\n", File::get($this->directory.'/requests'));
    }

    public function test_concurrent_starts_create_exactly_one_active_run_per_project(): void
    {
        $first = $this->worker('start', $this->project->id);
        $second = $this->worker('start', $this->project->id);
        $this->await(fn (): bool => count(File::glob($this->directory.'/ready-*')) === 2);
        touch($this->directory.'/start');
        $results = [$this->workerResult($first)['status'], $this->workerResult($second)['status']];
        sort($results);
        $this->assertSame(['crawl_already_active', 'queued'], $results);
        $this->assertSame(1, CrawlRun::query()->where('project_id', $this->project->id)->count());
        $this->assertSame(1, DB::table('activity_events')->where('event', 'crawl.started')->count());
    }
}
