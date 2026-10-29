import type { Locale } from '@/i18n';

export type Totals = {
    clicks: number;
    impressions: number;
    ctr: number;
    position: number | null;
    days: number;
};

const tag = (locale: Locale) => (locale === 'fa' ? 'fa-IR' : 'en');

export function formatNumber(value: number, locale: Locale): string {
    return value.toLocaleString(tag(locale), { maximumFractionDigits: 1 });
}

export function formatPercent(value: number, locale: Locale): string {
    return value.toLocaleString(tag(locale), {
        style: 'percent',
        maximumFractionDigits: 1,
    });
}

/**
 * Change of a per-day rate between two imports, so a 28-day export and a
 * 90-day export are compared fairly. Null when there is nothing to compare.
 */
export function perDayChange(
    current: Totals,
    previous: Totals | null,
    metric: 'clicks' | 'impressions',
): number | null {
    if (!previous || previous[metric] === 0) return null;
    const before = previous[metric] / previous.days;
    const now = current[metric] / current.days;

    return (now - before) / before;
}

/** Suggested task titles for each kind of opportunity. */
export function taskTitle(
    kind: 'near_top' | 'low_ctr' | 'declining',
    key: string,
    locale: Locale,
): string {
    const fa = {
        near_top: `تقویت محتوا برای رسیدن به صدر: «${key}»`,
        low_ctr: `بازنویسی عنوان و توضیحات برای کلیک بیشتر: «${key}»`,
        declining: `بررسی افت کلیک صفحه: ${key}`,
    };
    const en = {
        near_top: `Strengthen content to reach the top: “${key}”`,
        low_ctr: `Rewrite title and description for more clicks: “${key}”`,
        declining: `Check the click drop of page: ${key}`,
    };

    return (locale === 'fa' ? fa : en)[kind].slice(0, 255);
}
