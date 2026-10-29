<?php

namespace App\Domain\SearchConsole;

/**
 * Understands the CSV files Google Search Console exports from the
 * Performance report ("Queries.csv" / "Pages.csv"), in English or Persian.
 * Only the header row decides the kind; numbers may use Persian digits,
 * thousands separators and a percent sign. Pure; no Google API involved.
 */
final class SearchConsoleCsv
{
    public const KINDS = ['queries', 'pages'];

    private const KEY_HEADERS = [
        'queries' => ['top queries', 'query', 'queries', 'پرسش‌های برتر', 'پرسش های برتر', 'پرسش‌ها', 'عبارت‌های جستجو', 'جستجوهای برتر'],
        'pages' => ['top pages', 'page', 'pages', 'صفحه‌های برتر', 'صفحه های برتر', 'صفحات برتر', 'صفحه‌ها', 'صفحات'],
    ];

    private const METRIC_HEADERS = [
        'clicks' => ['clicks', 'کلیک‌ها', 'کلیک ها', 'کلیک'],
        'impressions' => ['impressions', 'نمایش‌ها', 'نمایش ها', 'نمایش', 'impression'],
        'ctr' => ['ctr', 'نرخ کلیک', 'نرخ کلیک (ctr)'],
        'position' => ['position', 'average position', 'جایگاه', 'موقعیت', 'میانگین جایگاه', 'میانگین موقعیت'],
    ];

    /**
     * @param  list<string>  $headers
     * @return array{kind: 'queries'|'pages', columns: array{key: string, clicks: string, impressions: string, ctr: ?string, position: string}}|null
     */
    public static function detect(array $headers): ?array
    {
        $byName = [];
        foreach ($headers as $header) {
            $byName[self::headerKey($header)] ??= $header;
        }
        $kind = null;
        $key = null;
        foreach (self::KEY_HEADERS as $candidateKind => $names) {
            foreach ($names as $name) {
                if (isset($byName[self::headerKey($name)])) {
                    $kind = $candidateKind;
                    $key = $byName[self::headerKey($name)];
                    break 2;
                }
            }
        }
        $metrics = [];
        foreach (self::METRIC_HEADERS as $metric => $names) {
            $metrics[$metric] = null;
            foreach ($names as $name) {
                if (isset($byName[self::headerKey($name)])) {
                    $metrics[$metric] = $byName[self::headerKey($name)];
                    break;
                }
            }
        }
        if ($kind === null || $key === null || $metrics['clicks'] === null || $metrics['impressions'] === null || $metrics['position'] === null) {
            return null;
        }

        return ['kind' => $kind, 'columns' => [
            'key' => $key,
            'clicks' => $metrics['clicks'],
            'impressions' => $metrics['impressions'],
            'ctr' => $metrics['ctr'],
            'position' => $metrics['position'],
        ]];
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array{key: string, clicks: string, impressions: string, ctr: ?string, position: string}  $columns
     * @return array{key: string, clicks: int, impressions: int, position: float}|null null when the row is not a usable data row
     */
    public static function parse(array $row, array $columns): ?array
    {
        $key = trim((string) ($row[$columns['key']] ?? ''));
        $clicks = self::number($row[$columns['clicks']] ?? null);
        $impressions = self::number($row[$columns['impressions']] ?? null);
        $position = self::number($row[$columns['position']] ?? null);
        if ($key === '' || mb_strlen($key) > 2048 || $clicks === null || $impressions === null || $position === null
            || $clicks < 0 || $impressions < 0 || $clicks > $impressions || $position < 0 || $position > 1000) {
            return null;
        }

        return ['key' => $key, 'clicks' => (int) round($clicks), 'impressions' => (int) round($impressions), 'position' => round($position, 2)];
    }

    /** Reads "۱٬۲۳۴", "1,234", "3.5%" and "۳٫۵" alike. */
    public static function number(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $value = strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '٬' => '', ',' => '', '%' => '', '٪' => '', ' ' => '', "\u{00A0}" => '', "\u{202F}" => '',
        ]);

        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? (float) $value : null;
    }

    private static function headerKey(string $header): string
    {
        $header = str_replace("\u{200C}", ' ', mb_strtolower(trim($header)));

        return preg_replace('/\s+/u', ' ', $header) ?? $header;
    }
}
