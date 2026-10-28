<?php

namespace Tests\Unit\Crawl;

use App\Domain\Crawl\CrawlTargetPolicy;
use App\Domain\Crawl\CurlCrawlTransport;
use App\Domain\Crawl\SafeHttpFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Real sockets against a disposable local fixture site. */
class SafeHttpFetcherTest extends TestCase
{
    private static Process $server;

    private static int $port;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        self::$server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.self::$port, dirname(__DIR__, 2).'/Fixtures/crawl-site/router.php']);
        self::$server->start();
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && @fsockopen('127.0.0.1', self::$port) === false) {
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop(1);
    }

    /** The fixture host name never resolves: only the pinned address can reach it. */
    private function fetcher(bool $allowPrivate = true, int $timeout = 20, int $maxBytes = 2_097_152): SafeHttpFetcher
    {
        $policy = new CrawlTargetPolicy(['site.test:'.self::$port], $allowPrivate, static fn (): array => ['127.0.0.1']);

        return new SafeHttpFetcher($policy, new CurlCrawlTransport(2, $timeout), $maxBytes);
    }

    private function url(string $path): string
    {
        return 'http://site.test:'.self::$port.$path;
    }

    public function test_html_is_fetched_through_the_pinned_address(): void
    {
        $result = $this->fetcher()->fetch($this->url('/ok'));

        $this->assertNull($result->errorCode);
        $this->assertSame(200, $result->response?->status);
        $this->assertTrue($result->isHtml());
        $this->assertStringContainsString('<title>سلام</title>', $result->response->body);
    }

    public function test_the_crawler_identifies_itself_and_sends_no_cookies(): void
    {
        $result = $this->fetcher()->fetch($this->url('/agent'));

        $this->assertSame(CurlCrawlTransport::USER_AGENT.'|', $result->response?->body);
    }

    public function test_in_scope_redirects_are_followed_and_recorded(): void
    {
        $result = $this->fetcher()->fetch($this->url('/redirect'));

        $this->assertNull($result->errorCode);
        $this->assertSame([$this->url('/ok')], $result->redirects);
        $this->assertSame($this->url('/ok'), $result->finalUrl);
        $this->assertSame(200, $result->response?->status);
    }

    public function test_redirects_leaving_the_site_are_recorded_but_never_followed(): void
    {
        $result = $this->fetcher()->fetch($this->url('/redirect-external'));

        $this->assertSame('redirect_out_of_scope', $result->errorCode);
        $this->assertSame('https://elsewhere.test/', $result->finalUrl);
        $this->assertSame(302, $result->response?->status);
    }

    public function test_redirect_loops_and_long_chains_stop(): void
    {
        $this->assertSame('redirect_loop', $this->fetcher()->fetch($this->url('/redirect-loop'))->errorCode);
        $this->assertSame('too_many_redirects', $this->fetcher()->fetch($this->url('/redirect-chain'))->errorCode);
    }

    public function test_oversized_bodies_and_slow_responses_are_bounded(): void
    {
        $this->assertSame('body_too_large', $this->fetcher()->fetch($this->url('/big'))->errorCode);

        $slow = $this->fetcher(timeout: 1)->fetch($this->url('/slow'));
        $this->assertSame('timeout', $slow->errorCode);
        $this->assertTrue($slow->transient);
    }

    public function test_server_errors_are_transient_and_not_html(): void
    {
        $result = $this->fetcher()->fetch($this->url('/error'));

        $this->assertNull($result->errorCode);
        $this->assertSame(503, $result->response?->status);
        $this->assertTrue($result->transient);
        $this->assertFalse($result->isHtml());
    }

    public function test_a_site_resolving_to_a_private_address_is_never_contacted_by_default(): void
    {
        $result = $this->fetcher(allowPrivate: false)->fetch($this->url('/ok'));

        $this->assertSame('private_network', $result->errorCode);
        $this->assertNull($result->response);
    }
}
