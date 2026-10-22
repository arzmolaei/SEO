<?php

namespace App\Domain\Keywords;

final readonly class KeywordIntentClassification
{
    /**
     * @param list<string> $matchedRuleIds
     * @param list<string> $evidence
     */
    public function __construct(
        public string $originalText,
        public string $normalizedText,
        public KeywordIntent $intent,
        public IntentConfidence $confidence,
        public array $matchedRuleIds,
        public array $evidence,
    ) {
    }

    /**
     * @return array{
     *     original_text:string,
     *     normalized_text:string,
     *     intent:string,
     *     confidence:string,
     *     matched_rule_ids:list<string>,
     *     evidence:list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'original_text' => $this->originalText,
            'normalized_text' => $this->normalizedText,
            'intent' => $this->intent->value,
            'confidence' => $this->confidence->value,
            'matched_rule_ids' => $this->matchedRuleIds,
            'evidence' => $this->evidence,
        ];
    }
}
