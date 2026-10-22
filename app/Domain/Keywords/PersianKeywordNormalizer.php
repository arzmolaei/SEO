<?php

namespace App\Domain\Keywords;

use Normalizer;

final class PersianKeywordNormalizer
{
    public function normalize(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        if ($normalized === false) {
            $normalized = $value;
        }

        $normalized = strtr($normalized, [
            'ي' => 'ی',
            'ى' => 'ی',
            'ك' => 'ک',
            "\u{200C}" => ' ',
            "\u{200B}" => '',
            "\u{200D}" => '',
            "\u{2060}" => '',
            "\u{FEFF}" => '',
        ]);

        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+([،,؛;:!؟?)\]}])/u', '$1', $normalized) ?? $normalized;
        $normalized = preg_replace('/([([{])\s+/u', '$1', $normalized) ?? $normalized;

        return trim($normalized);
    }
}
