<?php

namespace Tests\Unit\Crawl;

use App\Domain\Crawl\RobotsTxt;
use PHPUnit\Framework\TestCase;

class RobotsTxtTest extends TestCase
{
    public function test_longest_match_wildcards_end_anchor_and_delay_cap(): void
    {
        $robots = RobotsTxt::parse(
            "User-agent: *\nDisallow: /private/\nAllow: /private/public\nDisallow: /*.pdf$\nDisallow: /search?\nCrawl-delay: 30\n\nUser-agent: OtherBot\nDisallow: /\n",
            'SEO-OS-Crawler/1',
        );

        $this->assertTrue($robots->allows('/'));
        $this->assertFalse($robots->allows('/private/x'));
        $this->assertTrue($robots->allows('/private/public/x'));
        $this->assertFalse($robots->allows('/files/report.pdf'));
        $this->assertTrue($robots->allows('/files/report.pdf?download=1'));
        $this->assertFalse($robots->allows('/search?q=seo'));
        $this->assertTrue($robots->allows('/robots.txt'));
        $this->assertSame(10, $robots->crawlDelaySeconds());
    }

    public function test_the_crawler_specific_group_replaces_the_wildcard_group(): void
    {
        $robots = RobotsTxt::parse("User-agent: seo-os-crawler\nDisallow: /x\n\nUser-agent: *\nDisallow: /\n", 'SEO-OS-Crawler/1');

        $this->assertTrue($robots->allows('/y'));
        $this->assertFalse($robots->allows('/x'));
    }

    public function test_allow_wins_ties_and_empty_or_garbage_rules_allow(): void
    {
        $this->assertTrue(RobotsTxt::parse("User-agent: *\nDisallow: /a\nAllow: /a\n", 'bot')->allows('/a'));
        $this->assertTrue(RobotsTxt::parse("User-agent: *\nDisallow:\n", 'bot')->allows('/x'));
        $this->assertTrue(RobotsTxt::parse("garbage\n\0\xff", 'bot')->allows('/anything'));
    }

    public function test_percent_encoded_rules_match_persian_paths(): void
    {
        $this->assertFalse(RobotsTxt::parse("User-agent: *\nDisallow: /%D8%B5\n", 'bot')->allows('/ص'));
    }
}
