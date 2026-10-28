<?php

namespace Tests\Support;

use App\Domain\Crawl\CrawlRefusal;
use App\Domain\Crawl\CrawlTransport;
use App\Domain\Crawl\TransportResponse;
use Closure;

/** In-memory site for crawler tests; records every request it receives. */
final class FakeCrawlTransport implements CrawlTransport
{
    /** @var list<string> */
    public array $requests = [];

    /** @param  array<string, TransportResponse|string|Closure(): (TransportResponse|string)>  $routes  string = refusal code */
    public function __construct(private array $routes = []) {}

    public static function html(string $body, int $status = 200, array $headers = []): TransportResponse
    {
        return new TransportResponse($status, ['content-type' => ['text/html; charset=UTF-8']] + $headers, $body, 5);
    }

    public static function text(string $body, int $status = 200, string $type = 'text/plain'): TransportResponse
    {
        return new TransportResponse($status, ['content-type' => [$type]], $body, 5);
    }

    public static function redirect(string $location, int $status = 301): TransportResponse
    {
        return new TransportResponse($status, ['location' => [$location]], '', 5);
    }

    public function get(string $url, array $addresses, int $maxBytes): TransportResponse
    {
        $this->requests[] = $url;
        $route = $this->routes[$url] ?? self::text('missing', 404);
        if ($route instanceof Closure) {
            $route = $route();
        }
        if (is_string($route)) {
            throw new CrawlRefusal($route);
        }

        return $route;
    }
}
