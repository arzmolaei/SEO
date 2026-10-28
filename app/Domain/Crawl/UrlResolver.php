<?php

namespace App\Domain\Crawl;

/**
 * RFC 3986 §5.2 reference resolution, used for redirect targets and links.
 * Returns null for references the crawler never follows (non-HTTP schemes,
 * fragments-only, scripts).
 */
final class UrlResolver
{
    public static function resolve(string $base, string $reference): ?string
    {
        $reference = trim($reference);
        if ($reference === '' || str_starts_with($reference, '#') || strlen($reference) > 8192) {
            return null;
        }
        $reference = (string) preg_replace('/#.*\z/s', '', $reference);

        if (preg_match('/\A([a-z][a-z0-9+.\-]*):/i', $reference, $scheme) === 1) {
            return in_array(strtolower($scheme[1]), ['http', 'https'], true) ? self::removeDotSegmentsFromUrl($reference) : null;
        }

        $b = parse_url($base);
        if (! is_array($b) || ! isset($b['scheme'], $b['host'])) {
            return null;
        }
        $authority = $b['scheme'].'://'.(str_contains($b['host'], ':') && ! str_starts_with($b['host'], '[') ? '['.$b['host'].']' : $b['host']).(isset($b['port']) ? ':'.$b['port'] : '');

        if (str_starts_with($reference, '//')) {
            return self::removeDotSegmentsFromUrl($b['scheme'].':'.$reference);
        }

        $basePath = $b['path'] ?? '/';
        if ($reference[0] === '?') {
            return $authority.$basePath.$reference;
        }

        [$path, $query] = array_pad(explode('?', $reference, 2), 2, null);
        if (! str_starts_with($path, '/')) {
            $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
            $path = ($directory === '' ? '/' : $directory).$path;
        }

        return $authority.self::removeDotSegments($path).($query !== null ? '?'.$query : '');
    }

    private static function removeDotSegmentsFromUrl(string $url): string
    {
        if (preg_match('#\A([a-z][a-z0-9+.\-]*://[^/?]*)([^?]*)(\?.*)?\z#is', $url, $m) !== 1) {
            return $url;
        }

        return $m[1].self::removeDotSegments($m[2] === '' ? '/' : $m[2]).($m[3] ?? '');
    }

    private static function removeDotSegments(string $path): string
    {
        $output = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            if ($segment === '.') {
                if ($index === $last) {
                    $output[] = '';
                }

                continue;
            }
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
                if ($index === $last) {
                    $output[] = '';
                }

                continue;
            }
            $output[] = $segment;
        }
        $result = implode('/', $output);

        return str_starts_with($result, '/') ? $result : '/'.$result;
    }
}
