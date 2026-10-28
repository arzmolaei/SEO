<?php

namespace App\Domain\Crawl;

final class TransportResponse
{
    /** @param  array<string, list<string>>  $headers  lower-case names */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly int $elapsedMs,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
