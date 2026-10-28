<?php

namespace App\Domain\Crawl;

interface CrawlTransport
{
    /**
     * One GET without following redirects, connected only to $addresses.
     *
     * @param  list<string>  $addresses
     *
     * @throws CrawlRefusal on timeouts, connection or size failures
     */
    public function get(string $url, array $addresses, int $maxBytes): TransportResponse;
}
