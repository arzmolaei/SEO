<?php

namespace App\Domain\Crawl;

final class FetchResult
{
    /** @param  list<string>  $redirects  each hop's target, in order */
    public function __construct(
        public readonly string $requestedUrl,
        public readonly string $finalUrl,
        public readonly array $redirects,
        public readonly ?TransportResponse $response,
        public readonly ?string $errorCode,
        public readonly bool $transient = false,
    ) {}

    public function isHtml(): bool
    {
        $type = strtolower((string) $this->response?->header('content-type'));

        return $this->response !== null
            && ($this->response->status >= 200 && $this->response->status < 300)
            && (str_starts_with($type, 'text/html') || str_starts_with($type, 'application/xhtml+xml'));
    }
}
