import type { Locale } from '@/i18n';

export type ChecklistStatus = 'pass' | 'fix' | 'na';

type ItemCopy = { label: string; fix: string };

// Kept in sync with App\Domain\Pages\OnPageChecklist (keys and limits).
const copy: Record<Locale, Record<string, ItemCopy>> = {
    fa: {
        target: {
            label: 'خوشه هدف دارد',
            fix: 'این صفحه هنوز به هیچ خوشه‌ای اختصاص داده نشده است. در بخش کلمات کلیدی، تب «اختصاص صفحه»، یک خوشه به این صفحه بدهید.',
        },
        single_target: {
            label: 'فقط یک خوشه هدف دارد',
            fix: 'چند خوشه به این صفحه اشاره می‌کنند و صفحه‌ها با هم رقابت می‌کنند. خوشه‌ها را ادغام کنید یا برای یکی صفحه دیگری بسازید.',
        },
        title_present: {
            label: 'عنوان سئو نوشته شده',
            fix: 'عنوان سئو همان متنی است که در نتایج گوگل دیده می‌شود. یک عنوان روشن بنویسید.',
        },
        title_length: {
            label: 'طول عنوان مناسب است (۳۰ تا ۶۵ نویسه)',
            fix: 'عنوان خیلی کوتاه یا خیلی بلند است. آن را بین ۳۰ تا ۶۵ نویسه نگه دارید تا در گوگل کامل دیده شود.',
        },
        title_keyword: {
            label: 'کلمه کلیدی اصلی در عنوان آمده',
            fix: 'کلمه کلیدی اصلی خوشه را ترجیحاً در ابتدای عنوان بیاورید.',
        },
        title_unique: {
            label: 'عنوان تکراری نیست',
            fix: 'صفحه دیگری از سایت همین عنوان را دارد. برای هر صفحه عنوان متفاوتی بنویسید.',
        },
        meta_present: {
            label: 'توضیحات متا نوشته شده',
            fix: 'توضیحات متا متن کوتاه زیر عنوان در نتایج گوگل است. یک جمله جذاب درباره محتوای صفحه بنویسید.',
        },
        meta_length: {
            label: 'طول توضیحات مناسب است (۷۰ تا ۱۶۰ نویسه)',
            fix: 'توضیحات را بین ۷۰ تا ۱۶۰ نویسه نگه دارید.',
        },
        meta_keyword: {
            label: 'کلمه کلیدی اصلی در توضیحات آمده',
            fix: 'کلمه کلیدی اصلی را به‌طور طبیعی در توضیحات بیاورید.',
        },
        h1_present: {
            label: 'تیتر اصلی (H1) نوشته شده',
            fix: 'هر صفحه به یک تیتر اصلی (H1) نیاز دارد که موضوع صفحه را می‌گوید.',
        },
        h1_keyword: {
            label: 'کلمه کلیدی اصلی در H1 آمده',
            fix: 'کلمه کلیدی اصلی را در تیتر اصلی صفحه بیاورید.',
        },
        url_present: {
            label: 'نشانی صفحه ثبت شده',
            fix: 'این صفحه موجود است ولی نشانی‌اش ثبت نشده است.',
        },
        url_short: {
            label: 'نشانی کوتاه و ساده است',
            fix: 'نشانی کوتاه با حداکثر چند سطح بهتر است؛ از نشانی‌های طولانی و تودرتو پرهیز کنید.',
        },
    },
    en: {
        target: {
            label: 'Has a target cluster',
            fix: 'This page is not mapped to any cluster yet. Map a cluster to it in Keywords → Mapping.',
        },
        single_target: {
            label: 'Targeted by only one cluster',
            fix: 'Several clusters target this page, so your pages compete. Merge the clusters or give one of them another page.',
        },
        title_present: {
            label: 'SEO title written',
            fix: 'The SEO title is what Google shows in results. Write a clear title.',
        },
        title_length: {
            label: 'Title length is right (30–65 characters)',
            fix: 'Keep the title between 30 and 65 characters so Google shows it in full.',
        },
        title_keyword: {
            label: 'Primary keyword in the title',
            fix: 'Put the cluster’s primary keyword in the title, ideally near the start.',
        },
        title_unique: {
            label: 'Title is unique',
            fix: 'Another page has the same title. Give every page its own title.',
        },
        meta_present: {
            label: 'Meta description written',
            fix: 'The meta description is the short text under the title in Google. Write one inviting sentence about the page.',
        },
        meta_length: {
            label: 'Description length is right (70–160 characters)',
            fix: 'Keep the description between 70 and 160 characters.',
        },
        meta_keyword: {
            label: 'Primary keyword in the description',
            fix: 'Use the primary keyword naturally in the description.',
        },
        h1_present: {
            label: 'Main heading (H1) written',
            fix: 'Every page needs one main heading (H1) that states its topic.',
        },
        h1_keyword: {
            label: 'Primary keyword in the H1',
            fix: 'Use the primary keyword in the page’s main heading.',
        },
        url_present: {
            label: 'Page address recorded',
            fix: 'This page exists but its address is not recorded.',
        },
        url_short: {
            label: 'Address is short and simple',
            fix: 'Prefer a short address with only a few levels; avoid long nested addresses.',
        },
    },
};

export function checklistCopy(key: string, locale: Locale): ItemCopy | null {
    return copy[locale][key] ?? null;
}

export const statusLabels: Record<Locale, Record<string, string>> = {
    fa: { todo: 'شروع نشده', in_progress: 'در حال کار', done: 'تمام شده' },
    en: { todo: 'Not started', in_progress: 'In progress', done: 'Done' },
};
