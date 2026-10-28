import { Link } from '@inertiajs/react';
import type { Locale } from '@/i18n';

export type ChangeRow = {
    id: number;
    normalized_url: string;
    change_type: string;
    before: { value: unknown } | null;
    after: { value: unknown } | null;
};

type Paged<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
};

const copy = {
    fa: {
        title: 'تغییرات نسبت به خزش قبلی',
        since: (id: number) => `مقایسه با خزش #${id}`,
        in_progress: 'مقایسه با خزش قبلی در حال انجام است.',
        no_baseline:
            'خزش کامل قبلی با همین تنظیمات وجود ندارد؛ این خزش مبنای مقایسه‌های بعدی است.',
        scope_changed:
            'تنظیمات این خزش (نشانی شروع، دامنه‌ها، پیشوندها یا سقف‌ها) با خزش‌های قبلی فرق دارد؛ برای جلوگیری از نتیجه نادرست مقایسه نشد.',
        notAuthoritative:
            'این خزش به سقف تعداد نشانی رسید؛ نشانی‌های حذف‌شده قابل اثبات نیستند و گزارش نشدند.',
        all: 'همه',
        none: 'تغییری ثبت نشد.',
        url: 'نشانی',
        type: 'نوع تغییر',
        before: 'قبل',
        after: 'بعد',
        empty: '—',
        previous: 'قبلی',
        next: 'بعدی',
        pagination: 'صفحه‌بندی تغییرات',
        types: {
            added: 'نشانی جدید',
            removed: 'حذف‌شده',
            status: 'کد وضعیت',
            title: 'عنوان',
            meta_description: 'توضیحات متا',
            canonical: 'canonical',
            indexability: 'قابلیت ایندکس',
            internal_links: 'تعداد لینک داخلی',
        } as Record<string, string>,
    },
    en: {
        title: 'Changes since the previous crawl',
        since: (id: number) => `Compared with run #${id}`,
        in_progress: 'Comparison with the previous crawl is in progress.',
        no_baseline:
            'No earlier completed crawl has the same settings; this crawl becomes the baseline.',
        scope_changed:
            'This crawl’s settings (start URL, hosts, prefixes or limits) differ from earlier crawls, so it was not compared to avoid misleading results.',
        notAuthoritative:
            'This crawl reached its URL limit; removed URLs cannot be proven and are not reported.',
        all: 'All',
        none: 'No changes recorded.',
        url: 'URL',
        type: 'Change',
        before: 'Before',
        after: 'After',
        empty: '—',
        previous: 'Previous',
        next: 'Next',
        pagination: 'Change pagination',
        types: {
            added: 'New URL',
            removed: 'Removed',
            status: 'Status code',
            title: 'Title',
            meta_description: 'Meta description',
            canonical: 'Canonical',
            indexability: 'Indexability',
            internal_links: 'Internal links',
        } as Record<string, string>,
    },
};

function display(value: unknown, empty: string): string {
    if (value === null || value === undefined) return empty;
    if (typeof value === 'boolean') return value ? '✓' : '✗';
    if (typeof value === 'string' || typeof value === 'number')
        return String(value);

    return JSON.stringify(value);
}

export function CrawlChanges({
    runId,
    status,
    baselineRunId,
    counts,
    changes,
    base,
    activeType,
    locale,
}: {
    runId: number;
    status: string | null;
    baselineRunId: number | null;
    counts: Record<string, number | boolean> | null;
    changes: Paged<ChangeRow> | null;
    base: string;
    activeType: string | undefined;
    locale: Locale;
}) {
    const t = copy[locale];
    if (status === null) return null;
    const href = (type?: string) =>
        `${base}?run=${runId}${type ? `&change_type=${type}` : ''}`;
    const typeCounts = Object.entries(counts ?? {}).filter(
        (entry): entry is [string, number] => typeof entry[1] === 'number',
    );

    return (
        <section
            className="space-y-3 rounded border p-4"
            aria-labelledby="crawl-changes-title"
        >
            <h2 id="crawl-changes-title" className="text-lg font-semibold">
                {t.title}
            </h2>
            {status !== 'compared' ? (
                <p role="status" aria-live="polite">
                    {status === 'in_progress'
                        ? t.in_progress
                        : status === 'scope_changed'
                          ? t.scope_changed
                          : t.no_baseline}
                </p>
            ) : (
                <>
                    {baselineRunId !== null && (
                        <p className="text-sm text-muted-foreground">
                            {t.since(baselineRunId)}
                        </p>
                    )}
                    {counts?.removed_authoritative === false && (
                        <p role="note">{t.notAuthoritative}</p>
                    )}
                    <nav
                        className="flex flex-wrap gap-3 text-sm"
                        aria-label={t.type}
                    >
                        <Link
                            href={href()}
                            aria-current={!activeType ? 'page' : undefined}
                            className="underline"
                        >
                            {t.all}
                        </Link>
                        {typeCounts.map(([type, total]) => (
                            <Link
                                key={type}
                                href={href(type)}
                                aria-current={
                                    activeType === type ? 'page' : undefined
                                }
                                className="underline"
                            >
                                {t.types[type] ?? type}: {total}
                            </Link>
                        ))}
                    </nav>
                    {!changes || changes.data.length === 0 ? (
                        <p>{t.none}</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-start text-sm">
                                <caption className="sr-only">{t.title}</caption>
                                <thead>
                                    <tr>
                                        <th
                                            scope="col"
                                            className="p-2 text-start"
                                        >
                                            {t.url}
                                        </th>
                                        <th
                                            scope="col"
                                            className="p-2 text-start"
                                        >
                                            {t.type}
                                        </th>
                                        <th
                                            scope="col"
                                            className="p-2 text-start"
                                        >
                                            {t.before}
                                        </th>
                                        <th
                                            scope="col"
                                            className="p-2 text-start"
                                        >
                                            {t.after}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {changes.data.map((change) => (
                                        <tr
                                            key={change.id}
                                            className="border-t"
                                        >
                                            <td
                                                className="p-2 break-all"
                                                dir="ltr"
                                            >
                                                {change.normalized_url}
                                            </td>
                                            <td className="p-2">
                                                {t.types[change.change_type] ??
                                                    change.change_type}
                                            </td>
                                            <td className="p-2" dir="auto">
                                                {display(
                                                    change.before?.value,
                                                    t.empty,
                                                )}
                                            </td>
                                            <td className="p-2" dir="auto">
                                                {display(
                                                    change.after?.value,
                                                    t.empty,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {changes && (
                        <nav className="flex gap-4" aria-label={t.pagination}>
                            {changes.prev_page_url && (
                                <Link href={changes.prev_page_url}>
                                    {t.previous}
                                </Link>
                            )}
                            {changes.next_page_url && (
                                <Link href={changes.next_page_url}>
                                    {t.next}
                                </Link>
                            )}
                        </nav>
                    )}
                </>
            )}
        </section>
    );
}
