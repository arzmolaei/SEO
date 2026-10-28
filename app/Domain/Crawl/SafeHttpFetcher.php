<?php

namespace App\Domain\Crawl;

use App\Domain\Pages\NormalizePageUrl;
use InvalidArgumentException;

/**
 * Fetches a URL for the crawler. Every hop (the request and each redirect)
 * is validated by the target policy and pinned to its checked addresses, so
 * neither a redirect nor DNS can steer a request to an internal service.
 */
final class SafeHttpFetcher
{
    public const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly CrawlTargetPolicy $policy,
        private readonly CrawlTransport $transport,
        private readonly int $maxBytes = 2_097_152,
    ) {}

    public function fetch(string $url): FetchResult
    {
        $current = $url;
        $redirects = [];
        $seen = [];

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $addresses = $this->policy->connectableAddresses($current);
                $response = $this->transport->get($current, $addresses, $this->maxBytes);
            } catch (CrawlRefusal $refusal) {
                return new FetchResult($url, $current, $redirects, null, $refusal->reason, self::isTransient($refusal->reason));
            }

            $location = $response->header('location');
            if ($response->status < 300 || $response->status >= 400 || $response->status === 304 || $location === null) {
                return new FetchResult($url, $current, $redirects, $response, null, $response->status === 429 || $response->status >= 500);
            }

            $next = UrlResolver::resolve($current, $location);
            try {
                $next = $next === null ? null : (new NormalizePageUrl)->normalize($next);
            } catch (InvalidArgumentException) {
                $next = null;
            }
            if ($next === null) {
                return new FetchResult($url, $current, $redirects, $response, 'redirect_invalid');
            }
            $redirects[] = $next;
            if (isset($seen[$next]) || $next === $current) {
                return new FetchResult($url, $current, $redirects, $response, 'redirect_loop');
            }
            $seen[$current] = true;
            if (! $this->policy->isInScope($next)) {
                // Recorded, never followed: the crawler only contacts the site itself.
                return new FetchResult($url, $next, $redirects, $response, 'redirect_out_of_scope');
            }
            $current = $next;
        }

        return new FetchResult($url, $current, $redirects, null, 'too_many_redirects');
    }

    private static function isTransient(string $code): bool
    {
        return in_array($code, ['timeout', 'connect_failed', 'dns_failed'], true);
    }
}
