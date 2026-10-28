<?php

namespace Tests\Unit\Crawl;

use App\Domain\Crawl\HtmlExtractor;
use PHPUnit\Framework\TestCase;

class HtmlExtractorTest extends TestCase
{
    private function internal(): \Closure
    {
        return static fn (string $url): bool => parse_url($url, PHP_URL_HOST) === 'example.com';
    }

    public function test_persian_page_fields_are_extracted_and_bounded(): void
    {
        $page = HtmlExtractor::extract(
            (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/crawl-html/persian.html'),
            'https://example.com/blog/seo-guide',
            'text/html; charset=UTF-8',
            'noarchive',
            $this->internal(),
        );

        $this->assertSame('راهنمای سئو', $page['title']);
        $this->assertSame('آموزش سئو برای فروشگاه‌ها', $page['meta_description']);
        $this->assertSame('index, follow, noarchive', $page['robots']);
        $this->assertSame('https://example.com/blog/seo-guide', $page['canonical']);
        $this->assertSame(['سئو چیست؟'], $page['h1']);
        $this->assertSame(['بخش اول', 'بخش دوم'], $page['h2']);
        $this->assertSame([['lang' => 'en', 'href' => 'https://example.com/en/seo-guide']], $page['hreflang']);
        $this->assertSame(['Article', 'Product', 'Thing'], $page['schema_types']);
        // Script text never counts; adjacent blocks never merge; «می‌شود» (ZWNJ) is one word.
        $this->assertSame(21, $page['word_count']);
        $this->assertSame(3, $page['images']);
        $this->assertSame(1, $page['images_missing_alt'], 'alt="" is a valid decorative image.');
        $this->assertSame(3, $page['internal_links']);
        $this->assertSame(1, $page['external_links']);
        // <base href> applies; fragments are dropped; mailto/javascript are ignored.
        $this->assertSame([
            'https://example.com/blog/seo-guide',
            'https://example.com/products/',
            'https://example.com/contact',
        ], $page['internal_urls']);
        $this->assertFalse($page['noindex']);
    }

    public function test_legacy_windows_1256_pages_are_decoded(): void
    {
        $html = (string) iconv('UTF-8', 'WINDOWS-1256', '<html><head><title>سلام عليكم</title></head><body><p>سلام عليكم</p></body></html>');

        $page = HtmlExtractor::extract($html, 'https://example.com/', 'text/html; charset=windows-1256', null, $this->internal());

        $this->assertSame('سلام عليكم', $page['title']);
        $this->assertSame(2, $page['word_count']);
    }

    public function test_robots_directives_from_meta_and_header_mark_noindex_and_nofollow(): void
    {
        $meta = HtmlExtractor::extract('<html><head><meta name="robots" content="noindex,nofollow"></head><body>x</body></html>', 'https://example.com/', null, null, $this->internal());
        $this->assertTrue($meta['noindex']);
        $this->assertTrue($meta['nofollow']);

        $header = HtmlExtractor::extract('<html><body>x</body></html>', 'https://example.com/', null, 'none', $this->internal());
        $this->assertTrue($header['noindex']);
    }

    public function test_malformed_and_empty_markup_never_breaks_extraction(): void
    {
        $broken = HtmlExtractor::extract("<html><body>\xff\xfe broken <a href='/ok'>x</a></body></html>", 'https://example.com/', null, null, $this->internal());
        $this->assertSame(['https://example.com/ok'], $broken['internal_urls']);

        $empty = HtmlExtractor::extract('', 'https://example.com/', null, null, $this->internal());
        $this->assertNull($empty['title']);
        $this->assertSame(0, $empty['word_count']);
    }
}
