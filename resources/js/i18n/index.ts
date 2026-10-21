export type Locale = 'fa' | 'en';
export type Direction = 'rtl' | 'ltr';

const messages = {
    fa: {
        'app.name': 'SEO OS',
        'common.continue': 'ادامه',
        'common.cancel': 'انصراف',
        'common.save': 'ذخیره',
        'auth.email': 'ایمیل',
        'auth.password': 'رمز عبور',
        'auth.confirmPassword': 'تکرار رمز عبور',
        'auth.name': 'نام',
        'auth.remember': 'مرا به خاطر بسپار',
        'auth.forgotPassword': 'رمز عبور را فراموش کرده‌اید؟',
        'auth.login': 'ورود',
        'auth.loginTitle': 'ورود به SEO OS',
        'auth.loginDescription': 'برای ادامه وارد حساب خود شوید.',
        'auth.noAccount': 'حساب ندارید؟',
        'auth.signup': 'ساخت حساب',
        'auth.register': 'ایجاد حساب',
        'auth.registerTitle': 'شروع کار با SEO OS',
        'auth.registerDescription': 'حساب اولیه را برای مدیریت پروژه‌های سئو بسازید.',
        'auth.haveAccount': 'از قبل حساب دارید؟',
        'onboarding.title': 'فضای کاری سئو را آماده کنیم',
        'onboarding.description':
            'در مرحله بعد فضای کاری، پروژه و تنظیمات اولیه شما ساخته می‌شود.',
        'onboarding.pending': 'راه‌اندازی فضای کاری در مرحله بعدی تکمیل می‌شود.',
    },
    en: {
        'app.name': 'SEO OS',
        'common.continue': 'Continue',
        'common.cancel': 'Cancel',
        'common.save': 'Save',
        'auth.email': 'Email',
        'auth.password': 'Password',
        'auth.confirmPassword': 'Confirm password',
        'auth.name': 'Name',
        'auth.remember': 'Remember me',
        'auth.forgotPassword': 'Forgot your password?',
        'auth.login': 'Log in',
        'auth.loginTitle': 'Log in to SEO OS',
        'auth.loginDescription': 'Sign in to continue to your SEO workspace.',
        'auth.noAccount': "Don't have an account?",
        'auth.signup': 'Create account',
        'auth.register': 'Create account',
        'auth.registerTitle': 'Get started with SEO OS',
        'auth.registerDescription':
            'Create the initial account for your SEO workspace.',
        'auth.haveAccount': 'Already have an account?',
        'onboarding.title': 'Set up your SEO workspace',
        'onboarding.description':
            'Next we will create your workspace, first project, and core defaults.',
        'onboarding.pending': 'Workspace setup will be completed in the next step.',
    },
} as const;

export type TranslationKey = keyof (typeof messages)['en'];

export function translate(locale: Locale, key: TranslationKey): string {
    return messages[locale][key] ?? messages.en[key];
}
