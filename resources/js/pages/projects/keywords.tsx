import { Head, router, usePage } from '@inertiajs/react';
import { Columns3, LoaderCircle } from 'lucide-react';
import {
    type FormEvent,
    type KeyboardEvent,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useLocale } from '@/hooks/use-locale';

type KeywordStatus = 'review' | 'ready';

type KeywordRow = {
    id: number;
    display_text: string;
    normalized_text: string;
    status: KeywordStatus;
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
    status: KeywordStatus | null;
    volume_operator: string | null;
    volume: number | null;
    sort: string;
    direction: 'asc' | 'desc';
    per_page: number;
};

type OptionalColumn =
    | 'normalized'
    | 'status'
    | 'volume'
    | 'source'
    | 'createdAt';

const columnStorageKey = 'seo-os.keyword-grid.columns.v1';
const defaultColumnVisibility: Record<OptionalColumn, boolean> = {
    normalized: true,
    status: true,
    volume: true,
    source: true,
    createdAt: false,
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
    const [status, setStatus] = useState(filters.status ?? '');
    const [volumeOperator, setVolumeOperator] = useState(
        filters.volume_operator ?? '',
    );
    const [volume, setVolume] = useState(
        filters.volume === null ? '' : String(filters.volume),
    );
    const [visibleColumns, setVisibleColumns] = useState(
        defaultColumnVisibility,
    );
    const [isLoading, setIsLoading] = useState(false);
    const [loadError, setLoadError] = useState(false);
    const [activeRow, setActiveRow] = useState(0);
    const rowRefs = useRef<Array<HTMLTableRowElement | null>>([]);

    const numberFormatter = useMemo(
        () => new Intl.NumberFormat(locale === 'fa' ? 'fa-IR' : 'en-US'),
        [locale],
    );
    const dateFormatter = useMemo(
        () =>
            new Intl.DateTimeFormat(locale === 'fa' ? 'fa-IR' : 'en-US', {
                dateStyle: 'medium',
            }),
        [locale],
    );

    useEffect(() => {
        const stored = window.localStorage.getItem(columnStorageKey);

        if (stored === null) {
            return;
        }

        try {
            const parsed = JSON.parse(stored) as Partial<
                Record<OptionalColumn, unknown>
            >;

            setVisibleColumns((current) => ({
                normalized:
                    typeof parsed.normalized === 'boolean'
                        ? parsed.normalized
                        : current.normalized,
                status:
                    typeof parsed.status === 'boolean'
                        ? parsed.status
                        : current.status,
                volume:
                    typeof parsed.volume === 'boolean'
                        ? parsed.volume
                        : current.volume,
                source:
                    typeof parsed.source === 'boolean'
                        ? parsed.source
                        : current.source,
                createdAt:
                    typeof parsed.createdAt === 'boolean'
                        ? parsed.createdAt
                        : current.createdAt,
            }));
        } catch {
            return;
        }
    }, []);

    useEffect(() => {
        window.localStorage.setItem(
            columnStorageKey,
            JSON.stringify(visibleColumns),
        );
    }, [visibleColumns]);

    useEffect(() => {
        setActiveRow(0);
        rowRefs.current = [];
    }, [keywords.current_page]);

    const visitCallbacks = {
        onStart: () => {
            setLoadError(false);
            setIsLoading(true);
        },
        onFinish: () => setIsLoading(false),
        onError: () => setLoadError(true),
    };

    const navigate = (overrides: Record<string, string | number | null>) => {
        const query: Record<string, string | number> = {
            per_page: filters.per_page,
            sort: filters.sort,
            direction: filters.direction,
        };
        const hasVolumeFilter =
            volumeOperator !== '' && volume.trim() !== '';
        const values = {
            q: search.trim() || null,
            source: source.trim() || null,
            status: status || null,
            volume_operator: hasVolumeFilter ? volumeOperator : null,
            volume: hasVolumeFilter ? volume.trim() : null,
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
            ...visitCallbacks,
        });
    };

    const visitPage = (url: string | null) => {
        if (url === null || isLoading) {
            return;
        }

        router.visit(url, {
            preserveScroll: true,
            preserveState: true,
            ...visitCallbacks,
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

    const toggleColumn = (column: OptionalColumn, checked: boolean) => {
        setVisibleColumns((current) => ({
            ...current,
            [column]: checked,
        }));
    };

    const focusRow = (index: number) => {
        if (keywords.data.length === 0) {
            return;
        }

        const boundedIndex = Math.max(
            0,
            Math.min(index, keywords.data.length - 1),
        );
        setActiveRow(boundedIndex);
        requestAnimationFrame(() => rowRefs.current[boundedIndex]?.focus());
    };

    const handleRowKeyDown = (
        event: KeyboardEvent<HTMLTableRowElement>,
        index: number,
    ) => {
        const target =
            event.key === 'ArrowDown'
                ? index + 1
                : event.key === 'ArrowUp'
                  ? index - 1
                  : event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? keywords.data.length - 1
                      : null;

        if (target === null) {
            return;
        }

        event.preventDefault();
        focusRow(target);
    };

    const renderDate = (value: string | null) => {
        if (value === null) {
            return '—';
        }

        const date = new Date(value);

        return Number.isNaN(date.getTime()) ? '—' : dateFormatter.format(date);
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
                            <div className="flex items-center gap-2">
                                {isLoading ? (
                                    <span className="inline-flex items-center gap-2 text-sm text-muted-foreground">
                                        <LoaderCircle className="size-4 animate-spin" />
                                        {t('keywords.loading')}
                                    </span>
                                ) : null}
                                <div className="rounded-md border px-3 py-2 text-sm text-muted-foreground">
                                    {t('keywords.total')}:{' '}
                                    {numberFormatter.format(keywords.total)}
                                </div>
                            </div>
                        </div>
                    </div>

                    <form
                        onSubmit={submit}
                        className="grid gap-3 rounded-lg border bg-card p-4 md:grid-cols-2 xl:grid-cols-6"
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
                            <span>{t('keywords.status')}</span>
                            <select
                                value={status}
                                onChange={(event) =>
                                    setStatus(
                                        event.target.value as KeywordStatus | '',
                                    )
                                }
                                className="h-9 w-full rounded-md border bg-background px-3 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <option value="">
                                    {t('keywords.allStatuses')}
                                </option>
                                <option value="review">
                                    {t('keywords.statusReview')}
                                </option>
                                <option value="ready">
                                    {t('keywords.statusReady')}
                                </option>
                            </select>
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
                                    disabled={isLoading}
                                    className="h-9 shrink-0 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-50"
                                >
                                    {t('keywords.apply')}
                                </button>
                            </div>
                        </label>
                    </form>

                    {loadError ? (
                        <div
                            role="alert"
                            className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm"
                        >
                            <span>{t('keywords.loadError')}</span>
                            <button
                                type="button"
                                onClick={() => navigate({})}
                                className="font-medium underline underline-offset-4"
                            >
                                {t('keywords.retry')}
                            </button>
                        </div>
                    ) : null}

                    <div
                        className="overflow-hidden rounded-lg border bg-card"
                        aria-busy={isLoading}
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-2.5">
                            <span className="text-xs text-muted-foreground">
                                {t('keywords.keyboardHint')}
                            </span>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        type="button"
                                        className="inline-flex h-8 items-center gap-2 rounded-md border bg-background px-3 text-sm font-medium hover:bg-muted"
                                    >
                                        <Columns3 className="size-4" />
                                        {t('keywords.columns')}
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    className="w-52"
                                >
                                    <DropdownMenuLabel>
                                        {t('keywords.columns')}
                                    </DropdownMenuLabel>
                                    <DropdownMenuCheckboxItem
                                        checked={visibleColumns.normalized}
                                        onCheckedChange={(checked) =>
                                            toggleColumn(
                                                'normalized',
                                                checked === true,
                                            )
                                        }
                                        onSelect={(event) =>
                                            event.preventDefault()
                                        }
                                    >
                                        {t('keywords.normalized')}
                                    </DropdownMenuCheckboxItem>
                                    <DropdownMenuCheckboxItem
                                        checked={visibleColumns.status}
                                        onCheckedChange={(checked) =>
                                            toggleColumn(
                                                'status',
                                                checked === true,
                                            )
                                        }
                                        onSelect={(event) =>
                                            event.preventDefault()
                                        }
                                    >
                                        {t('keywords.status')}
                                    </DropdownMenuCheckboxItem>
                                    <DropdownMenuCheckboxItem
                                        checked={visibleColumns.volume}
                                        onCheckedChange={(checked) =>
                                            toggleColumn(
                                                'volume',
                                                checked === true,
                                            )
                                        }
                                        onSelect={(event) =>
                                            event.preventDefault()
                                        }
                                    >
                                        {t('keywords.latestVolume')}
                                    </DropdownMenuCheckboxItem>
                                    <DropdownMenuCheckboxItem
                                        checked={visibleColumns.source}
                                        onCheckedChange={(checked) =>
                                            toggleColumn(
                                                'source',
                                                checked === true,
                                            )
                                        }
                                        onSelect={(event) =>
                                            event.preventDefault()
                                        }
                                    >
                                        {t('keywords.latestSource')}
                                    </DropdownMenuCheckboxItem>
                                    <DropdownMenuCheckboxItem
                                        checked={visibleColumns.createdAt}
                                        onCheckedChange={(checked) =>
                                            toggleColumn(
                                                'createdAt',
                                                checked === true,
                                            )
                                        }
                                        onSelect={(event) =>
                                            event.preventDefault()
                                        }
                                    >
                                        {t('keywords.createdAt')}
                                    </DropdownMenuCheckboxItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>

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
                                        {visibleColumns.normalized ? (
                                            <th className="px-4 py-3 text-start font-medium">
                                                {t('keywords.normalized')}
                                            </th>
                                        ) : null}
                                        {visibleColumns.status ? (
                                            <th className="px-4 py-3 text-start font-medium">
                                                {t('keywords.status')}
                                            </th>
                                        ) : null}
                                        {visibleColumns.volume ? (
                                            <th className="px-4 py-3 text-start font-medium">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        toggleSort('volume')
                                                    }
                                                    className="font-medium text-foreground"
                                                >
                                                    {t(
                                                        'keywords.latestVolume',
                                                    )}
                                                </button>
                                            </th>
                                        ) : null}
                                        {visibleColumns.source ? (
                                            <th className="px-4 py-3 text-start font-medium">
                                                {t('keywords.latestSource')}
                                            </th>
                                        ) : null}
                                        {visibleColumns.createdAt ? (
                                            <th className="px-4 py-3 text-start font-medium">
                                                {t('keywords.createdAt')}
                                            </th>
                                        ) : null}
                                    </tr>
                                </thead>
                                <tbody>
                                    {keywords.data.map((keyword, index) => (
                                        <tr
                                            key={keyword.id}
                                            ref={(element) => {
                                                rowRefs.current[index] = element;
                                            }}
                                            tabIndex={
                                                index === activeRow ? 0 : -1
                                            }
                                            aria-selected={index === activeRow}
                                            onFocus={() => setActiveRow(index)}
                                            onKeyDown={(event) =>
                                                handleRowKeyDown(event, index)
                                            }
                                            className="border-t outline-none hover:bg-muted/30 focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring aria-selected:bg-muted/20"
                                        >
                                            <td className="sticky start-0 bg-card px-4 py-3 font-medium group-focus-within:bg-muted/50">
                                                {keyword.display_text}
                                            </td>
                                            {visibleColumns.normalized ? (
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {keyword.normalized_text}
                                                </td>
                                            ) : null}
                                            {visibleColumns.status ? (
                                                <td className="px-4 py-3">
                                                    <span className="inline-flex rounded-full border bg-muted/40 px-2 py-0.5 text-xs font-medium">
                                                        {keyword.status ===
                                                        'ready'
                                                            ? t(
                                                                  'keywords.statusReady',
                                                              )
                                                            : t(
                                                                  'keywords.statusReview',
                                                              )}
                                                    </span>
                                                </td>
                                            ) : null}
                                            {visibleColumns.volume ? (
                                                <td className="px-4 py-3 tabular-nums">
                                                    {keyword.latest_volume ===
                                                    null
                                                        ? '—'
                                                        : numberFormatter.format(
                                                              Number(
                                                                  keyword.latest_volume,
                                                              ),
                                                          )}
                                                </td>
                                            ) : null}
                                            {visibleColumns.source ? (
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {keyword.latest_volume_source ??
                                                        '—'}
                                                </td>
                                            ) : null}
                                            {visibleColumns.createdAt ? (
                                                <td className="px-4 py-3 whitespace-nowrap text-muted-foreground">
                                                    {renderDate(
                                                        keyword.created_at,
                                                    )}
                                                </td>
                                            ) : null}
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
                            <button
                                type="button"
                                onClick={() =>
                                    visitPage(keywords.prev_page_url)
                                }
                                disabled={
                                    keywords.prev_page_url === null || isLoading
                                }
                                className="font-medium hover:underline disabled:pointer-events-none disabled:opacity-40"
                            >
                                {t('keywords.previous')}
                            </button>
                            <span className="text-muted-foreground">
                                {numberFormatter.format(keywords.current_page)}{' '}
                                / {numberFormatter.format(keywords.last_page)}
                            </span>
                            <button
                                type="button"
                                onClick={() =>
                                    visitPage(keywords.next_page_url)
                                }
                                disabled={
                                    keywords.next_page_url === null || isLoading
                                }
                                className="font-medium hover:underline disabled:pointer-events-none disabled:opacity-40"
                            >
                                {t('keywords.next')}
                            </button>
                        </div>
                    </div>
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
