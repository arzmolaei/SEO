<?php

namespace Tests\Unit\Pages;

use App\Domain\Pages\OnPageChecklist;
use PHPUnit\Framework\TestCase;

class OnPageChecklistTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $page
     * @return array<string, string>
     */
    private function statuses(array $page, ?string $keyword = 'خرید کفش', int $targets = 1, bool $duplicate = false): array
    {
        $items = OnPageChecklist::evaluate($page + [
            'seo_title' => null, 'meta_description' => null, 'h1' => null, 'url' => null, 'planned' => false,
        ], $keyword, $targets, $duplicate);

        return array_column($items, 'status', 'key');
    }

    public function test_an_empty_existing_page_without_a_target_asks_for_the_basics(): void
    {
        $statuses = $this->statuses(['url' => 'https://example.com/shoes'], null, 0);

        $this->assertSame('fix', $statuses['target']);
        $this->assertSame('na', $statuses['single_target']);
        $this->assertSame('fix', $statuses['title_present']);
        $this->assertSame('na', $statuses['title_length'], 'length is only judged once a title exists');
        $this->assertSame('na', $statuses['title_keyword'], 'no keyword to look for');
        $this->assertSame('fix', $statuses['meta_present']);
        $this->assertSame('fix', $statuses['h1_present']);
        $this->assertSame('pass', $statuses['url_present']);
        $this->assertSame('pass', $statuses['url_short']);
    }

    public function test_a_well_written_persian_page_passes_every_applicable_item(): void
    {
        $items = OnPageChecklist::evaluate([
            // Arabic ي/ك and a ZWNJ still match the Persian keyword after normalization.
            'seo_title' => 'خريد كفش ورزشی مردانه با ارسال رایگان و ضمانت',
            'meta_description' => 'راهنمای خرید کفش ورزشی: مقایسه مدل‌ها، قیمت روز و نکته‌های انتخاب سایز برای پیاده‌روی و دویدن.',
            'h1' => 'خرید کفش ورزشی',
            'url' => 'https://example.com/shoes/running',
            'planned' => false,
        ], 'خرید کفش', 1, false);

        $this->assertSame([], array_values(array_filter($items, fn (array $item): bool => $item['status'] === 'fix')));
        $this->assertSame(['passed' => 13, 'applicable' => 13], OnPageChecklist::score($items));
    }

    public function test_lengths_keyword_absence_duplicates_and_competing_targets_need_fixing(): void
    {
        $statuses = $this->statuses([
            'seo_title' => 'کفش',
            'meta_description' => str_repeat('توضیح ', 40),
            'h1' => 'فروشگاه',
            'url' => 'https://example.com/a/b/c/d/e/very-long-address',
        ], 'خرید کفش', 2, true);

        $this->assertSame('fix', $statuses['single_target']);
        $this->assertSame('fix', $statuses['title_length']);
        $this->assertSame('fix', $statuses['title_keyword']);
        $this->assertSame('fix', $statuses['title_unique']);
        $this->assertSame('fix', $statuses['meta_length']);
        $this->assertSame('fix', $statuses['meta_keyword']);
        $this->assertSame('fix', $statuses['h1_keyword']);
        $this->assertSame('fix', $statuses['url_short']);
    }

    public function test_a_planned_page_is_not_faulted_for_having_no_address(): void
    {
        $items = OnPageChecklist::evaluate(
            ['seo_title' => null, 'meta_description' => null, 'h1' => null, 'url' => null, 'planned' => true],
            'خرید کفش', 1, false,
        );
        $statuses = array_column($items, 'status', 'key');

        $this->assertSame('na', $statuses['url_present']);
        $this->assertSame('na', $statuses['url_short']);
        $this->assertSame(['passed' => 2, 'applicable' => 5], OnPageChecklist::score($items));
    }
}
