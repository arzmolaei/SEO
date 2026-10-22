import { Head, Link, router, usePage } from '@inertiajs/react';
import { type FormEvent, useMemo, useState } from 'react';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { useLocale } from '@/hooks/use-locale';

type KeywordRow = {
    id: number;
    display_text: string;
    normalized_text: string;
    latest_volume: string | null;
    latest_volume_source: string | null;
    created_at: string | null;
};

type KeywordPaginator = {
    data: KeywordRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type KeywordFilters = {
    q: string | null;
    source: string | null;
    volume_operator: string | null;
    volume: number | null;
    sort: string;
    direction: 'asc' | 'desc';
    per_page: number;
};

export default function Keywords({
    keywords,
    filters,
}: {
    keywords: KeywordPaginator;
    filters: KeywordFilters;
}) {
    const { locale, t } = useLocale();
    const path = usePage().url.split('?')[0];
    const [search, setSearch] = useState(filters.q ?? '');
    const [source, setSource] = useState(filters.source ?? '');
    const [volumeOperator, setVolumeOperator] = useState(
        filters.volume_operator ?? '',
    );
    const [volume, setVolume] = useState(
        filters.volume === null ? '' : String(filters.volume),
    );
    const numberFormatter = useMemo(
        () => new Intl.NumberFormat(locale === 'fa' ? 'fa-IR' : 'en-US'),
        [locale],
    );

    const navigate = (overrides: Record<string, string | number | null>) => {
        const query: Record<string, string | number> = {
            per_page: filters.per_page,
            sort: filters.sort,
            direction: filters.direction,
        };

        const values = {
            q: search.trim() || null,
            source: source.trim() || null,
            volume_operator: volumeOperator || null,
            volume: volume.trim() || null,
            ...overrides,
        };

        for (const [key, value] of Object.entries(values)) {
            if (value !== null && value !== '') {
                query[key] = value;
            }
        }

        router.get(path, query, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        navigate({});
    };

    const toggleSort = (sort: 'keyword' | 'volume') => {
        navigate({
            sort,
            direction:
                filters.sort === sort && filters.direction === 'asc'
                    ? 'desc'
                    : 'asc',
        });
    };

    return (
        <>
            <Head title={t('project.nav.keywords')} />
            <ProjectWorkspaceShell>
                <div className="mx-auto w-full max-w-7xl space-y-5">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {t('project.nav.keywords')}
                                </h1>
                                <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                                    {t('keywords.description')}
                                </p>
                            </div>
                            <div className="rounded-md border px-3 py-2 text-sm text-muted-foreground">
                                {t('keywords.total')}:{' '}
                                {numberFormatter.format(keywords.total)}
                            </div>
                        </div>
                    </div>

                    <form
                        onSubmit={submit}
                        className="grid gap-3 rounded-lg border bg-card p-4 md:grid-cols-2 xl:grid-cols-5"
                    >
                        <label className="space-y-1 text-sm xl:col-span-2">
                            <span>{t('keywords.search')}</span>
                            <input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                className="h-9 w-full rounded-md border bg-background px-3 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                type="search"
                            />
                        </label>
                        <label className="space-y-1 text-sm">
                            <span>{t('keywords.source')}</span>
                            <input
                                value={source}
                                onChange={(event) =>
                                    setSource(event.target.value)
                                }
                                className="h-9 w-full rounded-md border bg-background px-3 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                placeholder={t('keywords.allSources')}
                            />
                        </label>
                        <label className="space-y-1 text-sm">
                            <span>{t('keywords.operator')}</span>
                            <select
                                value={volumeOperator}
                                onChange={(event) =>
                                    setVolumeOperator(event.target.value)
                                }
                                className="h-9 w-full rounded-md border bg-background px-3 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <option value="">—</option>
                                <option value="gte">≥</option>
                                <option value="lte">≤</option>
                                <option value="gt">&gt;</option>
                                <option value="lt">&lt;</option>
                                <option value="eq">=</option>
                            </select>
                        </label>
                        <label className="space-y-1 text-sm">
                            <span>{t('keywords.volume')}</span>
                            <div className="flex gap-2">
                                <input
                                    value={volume}
                                    onChange={(event) =>
                                        setVolume(event.target.value)
                                    }
                                    className="h-9 min-w-0 flex-1 rounded-md border bg-background px-3 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    min="0"
                                    inputMode="numeric"
                                    type="number"
                                />
                                <button
                                    type="submit"
                                    className="h-9 shrink-0 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground"
                                >
                                    {t('keywords.apply')}
                                </button>
                            </div>
                        </label>
                    </form>

                    <div className="overflow-hidden rounded-lg border bg-card">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[720px] border-collapse text-sm">
                                <thead className="bg-muted/50 text-start text-muted-foreground">
                                    <tr>
                                        <th className="sticky start-0 z-10 bg-muted/95 px-4 py-3 text-start font-medium">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    toggleSort('keyword')
                                                }
                                                className="font-medium text-foreground"
                                            >
                                                {t('keywords.keyword')}
                                            </button>
                                        </th>
                                        <th className="px-4 py-3 text-start font-medium">
                                            {t('keywords.normalized')}
                                        </th>
                                        <th className="px-4 py-3 text-start font-medium">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    toggleSort('volume')
                                                }
                                                className="font-medium text-foreground"
                                            >
                                                {t('keywords.latestVolume')}
                                            </button>
                                        </th>
                                        <th className="px-4 py-3 text-start font-medium">
                                            {t('keywords.latestSource')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {keywords.data.map((keyword) => (
                                        <tr
                                            key={keyword.id}
                                            className="border-t hover:bg-muted/30"
                                        >
                                            <td className="sticky start-0 bg-card px-4 py-3 font-medium">
                                                {keyword.display_text}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {keyword.normalized_text}
                                            </td>
                                            <td className="px-4 py-3 tabular-nums">
                                                {keyword.latest_volume === null
                                                    ? '—'
                                                    : numberFormatter.format(
                                                          Number(
                                                              keyword.latest_volume,
                                                          ),
                                                      )}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {keyword.latest_volume_source ??
                                                    '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {keywords.data.length === 0 ? (
                            <div className="border-t px-4 py-10 text-center text-sm text-muted-foreground">
                                {t('keywords.empty')}
                            </div>
                        ) : null}

                        <div className="flex items-center justify-between gap-3 border-t px-4 py-3 text-sm">
                            <Link
                                href={keywords.prev_page_url ?? '#'}
                                preserveScroll
                                className={
                                    keywords.prev_page_url === null
                                        ? 'pointer-events-none opacity-40'
                                        : 'font-medium hover:underline'
                                }
                            >
                                {t('keywords.previous')}
                            </Link>
                            <span className="text-muted-foreground">
                                {numberFormatter.format(keywords.current_page)}{' '}
                                / {numberFormatter.format(keywords.last_page)}
                            </span>
                            <Link
                                href={keywords.next_page_url ?? '#'}
                                preserveScroll
                                className={
                                    keywords.next_page_url === null
                                        ? 'pointer-events-none opacity-40'
                                        : 'font-medium hover:underline'
                                }
                            >
                                {t('keywords.next')}
                            </Link>
                        </div>
                    </div>
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
