import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/hooks/use-locale';
import type { Totals } from '@/lib/search-console';
import {
    formatNumber,
    formatPercent,
    perDayChange,
    taskTitle,
} from '@/lib/search-console';

type Kind = 'queries' | 'pages';
type Sort = 'clicks' | 'impressions' | 'position';
type ImportRow = {
    id: number;
    kind: Kind;
    period_start: string;
    period_end: string;
    filename: string;
    rows: number;
    skipped: number;
};
type Summary = Record<
    Kind,
    { import: ImportRow; totals: Totals; previous: Totals | null } | null
>;
type Match = { keyword_id: number | null; page_id: number | null };
type Opportunity = Match & {
    key: string;
    clicks: number;
    impressions?: number;
    position?: number;
    missed_clicks?: number;
    previous_clicks?: number;
    change?: number;
};
type DataRow = {
    key: string;
    clicks: number;
    impressions: number;
    ctr: number;
    position: number;
};
type Paginated = {
    data: DataRow[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
type OpportunityKind = 'near_top' | 'low_ctr' | 'declining';

const today = () => new Date().toISOString().slice(0, 10);
const daysAgo = (days: number) =>
    new Date(Date.now() - days * 86400000).toISOString().slice(0, 10);

export default function Performance({
    filters,
    imports,
    summary,
    opportunities,
    rows,
    canEdit,
}: {
    filters: {
        kind: Kind;
        import: number | null;
        q: string | null;
        sort: Sort;
    };
    imports: ImportRow[];
    summary: Summary;
    opportunities: Record<OpportunityKind, Opportunity[]>;
    rows: Paginated | null;
    canEdit: boolean;
}) {
    const { projectContext } = usePage().props;
    const { t, locale } = useLocale();
    const [busy, setBusy] = useState(false);
    const [added, setAdded] = useState<string[]>([]);
    const projectBase = `/projects/${projectContext?.project.id}`;
    const base = `${projectBase}/performance`;
    const num = (value: number) => formatNumber(value, locale);
    const date = (value: string) =>
        new Date(`${value}T00:00:00`).toLocaleDateString(
            locale === 'fa' ? 'fa-IR' : 'en',
        );
    const fieldClass = 'rounded border bg-background p-2 text-sm';
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
    };
    const show = (next: Partial<typeof filters>) => {
        const merged = { ...filters, ...next };
        const query: Record<string, string> = { kind: merged.kind };
        if (merged.import) query.import = String(merged.import);
        if (merged.q) query.q = merged.q;
        if (merged.sort !== 'clicks') query.sort = merged.sort;
        router.get(base, query, { ...options, only: ['rows', 'filters'] });
    };
    const addTask = (kind: OpportunityKind, item: Opportunity) => {
        const id = `${kind}:${item.key}`;
        router.post(
            `${projectBase}/tasks`,
            {
                title: taskTitle(kind, item.key, locale),
                page_id: item.page_id,
            },
            {
                ...options,
                onSuccess: () => setAdded((current) => [...current, id]),
            },
        );
    };
    const remove = (item: ImportRow) => {
        if (window.confirm(t('perf.deleteConfirm'))) {
            router.delete(`${base}/imports/${item.id}`, options);
        }
    };
    const change = (value: number | null) =>
        value === null ? null : (
            <span className={value < 0 ? 'text-destructive' : 'text-primary'}>
                {value > 0 ? '+' : ''}
                {formatPercent(value, locale)}
            </span>
        );
    const searchSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const value = new FormData(event.currentTarget).get('q');
        show({ q: typeof value === 'string' ? value.trim() : null });
    };
    const opportunityList = (kind: OpportunityKind, items: Opportunity[]) => (
        <ul className="space-y-2">
            {items.map((item) => (
                <li
                    key={item.key}
                    data-opportunity={kind}
                    className="flex flex-wrap items-center justify-between gap-2 border-b pb-2 text-sm last:border-0"
                >
                    <div className="min-w-0 space-y-1">
                        <p
                            dir={kind === 'declining' ? 'ltr' : 'auto'}
                            className="font-medium break-all"
                        >
                            {item.key}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {kind === 'declining' ? (
                                <>
                                    {num(item.previous_clicks ?? 0)} →{' '}
                                    {num(item.clicks)} {t('perf.clicks')} (
                                    {change(item.change ?? null)})
                                </>
                            ) : (
                                <>
                                    {t('perf.position')}:{' '}
                                    {num(item.position ?? 0)} ·{' '}
                                    {num(item.impressions ?? 0)}{' '}
                                    {t('perf.impressions')}
                                    {kind === 'low_ctr' &&
                                        ` · ${num(item.missed_clicks ?? 0)} ${t('perf.missedClicks')}`}
                                </>
                            )}
                            {item.keyword_id !== null &&
                                ` · ${t('perf.inKeywords')}`}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {item.page_id !== null && (
                            <Link
                                href={`${projectBase}/pages/${item.page_id}`}
                                className="text-xs hover:underline"
                            >
                                {t('perf.openPage')}
                            </Link>
                        )}
                        {canEdit &&
                            (added.includes(`${kind}:${item.key}`) ? (
                                <span className="text-xs text-muted-foreground">
                                    {t('perf.taskAdded')}
                                </span>
                            ) : (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => addTask(kind, item)}
                                >
                                    {t('perf.addTask')}
                                </Button>
                            ))}
                    </div>
                </li>
            ))}
        </ul>
    );
    const hasData = summary.queries !== null || summary.pages !== null;

    return (
        <>
            <Head title={t('project.nav.performance')} />
            <ProjectWorkspaceShell>
                <div className="mx-auto max-w-5xl space-y-6" aria-busy={busy}>
                    <header className="space-y-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('project.nav.performance')}
                        </h1>
                        <p className="max-w-2xl text-sm leading-6 text-muted-foreground">
                            {t('perf.intro')}
                        </p>
                    </header>

                    {canEdit && (
                        <section
                            aria-labelledby="upload-title"
                            className="space-y-3 rounded-lg border p-4"
                        >
                            <h2
                                id="upload-title"
                                className="text-lg font-semibold"
                            >
                                {t('perf.upload')}
                            </h2>
                            <p className="text-sm leading-6 text-muted-foreground">
                                {t('perf.howTo')}
                            </p>
                            <Form
                                action={`${base}/imports`}
                                method="post"
                                encType="multipart/form-data"
                                disableWhileProcessing
                                resetOnSuccess
                                className="grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="space-y-1">
                                            <Label htmlFor="sc-file">
                                                {t('perf.file')}
                                            </Label>
                                            <Input
                                                id="sc-file"
                                                name="file"
                                                type="file"
                                                accept=".csv,text/csv"
                                            />
                                            <InputError message={errors.file} />
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="sc-start">
                                                {t('perf.periodStart')}
                                            </Label>
                                            <input
                                                id="sc-start"
                                                name="period_start"
                                                type="date"
                                                className={`${fieldClass} w-full`}
                                                defaultValue={daysAgo(90)}
                                                max={today()}
                                            />
                                            <InputError
                                                message={errors.period_start}
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="sc-end">
                                                {t('perf.periodEnd')}
                                            </Label>
                                            <input
                                                id="sc-end"
                                                name="period_end"
                                                type="date"
                                                className={`${fieldClass} w-full`}
                                                defaultValue={daysAgo(1)}
                                                max={today()}
                                            />
                                            <InputError
                                                message={errors.period_end}
                                            />
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {t('perf.import')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </section>
                    )}

                    {!hasData ? (
                        <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                            {t('perf.empty')}
                        </p>
                    ) : (
                        <>
                            <section className="grid gap-4 md:grid-cols-2">
                                {(['queries', 'pages'] as const).map((kind) => {
                                    const item = summary[kind];
                                    if (!item) return null;

                                    return (
                                        <div
                                            key={kind}
                                            data-summary={kind}
                                            className="space-y-3 rounded-lg border p-4"
                                        >
                                            <h2 className="font-semibold">
                                                {t(`perf.kind.${kind}`)}
                                            </h2>
                                            <p className="text-xs text-muted-foreground">
                                                {t('perf.period')}:{' '}
                                                {date(item.import.period_start)}{' '}
                                                – {date(item.import.period_end)}
                                            </p>
                                            <dl className="grid grid-cols-2 gap-3 text-sm">
                                                <div>
                                                    <dt className="text-muted-foreground">
                                                        {t('perf.clicks')}
                                                    </dt>
                                                    <dd className="text-lg font-semibold">
                                                        {num(
                                                            item.totals.clicks,
                                                        )}{' '}
                                                        <span className="text-xs">
                                                            {change(
                                                                perDayChange(
                                                                    item.totals,
                                                                    item.previous,
                                                                    'clicks',
                                                                ),
                                                            )}
                                                        </span>
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-muted-foreground">
                                                        {t('perf.impressions')}
                                                    </dt>
                                                    <dd className="text-lg font-semibold">
                                                        {num(
                                                            item.totals
                                                                .impressions,
                                                        )}{' '}
                                                        <span className="text-xs">
                                                            {change(
                                                                perDayChange(
                                                                    item.totals,
                                                                    item.previous,
                                                                    'impressions',
                                                                ),
                                                            )}
                                                        </span>
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-muted-foreground">
                                                        {t('perf.ctr')}
                                                    </dt>
                                                    <dd className="text-lg font-semibold">
                                                        {formatPercent(
                                                            item.totals.ctr,
                                                            locale,
                                                        )}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-muted-foreground">
                                                        {t('perf.position')}
                                                    </dt>
                                                    <dd className="text-lg font-semibold">
                                                        {item.totals
                                                            .position === null
                                                            ? '—'
                                                            : num(
                                                                  item.totals
                                                                      .position,
                                                              )}
                                                    </dd>
                                                </div>
                                            </dl>
                                            {item.previous && (
                                                <p className="text-xs text-muted-foreground">
                                                    {t('perf.vsPrevious')}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })}
                            </section>

                            <section
                                aria-labelledby="opportunities-title"
                                className="space-y-4"
                            >
                                <h2
                                    id="opportunities-title"
                                    className="text-lg font-semibold"
                                >
                                    {t('perf.opportunities')}
                                </h2>
                                {(
                                    [
                                        [
                                            'near_top',
                                            'perf.nearTop',
                                            'perf.nearTopHint',
                                        ],
                                        [
                                            'low_ctr',
                                            'perf.lowCtr',
                                            'perf.lowCtrHint',
                                        ],
                                        [
                                            'declining',
                                            'perf.declining',
                                            'perf.decliningHint',
                                        ],
                                    ] as const
                                ).map(([kind, title, hint]) => (
                                    <div
                                        key={kind}
                                        className="space-y-2 rounded-lg border p-4"
                                    >
                                        <h3 className="font-semibold">
                                            {t(title)} (
                                            {num(opportunities[kind].length)})
                                        </h3>
                                        <p className="text-sm leading-6 text-muted-foreground">
                                            {t(hint)}
                                        </p>
                                        {opportunities[kind].length > 0 ? (
                                            opportunityList(
                                                kind,
                                                opportunities[kind],
                                            )
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                {kind === 'declining' &&
                                                imports.filter(
                                                    (item) =>
                                                        item.kind === 'pages',
                                                ).length < 2
                                                    ? t(
                                                          'perf.decliningNeedsTwo',
                                                      )
                                                    : t('perf.noOpportunity')}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </section>
                        </>
                    )}

                    {imports.length > 0 && (
                        <section
                            aria-labelledby="data-title"
                            className="space-y-3"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2
                                    id="data-title"
                                    className="text-lg font-semibold"
                                >
                                    {t('perf.data')}
                                </h2>
                                <div className="flex flex-wrap gap-2">
                                    {(['queries', 'pages'] as const).map(
                                        (kind) => (
                                            <Button
                                                key={kind}
                                                size="sm"
                                                variant={
                                                    filters.kind === kind
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                onClick={() =>
                                                    show({
                                                        kind,
                                                        import: null,
                                                        q: null,
                                                    })
                                                }
                                            >
                                                {t(`perf.kind.${kind}`)}
                                            </Button>
                                        ),
                                    )}
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <select
                                    aria-label={t('perf.period')}
                                    className={fieldClass}
                                    value={filters.import ?? ''}
                                    onChange={(event) =>
                                        show({
                                            import: Number(event.target.value),
                                        })
                                    }
                                >
                                    {imports
                                        .filter(
                                            (item) =>
                                                item.kind === filters.kind,
                                        )
                                        .map((item) => (
                                            <option
                                                key={item.id}
                                                value={item.id}
                                            >
                                                {date(item.period_start)} –{' '}
                                                {date(item.period_end)}
                                            </option>
                                        ))}
                                </select>
                                <select
                                    aria-label={t('perf.sort')}
                                    className={fieldClass}
                                    value={filters.sort}
                                    onChange={(event) =>
                                        show({
                                            sort: event.target.value as Sort,
                                        })
                                    }
                                >
                                    <option value="clicks">
                                        {t('perf.clicks')}
                                    </option>
                                    <option value="impressions">
                                        {t('perf.impressions')}
                                    </option>
                                    <option value="position">
                                        {t('perf.position')}
                                    </option>
                                </select>
                                <form onSubmit={searchSubmit} role="search">
                                    <input
                                        name="q"
                                        type="search"
                                        aria-label={t('perf.search')}
                                        placeholder={t('perf.search')}
                                        defaultValue={filters.q ?? ''}
                                        className={fieldClass}
                                    />
                                </form>
                            </div>
                            {rows && rows.data.length > 0 ? (
                                <div className="overflow-x-auto rounded-lg border">
                                    <table className="w-full text-sm">
                                        <thead className="bg-muted/40 text-start">
                                            <tr>
                                                <th className="p-2 text-start">
                                                    {t(
                                                        `perf.kind.${filters.kind}`,
                                                    )}
                                                </th>
                                                <th className="p-2 text-start">
                                                    {t('perf.clicks')}
                                                </th>
                                                <th className="p-2 text-start">
                                                    {t('perf.impressions')}
                                                </th>
                                                <th className="p-2 text-start">
                                                    {t('perf.ctr')}
                                                </th>
                                                <th className="p-2 text-start">
                                                    {t('perf.position')}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {rows.data.map((row) => (
                                                <tr
                                                    key={row.key}
                                                    className="border-t"
                                                >
                                                    <td
                                                        className="max-w-xs p-2 break-all"
                                                        dir={
                                                            filters.kind ===
                                                            'pages'
                                                                ? 'ltr'
                                                                : 'auto'
                                                        }
                                                    >
                                                        {row.key}
                                                    </td>
                                                    <td className="p-2">
                                                        {num(row.clicks)}
                                                    </td>
                                                    <td className="p-2">
                                                        {num(row.impressions)}
                                                    </td>
                                                    <td className="p-2">
                                                        {formatPercent(
                                                            row.ctr,
                                                            locale,
                                                        )}
                                                    </td>
                                                    <td className="p-2">
                                                        {num(row.position)}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                                    {t('perf.noOpportunity')}
                                </p>
                            )}
                            {rows &&
                                (rows.prev_page_url || rows.next_page_url) && (
                                    <nav className="flex justify-between gap-2">
                                        <Button
                                            variant="outline"
                                            disabled={
                                                busy || !rows.prev_page_url
                                            }
                                            onClick={() =>
                                                rows.prev_page_url &&
                                                router.get(
                                                    rows.prev_page_url,
                                                    {},
                                                    {
                                                        ...options,
                                                        only: ['rows'],
                                                    },
                                                )
                                            }
                                        >
                                            {t('pages.prev')}
                                        </Button>
                                        <Button
                                            variant="outline"
                                            disabled={
                                                busy || !rows.next_page_url
                                            }
                                            onClick={() =>
                                                rows.next_page_url &&
                                                router.get(
                                                    rows.next_page_url,
                                                    {},
                                                    {
                                                        ...options,
                                                        only: ['rows'],
                                                    },
                                                )
                                            }
                                        >
                                            {t('pages.next')}
                                        </Button>
                                    </nav>
                                )}

                            <h3 className="pt-2 font-semibold">
                                {t('perf.history')}
                            </h3>
                            <ul className="divide-y rounded-lg border text-sm">
                                {imports.map((item) => (
                                    <li
                                        key={item.id}
                                        data-import={item.id}
                                        className="flex flex-wrap items-center justify-between gap-2 p-3"
                                    >
                                        <span>
                                            {t(`perf.kind.${item.kind}`)} ·{' '}
                                            {date(item.period_start)} –{' '}
                                            {date(item.period_end)} ·{' '}
                                            {num(item.rows)} {t('perf.rows')}
                                            {item.skipped > 0 &&
                                                ` · ${num(item.skipped)} ${t('perf.skipped')}`}
                                        </span>
                                        <span className="flex items-center gap-2">
                                            <span
                                                dir="ltr"
                                                className="text-xs text-muted-foreground"
                                            >
                                                {item.filename}
                                            </span>
                                            {canEdit && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={busy}
                                                    onClick={() => remove(item)}
                                                >
                                                    {t('perf.delete')}
                                                </Button>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
