<?php

namespace App\Support;

final class LocaleContext
{
    public const PERSIAN = 'fa';

    public const ENGLISH = 'en';

    public const RTL = 'rtl';

    public const LTR = 'ltr';

    public static function locale(?string $locale): string
    {
        if ($locale === null || trim($locale) === '') {
            return self::PERSIAN;
        }

        $normalized = strtolower(str_replace('_', '-', trim($locale)));

        return str_starts_with($normalized, self::ENGLISH)
            ? self::ENGLISH
            : self::PERSIAN;
    }

    public static function direction(?string $locale): string
    {
        return self::locale($locale) === self::PERSIAN
            ? self::RTL
            : self::LTR;
    }

    /**
     * @return array{locale: 'fa'|'en', direction: 'rtl'|'ltr'}
     */
    public static function from(?string $locale): array
    {
        $resolvedLocale = self::locale($locale);

        return [
            'locale' => $resolvedLocale,
            'direction' => self::direction($resolvedLocale),
        ];
    }
}
