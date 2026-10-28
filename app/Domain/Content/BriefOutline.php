<?php

namespace App\Domain\Content;

use App\Domain\Keywords\PersianKeywordNormalizer;

/**
 * Suggested headings for a content brief, built only from the cluster's own
 * keywords (no templates, no external data). Question keywords become an FAQ
 * block at the end; the rest become section headings, strongest first.
 */
final class BriefOutline
{
    public const MAX_SECTIONS = 8;

    public const MAX_QUESTIONS = 6;

    public const FAQ_HEADING = 'پرسش‌های متداول';

    private const QUESTION_WORDS = ['چیست', 'چیه', 'چگونه', 'چطور', 'چرا', 'کدام', 'کدوم', 'آیا', 'چقدر', 'چند', 'کجا', 'کی', 'what', 'how', 'why', 'which', 'when', 'where'];

    /**
     * @param  list<string>  $secondary  cluster keywords other than the primary, strongest first
     * @return list<string>
     */
    public static function headings(string $primary, array $secondary): array
    {
        $normalizer = new PersianKeywordNormalizer;
        $seen = [mb_strtolower($normalizer->normalize($primary)) => true];
        $sections = [];
        $questions = [];
        foreach ($secondary as $keyword) {
            $text = $normalizer->normalize($keyword);
            $key = mb_strtolower($text);
            if ($text === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (self::isQuestion($key)) {
                if (count($questions) < self::MAX_QUESTIONS) {
                    $questions[] = rtrim($text, '?؟').'؟';
                }
            } elseif (count($sections) < self::MAX_SECTIONS) {
                $sections[] = $text;
            }
        }

        return $questions === [] ? $sections : [...$sections, self::FAQ_HEADING, ...$questions];
    }

    public static function isQuestion(string $keyword): bool
    {
        if (str_contains($keyword, '?') || str_contains($keyword, '؟')) {
            return true;
        }
        $words = preg_split('/\s+/u', mb_strtolower(trim($keyword))) ?: [];

        return array_intersect($words, self::QUESTION_WORDS) !== [];
    }
}
