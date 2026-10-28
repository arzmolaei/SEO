<?php

namespace App\Domain\Crawl;

use App\Domain\Pages\NormalizePageUrl;
use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use JsonException;

/**
 * Extracts the bounded SEO fields of master spec §16.4 from one HTML page.
 * Bodies are never stored; only these values are.
 */
final class HtmlExtractor
{
    private const MAX_TEXT = 1024;

    private const MAX_HEADING = 300;

    private const MAX_INTERNAL_LINKS = 1000;

    /**
     * @param  Closure(string): bool  $isInternal  receives a normalized URL
     * @return array{
     *   title: ?string, meta_description: ?string, robots: ?string, canonical: ?string,
     *   h1: list<string>, h2: list<string>, hreflang: list<array{lang: string, href: string}>,
     *   schema_types: list<string>, word_count: int, images: int, images_missing_alt: int,
     *   internal_links: int, external_links: int, internal_urls: list<string>, noindex: bool, nofollow: bool
     * }
     */
    public static function extract(string $html, string $url, ?string $contentType, ?string $xRobotsTag, Closure $isInternal): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.self::toUtf8($html, $contentType), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);

        $base = $url;
        $baseHref = self::attribute($xpath, '//base[@href]', 'href');
        if ($baseHref !== null) {
            $base = UrlResolver::resolve($url, $baseHref) ?? $url;
        }

        $robots = self::joinNonEmpty([
            self::metaContent($xpath, 'robots'),
            self::metaContent($xpath, 'seo-os-crawler'),
            $xRobotsTag,
        ]);
        $directives = $robots === null ? [] : array_map('trim', explode(',', strtolower($robots)));

        $canonicalHref = self::attribute($xpath, '//link[contains(concat(" ", translate(normalize-space(@rel), "CANONICAL", "canonical"), " "), " canonical ")][@href]', 'href');
        $canonical = $canonicalHref === null ? null : self::normalize(UrlResolver::resolve($base, $canonicalHref));

        $hreflang = [];
        foreach ($xpath->query('//link[@hreflang][@href]') ?: [] as $node) {
            if (! $node instanceof DOMElement || count($hreflang) >= 50) {
                continue;
            }
            $href = self::normalize(UrlResolver::resolve($base, $node->getAttribute('href')));
            if ($href !== null) {
                $hreflang[] = ['lang' => mb_substr(trim($node->getAttribute('hreflang')), 0, 35), 'href' => $href];
            }
        }

        $images = 0;
        $missingAlt = 0;
        foreach ($xpath->query('//img') ?: [] as $node) {
            $images++;
            if ($node instanceof DOMElement && ! $node->hasAttribute('alt')) {
                $missingAlt++;
            }
        }

        $internal = [];
        $internalCount = 0;
        $externalCount = 0;
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $target = self::normalize(UrlResolver::resolve($base, $node->getAttribute('href')));
            if ($target === null) {
                continue;
            }
            if ($isInternal($target)) {
                $internalCount++;
                if (count($internal) < self::MAX_INTERNAL_LINKS) {
                    $internal[$target] = true;
                }
            } else {
                $externalCount++;
            }
        }

        foreach ($xpath->query('//script|//style|//noscript|//template') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
        // Join text nodes with spaces so adjacent blocks never merge words.
        $parts = [];
        foreach ($xpath->query('//body//text()') ?: [] as $node) {
            $parts[] = $node->nodeValue;
        }
        $text = implode(' ', $parts);

        return [
            'title' => self::text($xpath->query('//title')?->item(0)?->textContent, self::MAX_TEXT),
            'meta_description' => self::text(self::metaContent($xpath, 'description'), self::MAX_TEXT),
            'robots' => self::text($robots, 255),
            'canonical' => $canonical,
            'h1' => self::texts($xpath, '//h1', 5),
            'h2' => self::texts($xpath, '//h2', 20),
            'hreflang' => $hreflang,
            'schema_types' => self::schemaTypes($html),
            'word_count' => preg_match_all('/[\p{L}\p{N}\p{M}][\p{L}\p{N}\p{M}\x{200C}\x{200D}\']*/u', $text),
            'images' => $images,
            'images_missing_alt' => $missingAlt,
            'internal_links' => $internalCount,
            'external_links' => $externalCount,
            'internal_urls' => array_keys($internal),
            'noindex' => in_array('noindex', $directives, true) || in_array('none', $directives, true),
            'nofollow' => in_array('nofollow', $directives, true) || in_array('none', $directives, true),
        ];
    }

    private static function toUtf8(string $html, ?string $contentType): string
    {
        $charset = null;
        if ($contentType !== null && preg_match('/charset\s*=\s*["\']?([\w.:-]+)/i', $contentType, $m) === 1) {
            $charset = $m[1];
        } elseif (preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w.:-]+)/i', substr($html, 0, 4096), $m) === 1) {
            $charset = $m[1];
        }
        $charset = $charset === null ? 'UTF-8' : strtoupper($charset);
        if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
            // iconv covers legacy Persian charsets (windows-1256) that
            // mbstring does not; unknown charsets fall back to scrubbed UTF-8.
            $converted = function_exists('iconv') ? @iconv($charset, 'UTF-8//IGNORE', $html) : false;
            if (! is_string($converted) && in_array($charset, array_map('strtoupper', mb_list_encodings()), true)) {
                $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
            }
            if (is_string($converted)) {
                return mb_scrub($converted, 'UTF-8');
            }
        }

        return mb_scrub($html, 'UTF-8');
    }

    private static function metaContent(DOMXPath $xpath, string $name): ?string
    {
        $upper = strtoupper($name);

        return self::attribute($xpath, sprintf('//meta[translate(@name, "%s", "%s")="%s"][@content]', $upper, $name, $name), 'content');
    }

    private static function attribute(DOMXPath $xpath, string $query, string $attribute): ?string
    {
        $node = $xpath->query($query)?->item(0);

        return $node instanceof DOMElement ? $node->getAttribute($attribute) : null;
    }

    /** @return list<string> */
    private static function texts(DOMXPath $xpath, string $query, int $limit): array
    {
        $values = [];
        foreach ($xpath->query($query) ?: [] as $node) {
            $text = self::text($node->textContent, self::MAX_HEADING);
            if ($text !== null) {
                $values[] = $text;
            }
            if (count($values) >= $limit) {
                break;
            }
        }

        return $values;
    }

    private static function text(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** @param  list<?string>  $values */
    private static function joinNonEmpty(array $values): ?string
    {
        $values = array_values(array_filter(array_map(static fn (?string $v): string => trim((string) $v), $values), static fn (string $v): bool => $v !== ''));

        return $values === [] ? null : implode(', ', $values);
    }

    private static function normalize(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        try {
            return (new NormalizePageUrl)->normalize($url);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return list<string> JSON-LD @type values only, never other content */
    private static function schemaTypes(string $html): array
    {
        $types = [];
        if (preg_match_all('#<script[^>]+type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches) === false) {
            return [];
        }
        foreach ($matches[1] as $json) {
            try {
                $data = json_decode(trim($json), true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (! is_array($data)) {
                continue;
            }
            array_walk_recursive($data, static function ($value, $key) use (&$types): void {
                if ($key === '@type' && is_string($value) && count($types) < 20) {
                    $types[mb_substr($value, 0, 100)] = true;
                }
            });
            self::collectTypeLists($data, $types);
        }

        return array_slice(array_keys($types), 0, 20);
    }

    /**
     * `@type` may be a list (["Product", "Thing"]); array_walk_recursive only
     * visits scalar leaves, so lists are collected separately.
     *
     * @param  array<mixed>  $data
     * @param  array<string, true>  $types
     */
    private static function collectTypeLists(array $data, array &$types): void
    {
        foreach ($data as $key => $value) {
            if ($key === '@type' && is_array($value)) {
                foreach ($value as $type) {
                    if (is_string($type) && count($types) < 20) {
                        $types[mb_substr($type, 0, 100)] = true;
                    }
                }
            } elseif (is_array($value)) {
                self::collectTypeLists($value, $types);
            }
        }
    }
}
