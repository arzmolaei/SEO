<?php

namespace App\Domain\Keywords;

use LogicException;

final readonly class IntentRule
{
    /**
     * @param  list<string>  $phrases
     */
    public function __construct(
        public string $id,
        public KeywordIntent $intent,
        public IntentConfidence $confidence,
        public array $phrases,
        public string $evidence,
    ) {}

    /** @param array<string, mixed> $definition */
    public static function fromArray(string $id, array $definition): self
    {
        $intentValue = $definition['intent'] ?? null;
        $confidenceValue = $definition['confidence'] ?? null;
        $phrasesValue = $definition['phrases'] ?? null;
        $evidenceValue = $definition['evidence'] ?? null;

        if (! is_string($intentValue) || ! is_string($confidenceValue) || ! is_array($phrasesValue) || ! is_string($evidenceValue)) {
            throw new LogicException("Invalid intent rule [$id].");
        }

        $intent = KeywordIntent::tryFrom($intentValue);
        $confidence = IntentConfidence::tryFrom($confidenceValue);

        if ($intent === null || $confidence === null) {
            throw new LogicException("Invalid intent rule enum value [$id].");
        }

        $phrases = [];
        foreach ($phrasesValue as $phrase) {
            if (! is_string($phrase) || trim($phrase) === '') {
                throw new LogicException("Invalid intent rule phrase [$id].");
            }

            $phrases[] = trim($phrase);
        }

        if ($phrases === [] || trim($evidenceValue) === '') {
            throw new LogicException("Incomplete intent rule [$id].");
        }

        return new self($id, $intent, $confidence, $phrases, $evidenceValue);
    }

    public function matches(string $normalizedText): bool
    {
        $haystack = ' '.trim($normalizedText).' ';

        foreach ($this->phrases as $phrase) {
            if (str_contains($haystack, ' '.$phrase.' ')) {
                return true;
            }
        }

        return false;
    }
}
