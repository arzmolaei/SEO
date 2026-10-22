<?php

namespace App\Domain\Keywords;

final readonly class KeywordText
{
    private function __construct(
        public string $original,
        public string $normalized,
    ) {}

    public static function fromOriginal(
        string $original,
        PersianKeywordNormalizer $normalizer,
    ): self {
        return new self(
            original: $original,
            normalized: $normalizer->normalize($original),
        );
    }
}
