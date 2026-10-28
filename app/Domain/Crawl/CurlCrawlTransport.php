<?php

namespace App\Domain\Crawl;

/**
 * Real HTTP transport. Connections are pinned to pre-checked addresses
 * (CURLOPT_RESOLVE / CONNECT_TO), redirects are never followed by curl, no
 * cookies are kept, and the body is aborted once it exceeds the bound.
 */
final class CurlCrawlTransport implements CrawlTransport
{
    public const USER_AGENT = 'SEO-OS-Crawler/1 (+self-hosted)';

    public function __construct(
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $timeoutSeconds = 20,
    ) {}

    public function get(string $url, array $addresses, int $maxBytes): TransportResponse
    {
        if (! function_exists('curl_init')) {
            throw new CrawlRefusal('transport_unavailable');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $addresses === []) {
            throw new CrawlRefusal('invalid_url');
        }
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        $pins = array_map(
            static fn (string $address): string => $host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address),
            $addresses,
        );

        $headers = [];
        $body = '';
        $tooLarge = false;
        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => $pins,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.5'],
            CURLOPT_COOKIEFILE => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))][] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $started = hrtime(true);
        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($tooLarge) {
            throw new CrawlRefusal('body_too_large');
        }
        if ($ok === false || $status === 0) {
            throw new CrawlRefusal(match ($errno) {
                CURLE_OPERATION_TIMEDOUT => 'timeout',
                CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CACERT_BADFILE => 'tls_failed',
                default => 'connect_failed',
            });
        }

        return new TransportResponse($status, $headers, $body, $elapsedMs);
    }
}
