<?php

namespace App\Domain\Crawl;

use RuntimeException;

/** A URL the crawler must not fetch; the code is stable and safe to show. */
final class CrawlRefusal extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
