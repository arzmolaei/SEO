import { usePage } from '@inertiajs/react';

import {
    translate,
    type Direction,
    type Locale,
    type TranslationKey,
} from '@/i18n';

export function useLocale(): {
    locale: Locale;
    direction: Direction;
    t: (key: TranslationKey) => string;
} {
    const { locale, direction } = usePage().props;

    return {
        locale,
        direction,
        t: (key) => translate(locale, key),
    };
}
