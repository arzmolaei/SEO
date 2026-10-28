<?php

namespace App\Services\Crawl;

use App\Domain\Crawl\CrawlRefusal;
use App\Domain\Crawl\CrawlTargetPolicy;
use App\Domain\Crawl\FetchResult;
use App\Domain\Crawl\HtmlExtractor;
use App\Domain\Crawl\RobotsTxt;
use App\Domain\Crawl\SafeHttpFetcher;
use App\Domain\Crawl\SitemapReader;
use App\Domain\Pages\NormalizePageUrl;
use App\Models\CrawlRun;
use App\Models\CrawlUrl;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Processes one bounded batch of a run. Every URL row is claimed with a
 * guarded update, so concurrent or restarted workers never fetch the same
 * URL twice; all progress is persisted per URL, so a killed worker resumes
 * from the frontier.
 */
class CrawlRunProcessor
{
    private const CLAIM_STALE_SECONDS = 300;

    /** @var array<string, list<string>> `Sitemap:` lines from each host's robots.txt */
    private array $sitemapHints = [];

    public function __construct(
        private readonly CrawlerFactory $factory,
        private readonly CrawlPageLinker $linker,
    ) {}

    /** @return 'more'|'later'|'done' */
    public function processBatch(int $runId): string
    {
        // Like the instance lifecycle lock, this lease lives on shared local
        // storage and is released by the OS if a worker dies. Never unlink it:
        // replacing an inode would let two workers own different locks.
        $directory = storage_path('app/crawl/locks');
        if (is_link($directory) || (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory))) {
            throw new RuntimeException('Crawl worker lock is unavailable.');
        }
        $path = $directory.'/run-'.$runId.'.lock';
        if (is_link($path)) {
            throw new RuntimeException('Crawl worker lock is unavailable.');
        }
        $lease = fopen($path, 'c+');
        if ($lease === false) {
            throw new RuntimeException('Crawl worker lock is unavailable.');
        }
        chmod($path, 0600);
        if (! flock($lease, LOCK_EX | LOCK_NB)) {
            fclose($lease);

            return 'later';
        }
        try {
            return $this->processOwnedBatch($runId);
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }

    /** @return 'more'|'later'|'done' */
    private function processOwnedBatch(int $runId): string
    {
        $run = CrawlRun::query()->find($runId);
        if ($run === null || ! $run->isActive()) {
            return 'done';
        }
        if ($run->cancel_requested_at !== null) {
            CrawlRunControls::finishCancelled($run);

            return 'done';
        }
        if ($run->pause_requested_at !== null) {
            $run->forceFill(['status' => 'paused'])->save();

            return 'done';
        }
        if ($run->status === 'paused') {
            return 'done';
        }
        if ($run->status === 'queued') {
            $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();
        }

        $policy = $this->factory->policy($run);
        $fetcher = $this->factory->fetcher($run);
        $robots = $this->robots($run);
        if (! ($run->settings['sitemaps_read'] ?? false) && $run->mode !== 'list') {
            if (! $this->discoverSitemaps($run, $policy)) {
                $this->refreshCounters($run);

                return 'more';
            }
        }

        $deadline = microtime(true) + (int) config('crawler.batch_seconds');
        $limit = (int) config('crawler.batch_size');
        $delayMs = $run->settings['delay_ms'];
        foreach ($robots as $rules) {
            $delayMs = max($delayMs, ($rules->crawlDelaySeconds() ?? 0) * 1000);
        }

        $processed = 0;
        while ($processed < $limit && microtime(true) < $deadline) {
            $run->refresh();
            if ($run->cancel_requested_at !== null || $run->pause_requested_at !== null) {
                break;
            }
            $row = $this->claimNext($run);
            if ($row === null) {
                break;
            }
            $this->processUrl($run, $row, $policy, $fetcher, $robots);
            $processed++;
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $this->refreshCounters($run);
        $run->refresh();
        if ($run->cancel_requested_at !== null) {
            CrawlRunControls::finishCancelled($run);

            return 'done';
        }
        if ($run->pause_requested_at !== null) {
            $run->forceFill(['status' => 'paused'])->save();

            return 'done';
        }

        $pending = CrawlUrl::query()->where('crawl_run_id', $run->id)->where('state', 'pending');
        if (! (clone $pending)->exists()) {
            if (! $this->linker->link($run)) {
                return 'more';
            }
            $run->forceFill(['status' => 'completed', 'finished_at' => now()])->save();

            return 'done';
        }

        return (clone $pending)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('claim_token')->orWhere('claimed_at', '<', now()->subSeconds(self::CLAIM_STALE_SECONDS)))
            ->exists()
            ? 'more'
            : 'later';
    }

    private function claimNext(CrawlRun $run): ?CrawlUrl
    {
        $staleBefore = now()->subSeconds(self::CLAIM_STALE_SECONDS);
        $candidates = CrawlUrl::query()
            ->where('crawl_run_id', $run->id)
            ->where('state', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('claim_token')->orWhere('claimed_at', '<', $staleBefore))
            ->orderBy('depth')->orderBy('id')
            ->limit(5)
            ->pluck('id');

        foreach ($candidates as $id) {
            $token = bin2hex(random_bytes(16));
            $claimed = CrawlUrl::query()
                ->whereKey($id)
                ->where('state', 'pending')
                ->where(fn ($q) => $q->whereNull('claim_token')->orWhere('claimed_at', '<', $staleBefore))
                ->update(['claim_token' => $token, 'claimed_at' => now(), 'updated_at' => now()]);
            if ($claimed === 1) {
                return CrawlUrl::query()->whereKey($id)->where('claim_token', $token)->first();
            }
        }

        return null;
    }

    /** @param  array<string, RobotsTxt>  $robots */
    private function processUrl(CrawlRun $run, CrawlUrl $row, CrawlTargetPolicy $policy, SafeHttpFetcher $fetcher, array $robots): void
    {
        $path = (string) parse_url($row->normalized_url, PHP_URL_PATH);
        $query = parse_url($row->normalized_url, PHP_URL_QUERY);
        $pathAndQuery = ($path === '' ? '/' : $path).(is_string($query) ? '?'.$query : '');

        if (! $policy->isInScope($row->normalized_url)) {
            $this->finish($row, ['state' => 'skipped', 'error_code' => 'out_of_scope']);

            return;
        }
        if (! $this->matchesPrefixes($run, $path === '' ? '/' : $path)) {
            $this->finish($row, ['state' => 'skipped', 'error_code' => 'excluded_by_settings']);

            return;
        }
        try {
            $policy->connectableAddresses($row->normalized_url);
        } catch (CrawlRefusal $refusal) {
            if (! in_array($refusal->reason, ['dns_failed', 'connect_failed', 'timeout'], true)) {
                $this->finish($row, ['state' => 'failed', 'error_code' => $refusal->reason]);

                return;
            }
        }
        $hostRobots = $robots[CrawlTargetPolicy::authority($row->normalized_url)] ?? RobotsTxt::allowAll();
        if (! $hostRobots->allows($pathAndQuery)) {
            $this->finish($row, ['state' => 'blocked', 'error_code' => 'blocked_by_robots']);

            return;
        }

        $result = $fetcher->fetch($row->normalized_url);
        if ($result->transient && $row->attempts + 1 < (int) config('crawler.max_attempts')) {
            $row->forceFill([
                'attempts' => $row->attempts + 1,
                'next_attempt_at' => now()->addSeconds(10 * (2 ** $row->attempts)),
                'claim_token' => null,
                'error_code' => $result->errorCode ?? 'http_'.($result->response->status ?? 0),
            ])->save();

            return;
        }

        $this->record($run, $row, $result, $policy);
    }

    private function record(CrawlRun $run, CrawlUrl $row, FetchResult $result, CrawlTargetPolicy $policy): void
    {
        $response = $result->response;
        $fields = [
            'attempts' => $row->attempts + 1,
            'status_code' => $response?->status,
            'final_url' => $result->finalUrl !== $row->normalized_url ? mb_substr($result->finalUrl, 0, 8192) : null,
            'redirect_count' => count($result->redirects),
            'content_type' => $response?->header('content-type') === null ? null : mb_substr((string) $response->header('content-type'), 0, 128),
            'response_ms' => $response?->elapsedMs,
            'bytes' => $response === null ? null : strlen($response->body),
            'error_code' => $result->errorCode,
            'fetched_at' => now(),
            'state' => $response === null ? 'failed' : 'fetched',
        ];

        if ($result->errorCode === null && $result->isHtml() && $response !== null) {
            $page = HtmlExtractor::extract(
                $response->body,
                $result->finalUrl,
                $response->header('content-type'),
                $response->header('x-robots-tag'),
                $policy->isInScope(...),
            );
            $fields += [
                'title' => $page['title'],
                'meta_description' => $page['meta_description'],
                'robots' => $page['robots'],
                'canonical' => $page['canonical'],
                'h1' => $page['h1'],
                'h2' => $page['h2'],
                'hreflang' => $page['hreflang'],
                'schema_types' => $page['schema_types'],
                'word_count' => $page['word_count'],
                'images' => $page['images'],
                'images_missing_alt' => $page['images_missing_alt'],
                'internal_links' => $page['internal_links'],
                'external_links' => $page['external_links'],
                'indexable' => $response->status === 200 && ! $page['noindex']
                    && ($page['canonical'] === null || $page['canonical'] === $result->finalUrl),
            ];
            if ($run->mode === 'site' && ! $page['nofollow'] && $row->depth < $run->settings['max_depth']) {
                $this->enqueue($run, $page['internal_urls'], $row->depth + 1, 'link');
            }
        } elseif ($response !== null) {
            $fields['indexable'] = false;
        }
        // A redirect inside the site is itself a discovered URL to crawl.
        if ($run->mode === 'site' && $result->redirects !== [] && $result->errorCode === null && $policy->isInScope($result->finalUrl)) {
            $this->enqueue($run, [$result->finalUrl], $row->depth, 'redirect');
        }

        $this->finish($row, $fields);
    }

    /** @param  array<string, mixed>  $fields */
    private function finish(CrawlUrl $row, array $fields): void
    {
        $row->forceFill($fields + ['claim_token' => null, 'next_attempt_at' => null])->save();
    }

    /** @param  list<string>  $urls */
    private function enqueue(CrawlRun $run, array $urls, int $depth, string $source): void
    {
        $remaining = $run->settings['max_urls'] - CrawlUrl::query()->where('crawl_run_id', $run->id)->count();
        if ($remaining <= 0 || $urls === []) {
            return;
        }
        $rows = [];
        foreach ($urls as $url) {
            if (count($rows) >= $remaining) {
                break;
            }
            try {
                $normalized = (new NormalizePageUrl)->normalize($url);
            } catch (InvalidArgumentException) {
                continue;
            }
            $hash = hash('sha256', $normalized);
            $rows[$hash] = [
                'crawl_run_id' => $run->id,
                'url' => $normalized,
                'normalized_url' => $normalized,
                'url_hash' => $hash,
                'depth' => $depth,
                'source' => $source,
                'state' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            CrawlUrl::query()->insertOrIgnore($chunk);
        }
    }

    private function matchesPrefixes(CrawlRun $run, string $path): bool
    {
        foreach ($run->settings['exclude'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }
        if ($run->settings['include'] === []) {
            return true;
        }
        foreach ($run->settings['include'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, RobotsTxt> keyed by host authority */
    private function robots(CrawlRun $run): array
    {
        $fetcher = $this->factory->fetcher($run, RobotsTxt::MAX_BYTES);
        $rules = [];
        foreach ($run->allowed_hosts as $authority) {
            $scheme = (string) parse_url($run->normalized_start_url, PHP_URL_SCHEME);
            $result = $fetcher->fetch($scheme.'://'.$authority.'/robots.txt');
            $status = $result->response?->status;
            // RFC 9309: 4xx means no restrictions; an unreachable robots.txt
            // (5xx/network) means the whole host is disallowed for now.
            $rules[$authority] = match (true) {
                $status !== null && $status >= 200 && $status < 300 && $result->errorCode === null => RobotsTxt::parse($result->response->body, 'SEO-OS-Crawler/1'),
                $status !== null && $status >= 400 && $status < 500 => RobotsTxt::allowAll(),
                default => RobotsTxt::parse("User-agent: *\nDisallow: /\n", 'SEO-OS-Crawler/1'),
            };
            if ($status !== null && $status >= 200 && $status < 300) {
                $this->sitemapHints[$authority] = self::sitemapLines($result->response->body);
            }
        }

        return $rules;
    }

    /** @return list<string> */
    private static function sitemapLines(string $body): array
    {
        preg_match_all('/^\s*sitemap\s*:\s*(\S+)/mi', substr($body, 0, RobotsTxt::MAX_BYTES), $matches);

        return array_slice($matches[1], 0, 50);
    }

    /** Process at most one sitemap per job; retain the frontier across restarts. */
    private function discoverSitemaps(CrawlRun $run, CrawlTargetPolicy $policy): bool
    {
        $fetcher = $this->factory->fetcher($run, 10_485_760);
        $scheme = (string) parse_url($run->normalized_start_url, PHP_URL_SCHEME);
        $queue = $run->settings['sitemap_queue'] ?? [];
        if (! isset($run->settings['sitemap_seen'])) {
            foreach ($run->allowed_hosts as $authority) {
                $queue = [...$queue, ...($this->sitemapHints[$authority] ?? []), $scheme.'://'.$authority.'/sitemap.xml'];
            }
        }
        $seen = array_fill_keys($run->settings['sitemap_seen'] ?? [], true);
        $files = count($seen);
        $limit = (int) config('crawler.max_sitemap_files');
        while ($queue !== [] && $files < $limit) {
            $location = array_shift($queue);
            try {
                $location = (new NormalizePageUrl)->normalize($location);
            } catch (InvalidArgumentException) {
                continue;
            }
            if (isset($seen[$location]) || ! $policy->isInScope($location)) {
                continue;
            }
            $seen[$location] = true;
            $files++;
            $result = $fetcher->fetch($location);
            $sitemap = $result->errorCode === null && $result->response?->status === 200
                ? SitemapReader::read($result->response->body)
                : ['type' => 'invalid', 'locations' => []];
            if ($sitemap['type'] === 'index') {
                $queue = array_slice(array_values(array_unique([...$queue, ...$sitemap['locations']])), 0, $limit);
            } elseif ($sitemap['type'] === 'urlset') {
                $inScope = array_values(array_filter($sitemap['locations'], function (string $url) use ($policy): bool {
                    try {
                        return $policy->isInScope((new NormalizePageUrl)->normalize($url));
                    } catch (InvalidArgumentException) {
                        return false;
                    }
                }));
                $this->enqueue($run, $inScope, $run->mode === 'site' ? 1 : 0, 'sitemap');
            }
            break;
        }

        $complete = $queue === [] || $files >= $limit;
        DB::transaction(function () use ($run, $queue, $seen, $complete): void {
            $locked = CrawlRun::query()->lockForUpdate()->findOrFail($run->id);
            $locked->forceFill(['settings' => [...$locked->settings,
                'sitemaps_read' => $complete,
                'sitemap_queue' => $complete ? [] : $queue,
                'sitemap_seen' => array_keys($seen),
            ]])->save();
        });
        $run->refresh();

        return $complete;
    }

    private function refreshCounters(CrawlRun $run): void
    {
        $counts = CrawlUrl::query()->where('crawl_run_id', $run->id)
            ->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');
        $run->forceFill([
            'discovered_count' => (int) $counts->sum(),
            'fetched_count' => (int) ($counts['fetched'] ?? 0),
            'failed_count' => (int) ($counts['failed'] ?? 0),
            'blocked_count' => (int) ($counts['blocked'] ?? 0),
        ])->save();
    }
}
