<?php

namespace App\Domain\Links;

use App\Domain\Keywords\PersianKeywordNormalizer;

/**
 * Suggests internal links between the owner's mapped pages from the topics
 * of their clusters: when two clusters share meaningful words, the page of
 * one should link to the page of the other, using the target's keywords as
 * anchor text. Pure and offline; no crawl link graph is needed.
 */
final class InternalLinkSuggester
{
    public const MIN_SCORE = 0.15;

    public const MAX_SOURCES_PER_TARGET = 5;

    /** Words too common in search phrases to signal a shared topic. */
    private const STOPWORDS = [
        'از', 'به', 'در', 'با', 'برای', 'و', 'که', 'این', 'آن', 'را', 'تا', 'یا', 'های', 'ها', 'می', 'است',
        'چه', 'چی', 'چیست', 'چگونه', 'چطور', 'کدام', 'کجا', 'آیا', 'هر', 'بر', 'بی', 'یک',
        'بهترین', 'خرید', 'قیمت', 'ارزان', 'آنلاین', 'جدید', 'انواع', 'لیست', 'مدل', 'نحوه', 'روش',
        'the', 'a', 'an', 'of', 'for', 'and', 'to', 'in', 'on', 'how', 'what', 'best', 'buy', 'price',
    ];

    /**
     * @param  list<array{cluster_id: int, page_id: int, primary: string, keywords: list<string>}>  $clusters  mapped clusters
     * @return list<array{source_page_id: int, target_page_id: int, target_cluster_id: int, anchor: string, alternatives: list<string>, shared: list<string>, score: float}>
     */
    public static function suggest(array $clusters): array
    {
        $normalizer = new PersianKeywordNormalizer;
        $tokens = [];
        $index = [];
        foreach ($clusters as $position => $cluster) {
            $set = [];
            foreach ([$cluster['primary'], ...$cluster['keywords']] as $keyword) {
                foreach (self::words($normalizer->normalize($keyword)) as $word) {
                    $set[$word] = true;
                }
            }
            $tokens[$position] = $set;
            foreach (array_keys($set) as $word) {
                $index[$word][] = $position;
            }
        }
        // A word shared by a large part of the site describes the site, not a topic.
        $common = max(5, (int) ceil(count($clusters) * 0.3));

        $byTarget = [];
        foreach ($clusters as $target => $targetCluster) {
            $candidates = [];
            foreach (array_keys($tokens[$target]) as $word) {
                if (count($index[$word]) > $common) {
                    continue;
                }
                foreach ($index[$word] as $source) {
                    if ($source !== $target && $clusters[$source]['page_id'] !== $targetCluster['page_id']) {
                        $candidates[$source][] = $word;
                    }
                }
            }
            foreach ($candidates as $source => $shared) {
                $union = count($tokens[$source] + $tokens[$target]);
                $score = $union === 0 ? 0.0 : count($shared) / $union;
                if ($score < self::MIN_SCORE) {
                    continue;
                }
                $key = $clusters[$source]['page_id'].':'.$targetCluster['page_id'];
                if (isset($byTarget[$targetCluster['page_id']][$key]) && $byTarget[$targetCluster['page_id']][$key]['score'] >= $score) {
                    continue;
                }
                $byTarget[$targetCluster['page_id']][$key] = [
                    'source_page_id' => $clusters[$source]['page_id'],
                    'target_page_id' => $targetCluster['page_id'],
                    'target_cluster_id' => $targetCluster['cluster_id'],
                    'anchor' => $targetCluster['primary'],
                    'alternatives' => self::alternatives($targetCluster, $tokens[$source], $normalizer),
                    'shared' => array_values(array_unique($shared)),
                    'score' => round($score, 4),
                ];
            }
        }

        $suggestions = [];
        foreach ($byTarget as $items) {
            $items = array_values($items);
            usort($items, static fn (array $a, array $b): int => [$b['score'], $a['source_page_id']] <=> [$a['score'], $b['source_page_id']]);
            array_push($suggestions, ...array_slice($items, 0, self::MAX_SOURCES_PER_TARGET));
        }
        usort($suggestions, static fn (array $a, array $b): int => [$b['score'], $a['target_page_id'], $a['source_page_id']] <=> [$a['score'], $b['target_page_id'], $b['source_page_id']]);

        return $suggestions;
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        return array_values(array_filter($parts, static fn (string $word): bool => mb_strlen($word) >= 2 && ! in_array($word, self::STOPWORDS, true)));
    }

    /**
     * Other keywords of the target that also fit the source page's topic,
     * so the owner can vary anchors across several links.
     *
     * @param  array{cluster_id: int, page_id: int, primary: string, keywords: list<string>}  $target
     * @param  array<string, true>  $sourceTokens
     * @return list<string>
     */
    private static function alternatives(array $target, array $sourceTokens, PersianKeywordNormalizer $normalizer): array
    {
        $primary = mb_strtolower($normalizer->normalize($target['primary']));
        $alternatives = [];
        foreach ($target['keywords'] as $keyword) {
            $text = $normalizer->normalize($keyword);
            if (mb_strtolower($text) === $primary || in_array($text, $alternatives, true)) {
                continue;
            }
            if (array_intersect_key(array_flip(self::words($text)), $sourceTokens) !== []) {
                $alternatives[] = $text;
            }
            if (count($alternatives) === 2) {
                break;
            }
        }

        return $alternatives;
    }
}
