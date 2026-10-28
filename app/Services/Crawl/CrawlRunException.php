<?php

namespace App\Services\Crawl;

use RuntimeException;

final class CrawlRunException extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message)
    {
        parent::__construct($message);
    }
}
