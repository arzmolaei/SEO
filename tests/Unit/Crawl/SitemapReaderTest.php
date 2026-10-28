<?php

namespace Tests\Unit\Crawl;

use App\Domain\Crawl\SitemapReader;
use PHPUnit\Framework\TestCase;

class SitemapReaderTest extends TestCase
{
    public function test_urlsets_and_gzip_indexes_are_read(): void
    {
        $urlset = SitemapReader::read('<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc> https://e.com/a </loc></url><url><loc>https://e.com/صفحه</loc></url></urlset>');
        $this->assertSame(['type' => 'urlset', 'locations' => ['https://e.com/a', 'https://e.com/صفحه'], 'truncated' => false], $urlset);

        $index = SitemapReader::read((string) gzencode('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://e.com/s1.xml</loc></sitemap></sitemapindex>'));
        $this->assertSame('index', $index['type']);
        $this->assertSame(['https://e.com/s1.xml'], $index['locations']);
    }

    public function test_entities_bombs_malformed_and_non_sitemap_documents_are_rejected(): void
    {
        foreach ([
            '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><urlset><url><loc>&xxe;</loc></url></urlset>',
            (string) gzencode(str_repeat('a', SitemapReader::MAX_INFLATED_BYTES + 10)),
            '<html><body>not a sitemap</body></html>',
            '<urlset><url><loc>https://e.com/1',
        ] as $body) {
            $this->assertSame(['type' => 'invalid', 'locations' => [], 'truncated' => false], SitemapReader::read($body));
        }
    }

    public function test_entries_are_capped(): void
    {
        $body = '<urlset>'.str_repeat('<url><loc>https://e.com/x</loc></url>', SitemapReader::MAX_ENTRIES + 5).'</urlset>';
        $result = SitemapReader::read($body);

        $this->assertCount(SitemapReader::MAX_ENTRIES, $result['locations']);
        $this->assertTrue($result['truncated']);
    }
}
