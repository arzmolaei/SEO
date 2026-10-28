<?php

namespace Tests\Unit\Pages;

use App\Domain\Pages\LivePageCheck;
use PHPUnit\Framework\TestCase;

class LivePageCheckTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $live
     * @param  array<string, ?string>  $planned
     * @return array<string, string|int|null>
     */
    private function problems(array $live, array $planned = []): array
    {
        $problems = LivePageCheck::problems($live + [
            'state' => 'fetched', 'error_code' => null, 'status_code' => 200, 'final_url' => null,
            'content_type' => 'text/html; charset=utf-8', 'title' => 'خرید کفش ورزشی', 'meta_description' => 'راهنمای خرید',
            'robots' => null, 'canonical' => null, 'h1' => ['خرید کفش'], 'word_count' => 800,
            'normalized_url' => 'https://example.test/shoes',
        ], $planned + ['seo_title' => null, 'meta_description' => null, 'h1' => null]);

        return array_column($problems, 'value', 'key');
    }

    public function test_a_healthy_page_matching_the_plan_has_no_problems(): void
    {
        $this->assertSame([], $this->problems([], [
            // Arabic ي/ك in the plan still matches the live Persian text.
            'seo_title' => 'خريد كفش ورزشی', 'meta_description' => 'راهنمای  خرید', 'h1' => 'خرید کفش',
        ]));
    }

    public function test_unreachable_and_error_pages_report_only_that(): void
    {
        $this->assertSame(['fetch_failed' => 'timeout'], $this->problems(['state' => 'failed', 'status_code' => null, 'error_code' => 'timeout']));
        $this->assertSame(['fetch_failed' => 'blocked_by_robots'], $this->problems(['state' => 'blocked', 'status_code' => null, 'error_code' => 'blocked_by_robots']));
        $this->assertSame(['http_error' => 404], $this->problems(['status_code' => 404, 'title' => null]));
    }

    public function test_indexing_redirect_and_content_problems_are_reported_with_live_values(): void
    {
        $problems = $this->problems([
            'final_url' => 'https://example.test/new-shoes',
            'robots' => 'NOINDEX, follow',
            'canonical' => 'https://example.test/other',
            'title' => 'فروشگاه',
            'meta_description' => '  ',
            'h1' => ['یک', 'دو'],
            'word_count' => 120,
        ], ['seo_title' => 'خرید کفش', 'h1' => 'خرید کفش']);

        $this->assertSame([
            'redirected' => 'https://example.test/new-shoes',
            'noindex' => null,
            'canonical_elsewhere' => 'https://example.test/other',
            'title_differs' => 'فروشگاه',
            'meta_missing' => null,
            'h1_multiple' => 2,
            'h1_differs' => 'یک',
            'thin_content' => 120,
        ], $problems);
    }

    public function test_a_canonical_to_the_redirect_target_is_not_a_problem(): void
    {
        $problems = $this->problems(['final_url' => 'https://example.test/b', 'canonical' => 'https://example.test/b']);

        $this->assertSame(['redirected' => 'https://example.test/b'], $problems);
    }

    public function test_non_html_and_missing_headings_are_reported(): void
    {
        $this->assertSame(['not_html' => 'application/pdf'], $this->problems(['content_type' => 'application/pdf']));
        $this->assertSame(['title_missing' => null, 'h1_missing' => null], $this->problems(['title' => null, 'h1' => []]));
    }
}
