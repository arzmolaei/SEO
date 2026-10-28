import type { Locale } from '@/i18n';

export type GuideStatus = 'done' | 'todo' | 'attention' | 'waiting';

export type GuideStep = {
    key: string;
    status: GuideStatus;
    count: number;
    total: number;
    href: string;
};

type StepCopy = {
    title: string;
    explain: string;
    action: string;
    detail: (count: number, total: number) => string;
};

const fa = (n: number) => n.toLocaleString('fa-IR');

const copy: Record<Locale, Record<string, StepCopy>> = {
    fa: {
        import: {
            title: 'وارد کردن کلمات کلیدی',
            explain:
                'کلمات کلیدی، عبارت‌هایی هستند که مردم در گوگل جستجو می‌کنند. فهرست کلمات مرتبط با کسب‌وکارتان را از فایل CSV یا با چسباندن متن وارد کنید.',
            action: 'وارد کردن کلمات',
            detail: (count) =>
                count > 0 ? `${fa(count)} کلمه وارد شده` : 'هنوز کلمه‌ای ندارید',
        },
        intent: {
            title: 'بررسی هدف جستجو',
            explain:
                'هدف جستجو یعنی کاربر با این عبارت دنبال چه چیزی است: یادگرفتن، خرید یا رسیدن به یک سایت خاص. پیشنهادهای سیستم را تأیید یا اصلاح کنید.',
            action: 'بررسی پیشنهادها',
            detail: (count) =>
                count > 0
                    ? `${fa(count)} مورد منتظر بررسی`
                    : 'همه موارد بررسی شده',
        },
        clusters: {
            title: 'گروه‌بندی کلمات (خوشه‌ها)',
            explain:
                'کلماتی که یک نیاز را نشان می‌دهند در یک خوشه قرار می‌گیرند تا برای هر نیاز یک صفحه بسازید، نه برای هر کلمه یک صفحه.',
            action: 'بررسی خوشه‌ها',
            detail: (count, total) =>
                total === 0
                    ? 'هنوز خوشه‌ای ساخته نشده'
                    : count > 0
                      ? `${fa(count)} مورد از ${fa(total)} خوشه منتظر بررسی`
                      : `${fa(total)} خوشه بررسی شده`,
        },
        mapping: {
            title: 'اختصاص خوشه به صفحه',
            explain:
                'برای هر خوشه مشخص کنید کدام صفحه سایت (موجود یا برنامه‌ریزی‌شده) باید برای آن رتبه بگیرد.',
            action: 'اختصاص صفحه',
            detail: (count, total) =>
                count > 0
                    ? `${fa(count)} خوشه از ${fa(total)} هنوز صفحه ندارد`
                    : `همه ${fa(total)} خوشه صفحه دارند`,
        },
        conflicts: {
            title: 'رفع تداخل صفحه‌ها',
            explain:
                'اگر دو خوشه به یک صفحه برسند، صفحه‌های سایت با هم رقابت می‌کنند (همنوع‌خواری). این تداخل‌ها را بررسی و رفع کنید.',
            action: 'رفع تداخل‌ها',
            detail: (count) =>
                count > 0
                    ? `${fa(count)} تداخل نیازمند تصمیم`
                    : 'تداخلی وجود ندارد',
        },
        ready: {
            title: 'آماده‌سازی خوشه‌ها برای کار',
            explain:
                'وقتی صفحه هر خوشه مشخص و تداخل‌ها رفع شد، خوشه را «آماده» علامت بزنید تا وارد برنامه کار شود.',
            action: 'علامت‌گذاری آماده',
            detail: (count, total) =>
                count > 0
                    ? `${fa(count)} خوشه از ${fa(total)} هنوز آماده نیست`
                    : `همه ${fa(total)} خوشه آماده‌اند`,
        },
        export: {
            title: 'دریافت برنامه سئو',
            explain:
                'خروجی CSV فارسی برنامه سئو را دریافت کنید تا فهرست صفحه‌ها و کلمات هدف را کنار خود داشته باشید.',
            action: 'دریافت فایل',
            detail: (count) =>
                count > 0 ? 'برنامه دریافت شده است' : 'هنوز دریافت نشده',
        },
        site_check: {
            title: 'بررسی صفحه‌های سایت خودتان',
            explain:
                'صفحه‌های سایت خودتان را بخوانید تا ببینید عنوان، توضیحات، H1 و وضعیت هر صفحه درست است یا نه.',
            action: 'بررسی سایت',
            detail: (count) =>
                count > 0 ? 'بررسی سایت انجام شده' : 'هنوز بررسی نشده',
        },
    },
    en: {
        import: {
            title: 'Import keywords',
            explain:
                'Keywords are the phrases people search for. Import the list for your business from a CSV file or by pasting text.',
            action: 'Import keywords',
            detail: (count) =>
                count > 0 ? `${count} keywords imported` : 'No keywords yet',
        },
        intent: {
            title: 'Review search intent',
            explain:
                'Search intent is what the searcher wants: to learn, to buy or to reach a specific site. Confirm or correct the suggestions.',
            action: 'Review suggestions',
            detail: (count) =>
                count > 0 ? `${count} waiting for review` : 'All reviewed',
        },
        clusters: {
            title: 'Group keywords (clusters)',
            explain:
                'Keywords that express one need belong in one cluster, so you build one page per need instead of one page per keyword.',
            action: 'Review clusters',
            detail: (count, total) =>
                total === 0
                    ? 'No clusters yet'
                    : count > 0
                      ? `${count} of ${total} clusters waiting for review`
                      : `${total} clusters reviewed`,
        },
        mapping: {
            title: 'Map clusters to pages',
            explain:
                'For each cluster, choose which page (existing or planned) should rank for it.',
            action: 'Map pages',
            detail: (count, total) =>
                count > 0
                    ? `${count} of ${total} clusters have no page`
                    : `All ${total} clusters have a page`,
        },
        conflicts: {
            title: 'Fix page conflicts',
            explain:
                'When two clusters target the same page, your pages compete with each other (cannibalization). Review and resolve these conflicts.',
            action: 'Resolve conflicts',
            detail: (count) =>
                count > 0
                    ? `${count} conflicts need a decision`
                    : 'No conflicts',
        },
        ready: {
            title: 'Mark clusters ready',
            explain:
                'Once a cluster has its page and no conflicts, mark it ready so it enters your work plan.',
            action: 'Mark ready',
            detail: (count, total) =>
                count > 0
                    ? `${count} of ${total} clusters not ready`
                    : `All ${total} clusters ready`,
        },
        export: {
            title: 'Download your SEO plan',
            explain:
                'Download the Persian CSV of your SEO plan to keep the list of pages and target keywords at hand.',
            action: 'Download',
            detail: (count) =>
                count > 0 ? 'Plan downloaded' : 'Not downloaded yet',
        },
        site_check: {
            title: 'Check your own pages',
            explain:
                'Read your own site pages to see whether each page’s title, description, H1 and status are right.',
            action: 'Check site',
            detail: (count) => (count > 0 ? 'Site checked' : 'Not checked yet'),
        },
    },
};

export function guideStepCopy(key: string, locale: Locale): StepCopy | null {
    return copy[locale][key] ?? null;
}

export const guideStatusLabel: Record<Locale, Record<GuideStatus, string>> = {
    fa: {
        done: 'انجام شد',
        todo: 'نوبت این مرحله',
        attention: 'نیاز به رسیدگی',
        waiting: 'بعد از مراحل قبلی',
    },
    en: {
        done: 'Done',
        todo: 'To do',
        attention: 'Needs attention',
        waiting: 'After earlier steps',
    },
};
