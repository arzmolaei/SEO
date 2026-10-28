<?php

// Separate-process connected acceptance helper. Never included in runtime packages.
use App\Models\Project;
use App\Models\User;
use App\Services\Crawl\CrawlerFactory;
use App\Services\Crawl\CrawlRunException;
use App\Services\Crawl\CrawlRunProcessor;
use App\Services\Crawl\StartCrawlRun;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeCrawlTransport;

require __DIR__.'/../bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.crawl_connected' => [
    'driver' => 'mariadb',
    'host' => getenv('BACKUP_SOURCE_DB_HOST'), 'port' => getenv('BACKUP_SOURCE_DB_PORT'),
    'database' => getenv('BACKUP_SOURCE_DB_DATABASE'), 'username' => getenv('BACKUP_SOURCE_DB_USERNAME'),
    'password' => getenv('BACKUP_SOURCE_DB_PASSWORD'), 'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
], 'crawler.default_delay_ms' => 0]);
DB::setDefaultConnection('crawl_connected');
Queue::fake();
$directory = $argv[3];
if ($argv[1] === 'start') {
    touch($directory.'/ready-'.getmypid());
    $deadline = microtime(true) + 15;
    while (! is_file($directory.'/start')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Start barrier timed out.');
        }
        usleep(10_000);
    }
    try {
        $run = app(StartCrawlRun::class)->handle(Project::query()->findOrFail((int) $argv[2]), User::query()->findOrFail((int) $argv[4]), ['mode' => 'site', 'start_url' => 'https://example.test/']);
        echo json_encode(['status' => $run->status], JSON_THROW_ON_ERROR);
    } catch (CrawlRunException $exception) {
        echo json_encode(['status' => $exception->codeName], JSON_THROW_ON_ERROR);
    }
    exit;
}
$record = static function (string $path) use ($directory): void {
    file_put_contents($directory.'/requests', $path."\n", FILE_APPEND | LOCK_EX);
};
$transport = new FakeCrawlTransport([
    'https://example.test/' => static function () use ($directory, $record) {
        $record('/');
        touch($directory.'/entered');
        $deadline = microtime(true) + 15;
        while (is_file($directory.'/hold') && ! is_file($directory.'/release')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Request barrier timed out.');
            }
            usleep(10_000);
        }

        return FakeCrawlTransport::html('<title>خانه</title>');
    },
    'https://example.test/second' => static function () use ($record) {
        $record('/second');

        return FakeCrawlTransport::html('<title>دوم</title>');
    },
]);
$app->instance(CrawlerFactory::class, new CrawlerFactory($transport, static fn (): array => ['93.184.216.34']));
echo json_encode(['next' => app(CrawlRunProcessor::class)->processBatch((int) $argv[2])], JSON_THROW_ON_ERROR);
