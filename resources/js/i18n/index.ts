export type Locale = 'fa' | 'en';
export type Direction = 'rtl' | 'ltr';

const messages = {
    fa: {
        'app.name': 'SEO OS',
        'common.continue': 'ادامه',
        'common.cancel': 'انصراف',
        'common.save': 'ذخیره',
    },
    en: {
        'app.name': 'SEO OS',
        'common.continue': 'Continue',
        'common.cancel': 'Cancel',
        'common.save': 'Save',
    },
} as const;

export type TranslationKey = keyof (typeof messages)['en'];

export function translate(locale: Locale, key: TranslationKey): string {
    return messages[locale][key] ?? messages.en[key];
}
