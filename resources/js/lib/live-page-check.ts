import type { Locale } from '@/i18n';
import { crawlErrorLabel } from '@/lib/crawl-errors';

export type LiveProblem = { key: string; value: string | number | null };

type Copy = { label: string; fix: string };

const faDigits = (value: string | number) =>
    typeof value === 'number' ? value.toLocaleString('fa-IR') : value;

// Kept in sync with App\Domain\Pages\LivePageCheck problem keys.
const copy: Record<Locale, Record<string, Copy>> = {
    fa: {
        fetch_failed: {
            label: 'صفحه خوانده نشد',
            fix: 'هنگام بررسی، صفحه پاسخ درستی نداد. آدرس را در مرورگر باز کنید و دوباره بررسی کنید.',
        },
        http_error: {
            label: 'صفحه خطا برمی‌گرداند',
            fix: 'گوگل صفحه‌ای را که خطا می‌دهد (مثلاً ۴۰۴ یعنی پیدا نشد) نمایش نمی‌دهد. صفحه را برگردانید یا آدرس را اصلاح کنید.',
        },
        redirected: {
            label: 'به آدرس دیگری منتقل می‌شود',
            fix: 'این آدرس به صفحه دیگری هدایت می‌شود. اگر عمدی است، آدرس صفحه را در این‌جا به آدرس نهایی تغییر دهید.',
        },
        not_html: {
            label: 'صفحه وب معمولی نیست',
            fix: 'این آدرس یک فایل است، نه صفحه وب. برای سئو یک صفحه HTML بسازید.',
        },
        noindex: {
            label: 'به گوگل گفته شده این صفحه را نشان ندهد (noindex)',
            fix: 'تا وقتی noindex در صفحه است، در نتایج گوگل نمی‌آید. اگر این صفحه باید دیده شود، noindex را از تنظیمات سایت بردارید.',
        },
        canonical_elsewhere: {
            label: 'canonical به صفحه دیگری اشاره می‌کند',
            fix: 'تگ canonical به گوگل می‌گوید نسخه اصلی صفحه دیگری است. اگر این صفحه اصلی است، canonical را به خودش برگردانید.',
        },
        title_missing: {
            label: 'عنوان در سایت نیست',
            fix: 'صفحه در سایت عنوان (title) ندارد. عنوانی را که این‌جا نوشته‌اید در سایت قرار دهید.',
        },
        title_differs: {
            label: 'عنوان سایت با عنوان برنامه‌ریزی‌شده فرق دارد',
            fix: 'عنوانی که در سایت است با عنوانی که این‌جا نوشته‌اید یکی نیست. یکی را انتخاب کنید و هر دو را یکسان کنید.',
        },
        meta_missing: {
            label: 'توضیحات متا در سایت نیست',
            fix: 'صفحه توضیحات متا ندارد و گوگل خودش متنی انتخاب می‌کند. توضیحاتی را که این‌جا نوشته‌اید در سایت قرار دهید.',
        },
        meta_differs: {
            label: 'توضیحات سایت با برنامه فرق دارد',
            fix: 'توضیحات متای سایت با متن این‌جا یکی نیست. آن‌ها را یکسان کنید.',
        },
        h1_missing: {
            label: 'سرتیتر اصلی (H1) در سایت نیست',
            fix: 'صفحه هیچ سرتیتر H1 ندارد. یک سرتیتر اصلی که موضوع صفحه را می‌گوید اضافه کنید.',
        },
        h1_multiple: {
            label: 'چند سرتیتر H1 دارد',
            fix: 'بهتر است هر صفحه فقط یک H1 داشته باشد. بقیه را به H2 تبدیل کنید.',
        },
        h1_differs: {
            label: 'H1 سایت با برنامه فرق دارد',
            fix: 'سرتیتر اصلی سایت با سرتیتری که این‌جا نوشته‌اید یکی نیست. آن‌ها را یکسان کنید.',
        },
        thin_content: {
            label: 'متن صفحه کم است',
            fix: 'صفحه کمتر از ۳۰۰ کلمه متن دارد. برای پاسخ کامل به جستجوی کاربر، متن مفیدتری بنویسید.',
        },
    },
    en: {
        fetch_failed: {
            label: 'Page could not be read',
            fix: 'The page did not answer properly during the check. Open it in a browser and check again.',
        },
        http_error: {
            label: 'Page returns an error',
            fix: 'Google does not show pages that return an error (404 means not found). Restore the page or fix its address.',
        },
        redirected: {
            label: 'Redirects to another address',
            fix: 'This address forwards to another page. If that is intended, change the page address here to the final one.',
        },
        not_html: {
            label: 'Not a normal web page',
            fix: 'This address is a file, not a web page. Create an HTML page for SEO.',
        },
        noindex: {
            label: 'Google is told not to show it (noindex)',
            fix: 'While noindex is present the page cannot appear in Google. Remove it in your site settings if the page should be found.',
        },
        canonical_elsewhere: {
            label: 'Canonical points to another page',
            fix: 'The canonical tag tells Google another page is the main version. Point it back to this page if this is the main one.',
        },
        title_missing: {
            label: 'No title on the site',
            fix: 'The live page has no title. Put the title you wrote here on the site.',
        },
        title_differs: {
            label: 'Live title differs from the planned title',
            fix: 'The title on the site is not the one you wrote here. Pick one and make both the same.',
        },
        meta_missing: {
            label: 'No meta description on the site',
            fix: 'Without a meta description Google picks its own snippet. Put the description you wrote here on the site.',
        },
        meta_differs: {
            label: 'Live description differs from the plan',
            fix: 'The meta description on the site is not the one written here. Make them the same.',
        },
        h1_missing: {
            label: 'No main heading (H1) on the site',
            fix: 'The page has no H1. Add one main heading that states the topic.',
        },
        h1_multiple: {
            label: 'Several H1 headings',
            fix: 'A page should have one H1. Turn the others into H2 headings.',
        },
        h1_differs: {
            label: 'Live H1 differs from the plan',
            fix: 'The main heading on the site is not the one written here. Make them the same.',
        },
        thin_content: {
            label: 'Little text on the page',
            fix: 'The page has fewer than 300 words. Write more useful text that fully answers the search.',
        },
    },
};

export function liveProblemCopy(key: string, locale: Locale): Copy | null {
    return copy[locale][key] ?? null;
}

/** The live value behind a problem, readable for the owner (or null). */
export function liveProblemDetail(
    problem: LiveProblem,
    locale: Locale,
): string | null {
    if (problem.value === null) return null;
    if (problem.key === 'fetch_failed') {
        return (
            crawlErrorLabel(String(problem.value), locale) ??
            String(problem.value)
        );
    }
    if (problem.key === 'thin_content') {
        return locale === 'fa'
            ? `${faDigits(problem.value)} کلمه`
            : `${problem.value} words`;
    }

    return locale === 'fa'
        ? String(faDigits(problem.value))
        : String(problem.value);
}
