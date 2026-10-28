<?php

namespace Tests\Unit\Links;

use App\Domain\Links\InternalLinkSuggester;
use PHPUnit\Framework\TestCase;

class InternalLinkSuggesterTest extends TestCase
{
    /** @return list<array{cluster_id: int, page_id: int, primary: string, keywords: list<string>}> */
    private function site(): array
    {
        return [
            ['cluster_id' => 1, 'page_id' => 10, 'primary' => 'کفش ورزشی', 'keywords' => ['کفش ورزشی', 'کفش ورزشی مردانه', 'قیمت کفش ورزشی']],
            ['cluster_id' => 2, 'page_id' => 20, 'primary' => 'کفش دویدن', 'keywords' => ['کفش دویدن', 'كفش مخصوص دويدن', 'کفش ورزشی دویدن']],
            ['cluster_id' => 3, 'page_id' => 30, 'primary' => 'لپ تاپ', 'keywords' => ['لپ تاپ', 'خرید لپ تاپ']],
        ];
    }

    public function test_pages_with_a_shared_topic_link_both_ways_with_the_targets_keywords_as_anchors(): void
    {
        $suggestions = InternalLinkSuggester::suggest($this->site());

        $pairs = array_map(fn (array $item): string => $item['source_page_id'].'>'.$item['target_page_id'], $suggestions);
        $this->assertEqualsCanonicalizing(['20>10', '10>20'], $pairs, 'an unrelated topic gets no links');
        $toRunning = $suggestions[array_search('10>20', $pairs, true)];
        $this->assertSame('کفش دویدن', $toRunning['anchor']);
        // Arabic letters in a keyword are normalized before matching and in anchors.
        $this->assertSame(['کفش مخصوص دویدن', 'کفش ورزشی دویدن'], $toRunning['alternatives']);
        $this->assertSame(['کفش', 'ورزشی'], $toRunning['shared']);
    }

    public function test_generic_modifiers_alone_do_not_create_links(): void
    {
        $this->assertSame([], InternalLinkSuggester::suggest([
            ['cluster_id' => 1, 'page_id' => 10, 'primary' => 'خرید کفش', 'keywords' => ['قیمت کفش', 'بهترین کفش']],
            ['cluster_id' => 2, 'page_id' => 20, 'primary' => 'خرید لپ تاپ', 'keywords' => ['قیمت لپ تاپ', 'بهترین لپ تاپ']],
        ]));
    }

    public function test_clusters_on_the_same_page_never_link_to_themselves_and_sources_are_bounded(): void
    {
        // Page 1 has two clusters about backlinks; eight other pages share that topic.
        $clusters = [
            ['cluster_id' => 100, 'page_id' => 1, 'primary' => 'بک لینک', 'keywords' => ['بک لینک']],
            ['cluster_id' => 101, 'page_id' => 1, 'primary' => 'بک لینک رایگان', 'keywords' => ['بک لینک رایگان']],
        ];
        foreach (range(2, 9) as $page) {
            $clusters[] = ['cluster_id' => $page, 'page_id' => $page, 'primary' => 'بک لینک '.$page, 'keywords' => ['بک لینک '.$page]];
        }
        // Unrelated topics, so "بک لینک" is a topic here and not a site-wide word.
        foreach (range(20, 49) as $page) {
            $clusters[] = ['cluster_id' => $page, 'page_id' => $page, 'primary' => 'موضوع'.$page, 'keywords' => ['موضوع'.$page]];
        }

        $suggestions = InternalLinkSuggester::suggest($clusters);

        foreach ($suggestions as $item) {
            $this->assertNotSame($item['source_page_id'], $item['target_page_id']);
        }
        $toPillar = array_filter($suggestions, fn (array $item): bool => $item['target_page_id'] === 1);
        $this->assertCount(InternalLinkSuggester::MAX_SOURCES_PER_TARGET, $toPillar);
    }
}
