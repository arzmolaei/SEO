<?php

namespace Tests\Unit\Keywords;

use App\Domain\Keywords\KeywordText;
use App\Domain\Keywords\PersianKeywordNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PersianKeywordNormalizerTest extends TestCase
{
    #[DataProvider('normalizationCases')]
    public function test_it_normalizes_persian_keyword_text_deterministically(string $input, string $expected): void
    {
        $normalizer = new PersianKeywordNormalizer;

        $this->assertSame($expected, $normalizer->normalize($input));
    }

    public function test_normalization_is_idempotent(): void
    {
        $normalizer = new PersianKeywordNormalizer;
        $corpus = [
            '  كلمات   كليدي  ',
            "نرم\u{200C}افزار\u{200B} سئو\u{200D}",
            'سئو ، سایت ! ( تست )',
            "Cafe\u{0301}",
            'C++ .NET seo-tools #1',
        ];

        foreach ($corpus as $input) {
            $once = $normalizer->normalize($input);

            $this->assertSame($once, $normalizer->normalize($once), $input);
        }
    }

    public function test_keyword_value_object_preserves_original_text_while_exposing_normalized_identity(): void
    {
        $normalizer = new PersianKeywordNormalizer;
        $original = "  كلمات\u{200C}كليدي  ";

        $keyword = KeywordText::fromOriginal($original, $normalizer);

        $this->assertSame($original, $keyword->original);
        $this->assertSame('کلمات کلیدی', $keyword->normalized);
    }

    /** @return array<string, array{string, string}> */
    public static function normalizationCases(): array
    {
        return [
            'arabic yeh and kaf' => ['كلمات كليدي', 'کلمات کلیدی'],
            'alef maqsura yeh variant' => ['على', 'علی'],
            'unicode nfc' => ["Cafe\u{0301}", 'Café'],
            'half space and zero width characters' => [
                "نرم\u{200C}افزار\u{200B} سئو\u{200D}",
                'نرم افزار سئو',
            ],
            'unicode whitespace collapse' => [" \t سئو\u{00A0}  سایت \n ", 'سئو سایت'],
            'safe punctuation spacing' => ['سئو ، سایت ! ( تست )', 'سئو، سایت! (تست)'],
            'meaningful punctuation is preserved' => ['C++ .NET seo-tools #1', 'C++ .NET seo-tools #1'],
        ];
    }
}
