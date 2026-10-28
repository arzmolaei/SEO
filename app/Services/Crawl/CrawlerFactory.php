<?php

namespace App\Services\Crawl;

use App\Domain\Crawl\CrawlTargetPolicy;
use App\Domain\Crawl\CrawlTransport;
use App\Domain\Crawl\SafeHttpFetcher;
use App\Models\CrawlRun;
use Closure;

/** Builds the network-facing pieces for one run from its frozen scope. */
class CrawlerFactory
{
    /** @param  (Closure(string): list<string>)|null  $resolver */
    public function __construct(
        private readonly CrawlTransport $transport,
        private readonly ?Closure $resolver = null,
    ) {}

    public function policy(CrawlRun $run): CrawlTargetPolicy
    {
        return new CrawlTargetPolicy($run->allowed_hosts, (bool) config('crawler.allow_private_networks'), $this->resolver);
    }

    public function fetcher(CrawlRun $run, int $maxBytes = 2_097_152): SafeHttpFetcher
    {
        return new SafeHttpFetcher($this->policy($run), $this->transport, $maxBytes);
    }
}
