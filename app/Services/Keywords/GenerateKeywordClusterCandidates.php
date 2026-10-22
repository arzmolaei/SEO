<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\KeywordClusterCandidate;
use App\Domain\Keywords\KeywordIntent;
use App\Domain\Keywords\PersianKeywordNormalizer;
use InvalidArgumentException;

class GenerateKeywordClusterCandidates
{
    public function __construct(private PersianKeywordNormalizer $normalizer) {}

    /**
     * @param  iterable<array{id:int, project_id:int, text:string, intent:KeywordIntent}>  $rows
     * @return list<KeywordClusterCandidate>
     */
    public function generate(iterable $rows): array
    {
        $stopWords = array_fill_keys(config('keyword-intelligence.clustering.stop_words', []), true);
        $buckets = [];
        $seen = [];
        foreach ($rows as $row) {
            if ($row['id'] < 1 || $row['project_id'] < 1 || isset($seen[$row['id']])) {
                throw new InvalidArgumentException('Candidate input IDs must be positive and unique.');
            }
            $seen[$row['id']] = true;
            $text = $this->normalizer->normalize($row['text']);
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_values(array_unique(array_filter($tokens, fn (string $token): bool => ! isset($stopWords[$token]))));
            if (count($tokens) < 2) {
                continue;
            }
            $anchor = array_slice($tokens, 0, 2);
            $keyTokens = $anchor;
            sort($keyTokens, SORT_STRING);
            $key = $row['project_id'].':'.json_encode($keyTokens, JSON_THROW_ON_ERROR);
            $features = $tokens;
            sort($features, SORT_STRING);
            $buckets[$key][] = ['id' => $row['id'], 'project_id' => $row['project_id'], 'text' => $text,
                'intent' => $row['intent'], 'tokens' => $tokens, 'features' => $features];
        }
        $candidates = [];
        foreach ($buckets as $members) {
            if (count($members) < 2) {
                continue;
            }
            usort($members, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            $primaryOrder = $members;
            usort($primaryOrder, fn (array $a, array $b): int => [count($a['tokens']), $a['id']] <=> [count($b['tokens']), $b['id']]);
            $primary = $primaryOrder[0];
            $intents = array_values(array_unique(array_map(fn (array $r): string => $r['intent']->value, $members)));
            $compatible = count($intents) === 1 && ! in_array($intents[0], ['unknown', 'mixed'], true);
            $exact = count(array_unique(array_map(fn (array $r): string => json_encode($r['features'], JSON_THROW_ON_ERROR), $members))) === 1;
            $contained = true;
            foreach ($members as $member) {
                if (array_diff($primary['features'], $member['features']) !== []) {
                    $contained = false;
                }
            }
            $components = ['shared_anchor' => 60, 'exact_features' => $exact ? 25 : 0, 'intent' => $compatible ? 15 : 0];
            $score = array_sum($components);
            $candidates[] = new KeywordClusterCandidate(
                $primary['project_id'], $primary['text'], $primary['id'], array_column($members, 'id'),
                count($intents) === 1 ? KeywordIntent::from($intents[0]) : KeywordIntent::Mixed,
                $score === 100 ? IntentConfidence::High : IntentConfidence::Medium, $score,
                array_map(fn (array $r): array => ['id' => $r['id'], 'normalized_text' => $r['text'], 'intent' => $r['intent']->value], $members),
                ['algorithm' => 'meaningful-prefix-v1', 'shared_tokens' => array_slice($primary['tokens'], 0, 2),
                    'relation' => $exact ? 'exact_features' : ($contained ? 'prefix_containment' : 'shared_anchor'),
                    'intent_compatibility' => $compatible ? 'compatible' : (count($intents) > 1 ? 'conflicting' : 'unknown'),
                    'score_components' => $components, 'primary_reason' => 'shortest meaningful phrase, then lowest canonical ID'],
            );
        }
        usort($candidates, fn (KeywordClusterCandidate $a, KeywordClusterCandidate $b): int => [$a->projectId, $a->primaryKeywordId] <=> [$b->projectId, $b->primaryKeywordId]);

        return $candidates;
    }
}
