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
        'auth.registerDescription':
            'حساب اولیه را برای مدیریت پروژه‌های سئو بسازید.',
        'auth.haveAccount': 'از قبل حساب دارید؟',
        'onboarding.title': 'فضای کاری سئو را آماده کنیم',
        'onboarding.description':
            'در مرحله بعد فضای کاری، پروژه و تنظیمات اولیه شما ساخته می‌شود.',
        'onboarding.pending': 'راه‌اندازی فضای کاری در مرحله بعدی تکمیل می‌شود.',
        'project.nav.label': 'فضای پروژه',
        'project.nav.overview': 'نمای کلی',
        'project.nav.keywords': 'کلمات کلیدی',
        'project.nav.pages': 'صفحات',
        'project.nav.siteAudit': 'بررسی فنی سایت',
        'project.nav.content': 'محتوا',
        'project.nav.internalLinks': 'لینک‌سازی داخلی',
        'project.nav.tasks': 'وظایف',
        'project.nav.performance': 'عملکرد',
        'project.nav.reports': 'گزارش‌ها',
        'project.nav.integrations': 'اتصال‌ها',
        'project.nav.settings': 'تنظیمات پروژه',
        'project.search': 'جستجو در پروژه',
        'project.mode.guided': 'راهنما',
        'project.mode.expert': 'حرفه‌ای',
        'project.health.setup': 'در حال راه‌اندازی',
        'project.sync.none': 'هنوز همگام‌سازی نشده',
        'project.overview.title': 'مرکز فرماندهی',
        'project.overview.description':
            'مهم‌ترین اقدام بعدی پروژه را از اینجا پیدا می‌کنید.',
        'project.overview.emptyTitle': 'هنوز داده‌ای برای اولویت‌بندی نداریم',
        'project.overview.emptyDescription':
            'با واردکردن کلمات کلیدی یا اتصال داده‌های پروژه، پیشنهادهای قابل اقدام اینجا ظاهر می‌شوند.',
        'project.state.loading': 'در حال آماده‌سازی داده‌های پروژه…',
        'project.state.errorTitle': 'داده‌های این بخش بارگذاری نشد',
        'project.state.errorDescription':
            'دوباره تلاش کنید. اگر مشکل ادامه داشت، وضعیت سیستم و اتصال‌ها را بررسی کنید.',
        'project.section.emptyTitle': 'این بخش آماده‌ی دریافت داده است',
        'project.section.emptyDescription':
            'زیرساخت این ماژول آماده است و قابلیت‌های تخصصی آن در مرحله مربوط به خودش اضافه می‌شود.',
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
        'onboarding.pending':
            'Workspace setup will be completed in the next step.',
        'project.nav.label': 'Project workspace',
        'project.nav.overview': 'Overview',
        'project.nav.keywords': 'Keywords',
        'project.nav.pages': 'Pages',
        'project.nav.siteAudit': 'Site Audit',
        'project.nav.content': 'Content',
        'project.nav.internalLinks': 'Internal Links',
        'project.nav.tasks': 'Tasks',
        'project.nav.performance': 'Performance',
        'project.nav.reports': 'Reports',
        'project.nav.integrations': 'Integrations',
        'project.nav.settings': 'Project Settings',
        'project.search': 'Search project',
        'project.mode.guided': 'Guided',
        'project.mode.expert': 'Expert',
        'project.health.setup': 'Setting up',
        'project.sync.none': 'Not synced yet',
        'project.overview.title': 'Command Center',
        'project.overview.description':
            'Find the highest-priority next action for this project here.',
        'project.overview.emptyTitle': 'No project data to prioritize yet',
        'project.overview.emptyDescription':
            'Import keywords or connect project data and actionable recommendations will appear here.',
        'project.state.loading': 'Preparing project data…',
        'project.state.errorTitle': 'This section could not be loaded',
        'project.state.errorDescription':
            'Try again. If the issue continues, check system health and integrations.',
        'project.section.emptyTitle': 'This section is ready for data',
        'project.section.emptyDescription':
            'The module foundation is ready; its specialized workflow will be added in the relevant implementation task.',
    },
} as const;

export type TranslationKey = keyof (typeof messages)['en'];

export function translate(locale: Locale, key: TranslationKey): string {
    return messages[locale][key] ?? messages.en[key];
}
