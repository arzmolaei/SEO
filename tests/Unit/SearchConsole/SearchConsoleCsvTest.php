<?php

namespace Tests\Unit\SearchConsole;

use App\Domain\SearchConsole\SearchConsoleCsv;
use App\Domain\SearchConsole\SearchOpportunities;
use PHPUnit\Framework\TestCase;

class SearchConsoleCsvTest extends TestCase
{
    public function test_english_and_persian_exports_are_recognised_by_their_header(): void
    {
        $this->assertSame('queries', SearchConsoleCsv::detect(['Top queries', 'Clicks', 'Impressions', 'CTR', 'Position'])['kind'] ?? null);
        $this->assertSame('pages', SearchConsoleCsv::detect(['Top pages', 'Clicks', 'Impressions', 'CTR', 'Position'])['kind'] ?? null);
        $persian = SearchConsoleCsv::detect(['پرسش‌های برتر', 'کلیک‌ها', 'نمایش‌ها', 'CTR', 'جایگاه']);
        $this->assertSame('queries', $persian['kind'] ?? null);
        $this->assertSame('جایگاه', $persian['columns']['position'] ?? null);
        $this->assertNull(SearchConsoleCsv::detect(['Keyword', 'Volume']), 'a keyword-research CSV is not a Search Console export');
        $this->assertNull(SearchConsoleCsv::detect(['Top queries', 'Clicks']), 'metrics are required');
    }

    public function test_rows_accept_persian_digits_separators_and_reject_impossible_values(): void
    {
        $columns = SearchConsoleCsv::detect(['Top queries', 'Clicks', 'Impressions', 'CTR', 'Position'])['columns'] ?? [];
        $this->assertSame(
            ['key' => 'خرید کفش', 'clicks' => 1234, 'impressions' => 56789, 'position' => 3.5],
            SearchConsoleCsv::parse(['Top queries' => ' خرید کفش ', 'Clicks' => '۱٬۲۳۴', 'Impressions' => '56,789', 'CTR' => '2.17%', 'Position' => '۳٫۵'], $columns),
        );
        foreach ([
            ['Top queries' => '', 'Clicks' => '1', 'Impressions' => '2', 'Position' => '1'],
            ['Top queries' => 'x', 'Clicks' => '5', 'Impressions' => '2', 'Position' => '1'],
            ['Top queries' => 'x', 'Clicks' => 'n/a', 'Impressions' => '2', 'Position' => '1'],
            ['Top queries' => 'x', 'Clicks' => '-1', 'Impressions' => '2', 'Position' => '1'],
        ] as $row) {
            $this->assertNull(SearchConsoleCsv::parse($row, $columns));
        }
    }

    public function test_opportunities_follow_their_plain_rules(): void
    {
        $rows = [
            ['key' => 'نزدیک صدر', 'clicks' => 5, 'impressions' => 400, 'position' => 7.2],
            ['key' => 'صدر', 'clicks' => 90, 'impressions' => 400, 'position' => 1.2],
            ['key' => 'کم‌نمایش', 'clicks' => 0, 'impressions' => 5, 'position' => 8.0],
            ['key' => 'دور', 'clicks' => 0, 'impressions' => 900, 'position' => 35.0],
            ['key' => 'کلیک کم', 'clicks' => 2, 'impressions' => 1000, 'position' => 2.0],
        ];

        $this->assertSame(['نزدیک صدر'], array_column(SearchOpportunities::nearTop($rows), 'key'));
        $low = SearchOpportunities::lowCtr($rows);
        $this->assertSame(['کلیک کم', 'نزدیک صدر'], array_column($low, 'key'));
        $this->assertSame(148, $low[0]['missed_clicks']);

        // 30 days before at 3/day; now 14 days at 1/day = -67%. Steady pages are not flagged.
        $declining = SearchOpportunities::declining(
            [['key' => '/a', 'clicks' => 14, 'impressions' => 100, 'position' => 5.0], ['key' => '/b', 'clicks' => 28, 'impressions' => 100, 'position' => 5.0]],
            14,
            ['/a' => 90, '/b' => 60, '/c' => 10],
            30,
        );
        $this->assertSame([['key' => '/a', 'clicks' => 14, 'previous_clicks' => 90, 'change' => -0.6667]], $declining);
    }
}
