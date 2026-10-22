<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\IntentConfidence;
use App\Domain\Keywords\IntentRule;
use App\Domain\Keywords\KeywordIntent;
use App\Domain\Keywords\KeywordIntentClassification;
use App\Domain\Keywords\PersianKeywordNormalizer;

class ClassifyKeywordIntent
{
    public function __construct(private PersianKeywordNormalizer $normalizer) {}

    public function classify(string $originalText): KeywordIntentClassification
    {
        $normalizedText = $this->normalizer->normalize($originalText);
        $matchedRules = $this->matchedRules($normalizedText);

        if ($matchedRules === []) {
            return new KeywordIntentClassification(
                $originalText,
                $normalizedText,
                KeywordIntent::Unknown,
                IntentConfidence::Low,
                [],
                [],
            );
        }

        $intents = [];
        foreach ($matchedRules as $rule) {
            $intents[$rule->intent->value] = $rule->intent;
        }

        $intent = count($intents) > 1
            ? KeywordIntent::Mixed
            : array_values($intents)[0];
        $confidence = count($intents) > 1
            ? IntentConfidence::Medium
            : $this->highestConfidence($matchedRules);

        return new KeywordIntentClassification(
            $originalText,
            $normalizedText,
            $intent,
            $confidence,
            array_map(static fn (IntentRule $rule): string => $rule->id, $matchedRules),
            array_map(static fn (IntentRule $rule): string => $rule->evidence, $matchedRules),
        );
    }

    /** @return list<IntentRule> */
    private function matchedRules(string $normalizedText): array
    {
        $definitions = config('keyword-intelligence.intent.rules', []);

        if (! is_array($definitions)) {
            return [];
        }

        $matched = [];
        foreach ($definitions as $id => $definition) {
            if (! is_string($id) || ! is_array($definition)) {
                continue;
            }

            $rule = IntentRule::fromArray($id, $definition);
            if ($rule->matches($normalizedText)) {
                $matched[] = $rule;
            }
        }

        return $matched;
    }

    /** @param list<IntentRule> $rules */
    private function highestConfidence(array $rules): IntentConfidence
    {
        $rank = [
            IntentConfidence::Low->value => 1,
            IntentConfidence::Medium->value => 2,
            IntentConfidence::High->value => 3,
        ];
        $highest = IntentConfidence::Low;

        foreach ($rules as $rule) {
            if ($rank[$rule->confidence->value] > $rank[$highest->value]) {
                $highest = $rule->confidence;
            }
        }

        return $highest;
    }
}
