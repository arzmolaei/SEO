<?php

namespace App\Domain\Crawl;

use XMLReader;

/**
 * Reads a sitemap or sitemap index (optionally gzip) into bounded location
 * lists. External entities and network access are never enabled.
 */
final class SitemapReader
{
    public const MAX_INFLATED_BYTES = 10_485_760;

    public const MAX_ENTRIES = 50_000;

    /** @return array{type: 'urlset'|'index'|'invalid', locations: list<string>, truncated: bool} */
    public static function read(string $body): array
    {
        if (str_starts_with($body, "\x1f\x8b")) {
            $inflated = @gzdecode($body, self::MAX_INFLATED_BYTES + 1);
            if (! is_string($inflated) || strlen($inflated) > self::MAX_INFLATED_BYTES) {
                return ['type' => 'invalid', 'locations' => [], 'truncated' => false];
            }
            $body = $inflated;
        }
        if ($body === '' || strlen($body) > self::MAX_INFLATED_BYTES || preg_match('/<!DOCTYPE|<!ENTITY/i', $body) === 1) {
            return ['type' => 'invalid', 'locations' => [], 'truncated' => false];
        }

        $reader = new XMLReader;
        if (! @$reader->XML($body, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return ['type' => 'invalid', 'locations' => [], 'truncated' => false];
        }

        $type = null;
        $locations = [];
        $truncated = false;
        $inEntry = false;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    if ($reader->nodeType === XMLReader::END_ELEMENT && in_array($reader->localName, ['url', 'sitemap'], true)) {
                        $inEntry = false;
                    }

                    continue;
                }
                $name = $reader->localName;
                if ($type === null) {
                    $type = match ($name) {
                        'urlset' => 'urlset',
                        'sitemapindex' => 'index',
                        default => 'invalid',
                    };
                    if ($type === 'invalid') {
                        break;
                    }

                    continue;
                }
                if ($name === ($type === 'urlset' ? 'url' : 'sitemap')) {
                    $inEntry = true;
                } elseif ($inEntry && $name === 'loc') {
                    if (count($locations) >= self::MAX_ENTRIES) {
                        $truncated = true;
                        break;
                    }
                    $location = trim($reader->readString());
                    if ($location !== '') {
                        $locations[] = $location;
                    }
                }
            }
            // A malformed document is rejected as a whole, never half-trusted.
            if (libxml_get_errors() !== []) {
                $type = 'invalid';
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $reader->close();
        }

        return ['type' => $type ?? 'invalid', 'locations' => $type === null || $type === 'invalid' ? [] : $locations, 'truncated' => $truncated];
    }
}
