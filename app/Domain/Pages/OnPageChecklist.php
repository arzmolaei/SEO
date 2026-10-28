<?php

namespace App\Domain\Pages;

use App\Domain\Keywords\PersianKeywordNormalizer;

/**
 * Beginner on-page checklist for one page. Pure: the caller supplies the
 * page fields and project facts; every item is pass, fix or na (does not
 * apply yet). Lengths count characters, so Persian text is measured fairly.
 */
final class OnPageChecklist
{
    public const TITLE_MIN = 30;

    public const TITLE_MAX = 65;

    public const META_MIN = 70;

    public const META_MAX = 160;

    public const URL_MAX = 75;

    /**
     * @param  array{seo_title: ?string, meta_description: ?string, h1: ?string, url: ?string, planned: bool}  $page
     * @param  ?string  $primaryKeyword  the target cluster's primary keyword
     * @param  int  $targetCount  how many clusters target this page
     * @param  bool  $duplicateTitle  another page of the project has the same SEO title
     * @return list<array{key: string, status: 'pass'|'fix'|'na'}>
     */
    public static function evaluate(array $page, ?string $primaryKeyword, int $targetCount, bool $duplicateTitle): array
    {
        $normalizer = new PersianKeywordNormalizer;
        $keyword = $primaryKeyword === null ? null : mb_strtolower($normalizer->normalize($primaryKeyword));
        $text = static fn (?string $value): ?string => $value === null || trim($value) === '' ? null : $normalizer->normalize($value);
        $title = $text($page['seo_title']);
        $meta = $text($page['meta_description']);
        $h1 = $text($page['h1']);
        $contains = static fn (?string $value): string => $value === null || $keyword === null || $keyword === ''
            ? 'na'
            : (str_contains(mb_strtolower($value), $keyword) ? 'pass' : 'fix');
        $between = static fn (?string $value, int $min, int $max): string => $value === null
            ? 'na'
            : (mb_strlen($value) >= $min && mb_strlen($value) <= $max ? 'pass' : 'fix');

        $path = $page['url'] === null ? null : rawurldecode((string) parse_url($page['url'], PHP_URL_PATH));

        $items = [
            'target' => $targetCount > 0 ? 'pass' : 'fix',
            'single_target' => $targetCount > 1 ? 'fix' : ($targetCount === 1 ? 'pass' : 'na'),
            'title_present' => $title === null ? 'fix' : 'pass',
            'title_length' => $between($title, self::TITLE_MIN, self::TITLE_MAX),
            'title_keyword' => $contains($title),
            'title_unique' => $title === null ? 'na' : ($duplicateTitle ? 'fix' : 'pass'),
            'meta_present' => $meta === null ? 'fix' : 'pass',
            'meta_length' => $between($meta, self::META_MIN, self::META_MAX),
            'meta_keyword' => $contains($meta),
            'h1_present' => $h1 === null ? 'fix' : 'pass',
            'h1_keyword' => $contains($h1),
            // A planned page has no address yet; that is expected, not a fault.
            'url_present' => $page['planned'] ? 'na' : ($path === null ? 'fix' : 'pass'),
            'url_short' => $path === null ? 'na' : (mb_strlen($path) <= self::URL_MAX && substr_count(trim($path, '/'), '/') < 4 ? 'pass' : 'fix'),
        ];

        return array_map(
            static fn (string $key, string $status): array => ['key' => $key, 'status' => $status],
            array_keys($items),
            array_values($items),
        );
    }

    /**
     * @param  list<array{key: string, status: string}>  $items
     * @return array{passed: int, applicable: int}
     */
    public static function score(array $items): array
    {
        $applicable = array_values(array_filter($items, static fn (array $item): bool => $item['status'] !== 'na'));

        return [
            'passed' => count(array_filter($applicable, static fn (array $item): bool => $item['status'] === 'pass')),
            'applicable' => count($applicable),
        ];
    }
}
