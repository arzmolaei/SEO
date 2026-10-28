<?php

namespace App\Domain\Pages;

use App\Domain\Keywords\PersianKeywordNormalizer;

/**
 * Compares what the owner's live page returned in the latest check with what
 * the owner wrote for it in the pages workspace. Pure: the caller supplies
 * the stored crawl fields. Each problem has a stable key and, where useful,
 * the live value that caused it.
 */
final class LivePageCheck
{
    public const THIN_WORDS = 300;

    /**
     * @param  array{state: string, error_code: ?string, status_code: ?int, final_url: ?string, content_type: ?string, title: ?string, meta_description: ?string, robots: ?string, canonical: ?string, h1: list<string>, word_count: ?int, normalized_url: string}  $live
     * @param  array{seo_title: ?string, meta_description: ?string, h1: ?string}  $planned
     * @return list<array{key: string, value: string|int|null}>
     */
    public static function problems(array $live, array $planned): array
    {
        if ($live['state'] !== 'fetched' || $live['status_code'] === null) {
            return [['key' => 'fetch_failed', 'value' => $live['error_code']]];
        }
        if ($live['status_code'] >= 400) {
            return [['key' => 'http_error', 'value' => $live['status_code']]];
        }
        $problems = [];
        if ($live['final_url'] !== null) {
            $problems[] = ['key' => 'redirected', 'value' => $live['final_url']];
        }
        $type = strtolower((string) $live['content_type']);
        if (! str_starts_with($type, 'text/html') && ! str_starts_with($type, 'application/xhtml+xml')) {
            $problems[] = ['key' => 'not_html', 'value' => $live['content_type']];

            return $problems;
        }

        $directives = array_map('trim', explode(',', strtolower((string) $live['robots'])));
        if (in_array('noindex', $directives, true) || in_array('none', $directives, true)) {
            $problems[] = ['key' => 'noindex', 'value' => null];
        }
        $address = $live['final_url'] ?? $live['normalized_url'];
        if ($live['canonical'] !== null && $live['canonical'] !== $address) {
            $problems[] = ['key' => 'canonical_elsewhere', 'value' => $live['canonical']];
        }

        $normalizer = new PersianKeywordNormalizer;
        $same = static fn (string $a, string $b): bool => mb_strtolower($normalizer->normalize($a)) === mb_strtolower($normalizer->normalize($b));
        $filled = static fn (?string $value): ?string => $value === null || trim($value) === '' ? null : $value;

        foreach ([['title', $live['title'], $planned['seo_title']], ['meta', $live['meta_description'], $planned['meta_description']]] as [$name, $liveValue, $plannedValue]) {
            $liveValue = $filled($liveValue);
            $plannedValue = $filled($plannedValue);
            if ($liveValue === null) {
                $problems[] = ['key' => $name.'_missing', 'value' => null];
            } elseif ($plannedValue !== null && ! $same($liveValue, $plannedValue)) {
                $problems[] = ['key' => $name.'_differs', 'value' => $liveValue];
            }
        }

        $headings = array_values(array_filter($live['h1'], static fn (string $heading): bool => trim($heading) !== ''));
        $plannedH1 = $filled($planned['h1']);
        if ($headings === []) {
            $problems[] = ['key' => 'h1_missing', 'value' => null];
        } else {
            if (count($headings) > 1) {
                $problems[] = ['key' => 'h1_multiple', 'value' => count($headings)];
            }
            if ($plannedH1 !== null && ! $same($headings[0], $plannedH1)) {
                $problems[] = ['key' => 'h1_differs', 'value' => $headings[0]];
            }
        }

        if ($live['word_count'] !== null && $live['word_count'] < self::THIN_WORDS) {
            $problems[] = ['key' => 'thin_content', 'value' => $live['word_count']];
        }

        return $problems;
    }
}
