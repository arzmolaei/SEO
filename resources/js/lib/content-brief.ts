import type { Locale } from '@/i18n';

export type BriefStatus = 'draft' | 'review' | 'published';
export type AgendaBucket =
    | 'overdue'
    | 'week'
    | 'month'
    | 'later'
    | 'unscheduled'
    | 'published';

export const briefStatusLabels: Record<Locale, Record<BriefStatus, string>> = {
    fa: { draft: 'پیش‌نویس', review: 'بازبینی', published: 'منتشرشده' },
    en: { draft: 'Draft', review: 'Review', published: 'Published' },
};

export const agendaLabels: Record<Locale, Record<AgendaBucket, string>> = {
    fa: {
        overdue: 'عقب‌افتاده',
        week: 'هفت روز آینده',
        month: 'تا یک ماه آینده',
        later: 'بعدتر',
        unscheduled: 'بدون تاریخ',
        published: 'منتشرشده در ۳۰ روز اخیر',
    },
    en: {
        overdue: 'Overdue',
        week: 'Next 7 days',
        month: 'Within a month',
        later: 'Later',
        unscheduled: 'No date',
        published: 'Published in the last 30 days',
    },
};

// What kind of content serves each search intent, for a beginner.
const intentGuide: Record<Locale, Record<string, string>> = {
    fa: {
        informational:
            'کاربر می‌خواهد چیزی یاد بگیرد: یک مقاله آموزشی یا راهنمای کامل با پاسخ روشن و مثال بنویسید.',
        commercial:
            'کاربر در حال مقایسه است: مقایسه، معرفی بهترین گزینه‌ها و نکات انتخاب بنویسید.',
        transactional:
            'کاربر آماده خرید یا اقدام است: صفحه محصول یا خدمت با قیمت، مزایا و دکمه اقدام روشن بسازید.',
        navigational:
            'کاربر دنبال یک برند یا صفحه خاص است: مطمئن شوید همان صفحه به‌روشنی پیدا می‌شود.',
        local: 'کاربر دنبال خدمت در یک محل است: نشانی، محدوده خدمت و راه تماس را واضح بنویسید.',
        mixed: 'نیت کاربر ترکیبی است: هم پاسخ آموزشی کوتاه بدهید و هم راه اقدام را نشان دهید.',
        unknown:
            'نیت هنوز مشخص نیست. نتایج گوگل برای کلمه اصلی را ببینید و همان نوع محتوا را بسازید.',
    },
    en: {
        informational:
            'The searcher wants to learn: write a guide or tutorial with clear answers and examples.',
        commercial:
            'The searcher is comparing: write comparisons, best options and buying advice.',
        transactional:
            'The searcher is ready to act: build a product or service page with price, benefits and a clear call to action.',
        navigational:
            'The searcher wants a specific brand or page: make sure that page is easy to find.',
        local: 'The searcher wants a local service: state the address, service area and contact clearly.',
        mixed: 'Mixed intent: give a short answer and also show how to act.',
        unknown:
            'Intent is not known yet. Look at Google’s results for the primary keyword and make the same kind of content.',
    },
};

export function intentGuidance(intent: string, locale: Locale): string {
    return intentGuide[locale][intent] ?? intentGuide[locale].unknown;
}

type BriefForText = {
    title: string;
    primary_keyword: string;
    secondary_keywords: string[];
    headings: string[];
    notes: string | null;
    intent: string;
};

/** A plain-text brief the owner can paste into a writing tool or send. */
export function briefAsText(brief: BriefForText, locale: Locale): string {
    const fa = locale === 'fa';
    const lines = [
        brief.title,
        '',
        `${fa ? 'کلمه کلیدی اصلی' : 'Primary keyword'}: ${brief.primary_keyword}`,
    ];
    if (brief.secondary_keywords.length > 0) {
        lines.push(
            `${fa ? 'کلمات مرتبط' : 'Related keywords'}: ${brief.secondary_keywords.join(fa ? '، ' : ', ')}`,
        );
    }
    lines.push(
        `${fa ? 'نوع محتوا' : 'Content type'}: ${intentGuidance(brief.intent, locale)}`,
    );
    if (brief.headings.length > 0) {
        lines.push('', fa ? 'سرفصل‌ها:' : 'Headings:');
        lines.push(...brief.headings.map((heading) => `- ${heading}`));
    }
    if (brief.notes) {
        lines.push('', fa ? 'یادداشت:' : 'Notes:', brief.notes);
    }

    return lines.join('\n');
}
