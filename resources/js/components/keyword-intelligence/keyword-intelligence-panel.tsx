import { Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    FilePlus2,
    Lock,
    LockOpen,
    RefreshCw,
    Search,
    ShieldAlert,
    Sparkles,
    Target,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { useLocale } from '@/hooks/use-locale';
import type {
    IntelligenceCluster,
    IntelligenceTargetPage,
    KeywordIntelligenceWorkspace,
} from '@/types/keyword-intelligence';

type Copy = {
    title: string;
    description: string;
    grid: string;
    intelligence: string;
    active: string;
    ready: string;
    unmapped: string;
    reviews: string;
    conflicts: string;
    prepareClusters: string;
    refreshConflicts: string;
    reviewQueue: string;
    createPage: string;
    pageTitle: string;
    create: string;
    empty: string;
    emptyDescription: string;
    primary: string;
    members: string;
    priority: string;
    completeness: string;
    business: string;
    notSet: string;
    markReviewed: string;
    reviewed: string;
    pending: string;
    locked: string;
    unlocked: string;
    target: string;
    noTarget: string;
    ignoreTarget: string;
    findPage: string;
    searchPlaceholder: string;
    searching: string;
    noPages: string;
    mapHere: string;
    makeReady: string;
    planning: string;
    readyState: string;
    previous: string;
    next: string;
    readOnly: string;
    actionFailed: string;
};

const copies: Record<'fa' | 'en', Copy> = {
    fa: {
        title: 'هوشمندی کلمات کلیدی',
        description:
            'خوشه‌ها را بررسی کنید، صفحه هدف بدهید، تعارض‌ها را حل کنید و فقط تصمیم‌های کامل را آماده اعلام کنید.',
        grid: 'جدول کلمات',
        intelligence: 'هوشمندی و نگاشت',
        active: 'خوشه فعال',
        ready: 'آماده',
        unmapped: 'بدون نگاشت',
        reviews: 'نیازمند بررسی',
        conflicts: 'تعارض نگاشت',
        prepareClusters: 'ساخت پیشنهاد خوشه',
        refreshConflicts: 'بازبینی تعارض‌ها',
        reviewQueue: 'صف بررسی',
        createPage: 'صفحه برنامه‌ریزی‌شده جدید',
        pageTitle: 'عنوان صفحه برنامه‌ریزی‌شده',
        create: 'ساخت صفحه',
        empty: 'هنوز خوشه‌ای ندارید',
        emptyDescription:
            'پیشنهادهای خوشه را آماده کنید و سپس موارد نامطمئن را در صف بررسی تأیید کنید.',
        primary: 'کلمه اصلی',
        members: 'عضو',
        priority: 'اولویت',
        completeness: 'کامل‌بودن داده',
        business: 'ارزش تجاری',
        notSet: 'ثبت نشده',
        markReviewed: 'ثبت بررسی',
        reviewed: 'بررسی‌شده',
        pending: 'در انتظار بررسی',
        locked: 'قفل‌شده',
        unlocked: 'باز',
        target: 'صفحه هدف',
        noTarget: 'هنوز صفحه هدف ندارد',
        ignoreTarget: 'نادیده‌گرفتن این خوشه',
        findPage: 'جستجوی صفحه هدف',
        searchPlaceholder: 'عنوان یا URL صفحه',
        searching: 'در حال جستجو…',
        noPages: 'صفحه‌ای پیدا نشد.',
        mapHere: 'نگاشت به این صفحه',
        makeReady: 'اعلام آماده',
        planning: 'در برنامه‌ریزی',
        readyState: 'آماده',
        previous: 'قبلی',
        next: 'بعدی',
        readOnly: 'دسترسی شما فقط برای مشاهده است.',
        actionFailed: 'عملیات انجام نشد. داده‌ها را تازه کنید و دوباره تلاش کنید.',
    },
    en: {
        title: 'Keyword Intelligence',
        description:
            'Review clusters, map target pages, resolve conflicts, and only mark complete decisions as ready.',
        grid: 'Keyword grid',
        intelligence: 'Intelligence & mapping',
        active: 'Active clusters',
        ready: 'Ready',
        unmapped: 'Unmapped',
        reviews: 'Pending reviews',
        conflicts: 'Mapping conflicts',
        prepareClusters: 'Prepare cluster suggestions',
        refreshConflicts: 'Refresh conflicts',
        reviewQueue: 'Review Queue',
        createPage: 'New planned page',
        pageTitle: 'Planned page title',
        create: 'Create page',
        empty: 'No clusters yet',
        emptyDescription:
            'Prepare cluster suggestions, then confirm uncertain suggestions in the Review Queue.',
        primary: 'Primary keyword',
        members: 'members',
        priority: 'Priority',
        completeness: 'Data completeness',
        business: 'Business relevance',
        notSet: 'Not set',
        markReviewed: 'Save review',
        reviewed: 'Reviewed',
        pending: 'Pending review',
        locked: 'Locked',
        unlocked: 'Unlocked',
        target: 'Target page',
        noTarget: 'No target page yet',
        ignoreTarget: 'Ignore this cluster',
        findPage: 'Find target page',
        searchPlaceholder: 'Page title or URL',
        searching: 'Searching…',
        noPages: 'No page found.',
        mapHere: 'Map to this page',
        makeReady: 'Mark ready',
        planning: 'Planning',
        readyState: 'Ready',
        previous: 'Previous',
        next: 'Next',
        readOnly: 'You have view-only access.',
        actionFailed: 'The action failed. Refresh the data and try again.',
    },
};

function ActionButton({
    children,
    onClick,
    disabled = false,
    tone = 'default',
}: {
    children: React.ReactNode;
    onClick: () => void;
    disabled?: boolean;
    tone?: 'default' | 'primary' | 'danger';
}) {
    const tones = {
        default: 'border bg-background hover:bg-muted',
        primary: 'border-primary bg-primary text-primary-foreground hover:bg-primary/90',
        danger: 'border-destructive/40 text-destructive hover:bg-destructive/5',
    };

    return (
        <button
            type="button"
            className={`inline-flex min-h-9 items-center justify-center gap-2 rounded-md border px-3 py-2 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-50 ${tones[tone]}`}
            onClick={onClick}
            disabled={disabled}
        >
            {children}
        </button>
    );
}

function ClusterCard({
    cluster,
    projectId,
    canManage,
    copy,
}: {
    cluster: IntelligenceCluster;
    projectId: number;
    canManage: boolean;
    copy: Copy;
}) {
    const [business, setBusiness] = useState(
        cluster.business_relevance?.toString() ?? '',
    );
    const [search, setSearch] = useState('');
    const [pages, setPages] = useState<IntelligenceTargetPage[]>([]);
    const [searching, setSearching] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const reviewComplete = Boolean(cluster.priority.components.review?.value);
    const canBecomeReady =
        cluster.review_state === 'reviewed' &&
        cluster.target !== null &&
        reviewComplete;

    const mutate = (
        action: () => void,
    ) => {
        setError(null);
        setBusy(true);
        action();
    };

    const options = {
        preserveScroll: true,
        onError: () => setError(copy.actionFailed),
        onFinish: () => setBusy(false),
    };

    const saveReview = () =>
        mutate(() =>
            router.patch(
                `/projects/${projectId}/keyword-intelligence/clusters/${cluster.id}`,
                {
                    review_state: 'reviewed',
                    business_relevance:
                        business === '' ? null : Number(business),
                },
                options,
            ),
        );

    const toggleLock = () =>
        mutate(() =>
            router.patch(
                `/projects/${projectId}/keyword-intelligence/clusters/${cluster.id}/lock`,
                { locked: !cluster.is_locked },
                options,
            ),
        );

    const ignoreTarget = () =>
        mutate(() =>
            router.put(
                `/projects/${projectId}/keyword-intelligence/clusters/${cluster.id}/target`,
                { type: 'ignore', page_id: null },
                options,
            ),
        );

    const mapPage = (page: IntelligenceTargetPage) =>
        mutate(() =>
            router.put(
                `/projects/${projectId}/keyword-intelligence/clusters/${cluster.id}/target`,
                {
                    type: page.kind === 'planned' ? 'planned_page' : 'existing_page',
                    page_id: page.id,
                },
                options,
            ),
        );

    const markReady = () =>
        mutate(() =>
            router.post(
                `/projects/${projectId}/keyword-intelligence/clusters/${cluster.id}/ready`,
                {},
                options,
            ),
        );

    const searchPages = async () => {
        setSearching(true);
        setError(null);
        try {
            const response = await fetch(
                `/projects/${projectId}/keyword-intelligence/pages/search?q=${encodeURIComponent(search)}`,
                { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } },
            );
            if (!response.ok) throw new Error('search failed');
            const payload = (await response.json()) as {
                data?: IntelligenceTargetPage[];
            };
            setPages(payload.data ?? []);
        } catch {
            setError(copy.actionFailed);
        } finally {
            setSearching(false);
        }
    };

    return (
        <article className="rounded-xl border bg-card p-4 shadow-xs">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="truncate font-semibold">{cluster.name}</h3>
                        <span className="rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground">
                            {cluster.intent}
                        </span>
                        <span className="rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground">
                            {cluster.review_state === 'reviewed'
                                ? copy.reviewed
                                : copy.pending}
                        </span>
                    </div>
                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span>
                            {copy.primary}: {cluster.primary_keyword.display_text}
                        </span>
                        <span>
                            {cluster.member_count} {copy.members}
                        </span>
                        <span>
                            {cluster.is_locked ? copy.locked : copy.unlocked}
                        </span>
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-2 text-center sm:min-w-56">
                    <div className="rounded-lg bg-muted/50 px-3 py-2">
                        <div className="text-lg font-bold">{cluster.priority.score}</div>
                        <div className="text-[11px] text-muted-foreground">{copy.priority}</div>
                    </div>
                    <div className="rounded-lg bg-muted/50 px-3 py-2">
                        <div className="text-lg font-bold">
                            {cluster.priority.data_completeness}%
                        </div>
                        <div className="text-[11px] text-muted-foreground">{copy.completeness}</div>
                    </div>
                </div>
            </div>

            <div className="mt-4 grid gap-4 xl:grid-cols-2">
                <div className="rounded-lg border p-3">
                    <div className="mb-3 flex items-center justify-between gap-2">
                        <span className="text-sm font-medium">{copy.business}</span>
                        <span className="text-xs text-muted-foreground">
                            {cluster.business_relevance ?? copy.notSet}
                        </span>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            className="h-9 rounded-md border bg-background px-2 text-sm"
                            value={business}
                            onChange={(event) => setBusiness(event.target.value)}
                            disabled={!canManage || busy}
                        >
                            <option value="">{copy.notSet}</option>
                            {[1, 2, 3, 4, 5].map((value) => (
                                <option key={value} value={value}>{value}</option>
                            ))}
                        </select>
                        <ActionButton onClick={saveReview} disabled={!canManage || busy} tone="primary">
                            <CheckCircle2 className="size-4" />
                            {copy.markReviewed}
                        </ActionButton>
                        <ActionButton onClick={toggleLock} disabled={!canManage || busy}>
                            {cluster.is_locked ? <LockOpen className="size-4" /> : <Lock className="size-4" />}
                            {cluster.is_locked ? copy.locked : copy.unlocked}
                        </ActionButton>
                    </div>
                </div>

                <div className="rounded-lg border p-3">
                    <div className="mb-3 flex items-center gap-2 text-sm font-medium">
                        <Target className="size-4" />
                        {copy.target}
                    </div>
                    {cluster.target ? (
                        <div className="mb-3 rounded-md bg-muted/50 p-2 text-xs">
                            <div className="font-medium">
                                {cluster.target.page?.title ?? cluster.target.type}
                            </div>
                            <div className="mt-1 truncate text-muted-foreground">
                                {cluster.target.page?.url ?? cluster.target.type}
                            </div>
                        </div>
                    ) : (
                        <p className="mb-3 text-xs text-muted-foreground">{copy.noTarget}</p>
                    )}
                    <div className="flex gap-2">
                        <input
                            className="h-9 min-w-0 flex-1 rounded-md border bg-background px-3 text-sm"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={copy.searchPlaceholder}
                            disabled={!canManage || busy}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    void searchPages();
                                }
                            }}
                        />
                        <ActionButton onClick={() => void searchPages()} disabled={!canManage || searching || busy}>
                            <Search className="size-4" />
                            {searching ? copy.searching : copy.findPage}
                        </ActionButton>
                    </div>
                    {pages.length > 0 && (
                        <div className="mt-2 max-h-40 space-y-1 overflow-auto rounded-md border p-1">
                            {pages.map((page) => (
                                <button
                                    key={page.id}
                                    type="button"
                                    className="flex w-full items-center justify-between gap-3 rounded px-2 py-2 text-start text-xs hover:bg-muted"
                                    onClick={() => mapPage(page)}
                                    disabled={busy}
                                >
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">{page.title ?? page.url ?? `#${page.id}`}</span>
                                        <span className="block truncate text-muted-foreground">{page.kind} · {page.url ?? '—'}</span>
                                    </span>
                                    <span className="shrink-0 text-primary">{copy.mapHere}</span>
                                </button>
                            ))}
                        </div>
                    )}
                    {!searching && search !== '' && pages.length === 0 && (
                        <p className="mt-2 text-xs text-muted-foreground">{copy.noPages}</p>
                    )}
                    <div className="mt-3 flex flex-wrap gap-2">
                        <ActionButton onClick={ignoreTarget} disabled={!canManage || busy} tone="danger">
                            {copy.ignoreTarget}
                        </ActionButton>
                        {cluster.lifecycle_state !== 'ready' && (
                            <ActionButton onClick={markReady} disabled={!canManage || busy || !canBecomeReady} tone="primary">
                                <CheckCircle2 className="size-4" />
                                {copy.makeReady}
                            </ActionButton>
                        )}
                        <span className="inline-flex min-h-9 items-center rounded-md border px-3 text-xs">
                            {cluster.lifecycle_state === 'ready' ? copy.readyState : copy.planning}
                        </span>
                    </div>
                </div>
            </div>
            {error && <p className="mt-3 text-xs text-destructive">{error}</p>}
        </article>
    );
}

export function KeywordIntelligencePanel({
    data,
    projectId,
}: {
    data: KeywordIntelligenceWorkspace;
    projectId: number;
}) {
    const { locale } = useLocale();
    const copy = copies[locale];
    const [busy, setBusy] = useState(false);
    const [pageTitle, setPageTitle] = useState('');
    const [error, setError] = useState<string | null>(null);
    const summary = useMemo(
        () => [
            [copy.active, data.summary.active_clusters],
            [copy.ready, data.summary.ready_clusters],
            [copy.unmapped, data.summary.unmapped_clusters],
            [copy.reviews, data.summary.pending_reviews],
            [copy.conflicts, data.summary.mapping_conflicts],
        ] as const,
        [copy, data.summary],
    );

    const run = (url: string) => {
        setBusy(true);
        setError(null);
        router.post(url, {}, {
            preserveScroll: true,
            onError: () => setError(copy.actionFailed),
            onFinish: () => setBusy(false),
        });
    };

    const createPage = () => {
        const title = pageTitle.trim();
        if (!title) return;
        setBusy(true);
        setError(null);
        router.post(
            `/projects/${projectId}/keyword-intelligence/pages`,
            { title },
            {
                preserveScroll: true,
                onSuccess: () => setPageTitle(''),
                onError: () => setError(copy.actionFailed),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <section className="space-y-5" aria-labelledby="keyword-intelligence-title">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 id="keyword-intelligence-title" className="text-xl font-semibold">{copy.title}</h2>
                    <p className="mt-1 max-w-3xl text-sm text-muted-foreground">{copy.description}</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <ActionButton
                        onClick={() => run(`/projects/${projectId}/keyword-intelligence/cluster-suggestions/refresh`)}
                        disabled={!data.can_manage || busy}
                    >
                        <Sparkles className="size-4" />
                        {copy.prepareClusters}
                    </ActionButton>
                    <ActionButton
                        onClick={() => run(`/projects/${projectId}/keyword-intelligence/mapping-conflicts/refresh`)}
                        disabled={!data.can_manage || busy}
                    >
                        <RefreshCw className={`size-4 ${busy ? 'animate-spin' : ''}`} />
                        {copy.refreshConflicts}
                    </ActionButton>
                    <Link
                        href={`/projects/${projectId}/reviews`}
                        className="inline-flex min-h-9 items-center gap-2 rounded-md border bg-background px-3 py-2 text-xs font-medium hover:bg-muted"
                    >
                        <ShieldAlert className="size-4" />
                        {copy.reviewQueue}
                    </Link>
                </div>
            </div>

            <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                {summary.map(([label, value]) => (
                    <div key={label} className="rounded-xl border bg-card px-4 py-3">
                        <div className="text-2xl font-bold tabular-nums">{value}</div>
                        <div className="mt-1 text-xs text-muted-foreground">{label}</div>
                    </div>
                ))}
            </div>

            {!data.can_manage && (
                <div className="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm">{copy.readOnly}</div>
            )}

            {data.can_manage && (
                <div className="rounded-xl border bg-card p-4">
                    <div className="mb-3 flex items-center gap-2 font-medium">
                        <FilePlus2 className="size-4" />
                        {copy.createPage}
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <input
                            className="h-9 min-w-0 flex-1 rounded-md border bg-background px-3 text-sm"
                            value={pageTitle}
                            onChange={(event) => setPageTitle(event.target.value)}
                            placeholder={copy.pageTitle}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    createPage();
                                }
                            }}
                        />
                        <ActionButton onClick={createPage} disabled={busy || pageTitle.trim() === ''} tone="primary">
                            {copy.create}
                        </ActionButton>
                    </div>
                </div>
            )}

            {data.clusters.data.length === 0 ? (
                <div className="rounded-xl border border-dashed p-8 text-center">
                    <div className="font-medium">{copy.empty}</div>
                    <p className="mx-auto mt-2 max-w-xl text-sm text-muted-foreground">{copy.emptyDescription}</p>
                </div>
            ) : (
                <div className="space-y-3">
                    {data.clusters.data.map((cluster) => (
                        <ClusterCard
                            key={cluster.id}
                            cluster={cluster}
                            projectId={projectId}
                            canManage={data.can_manage}
                            copy={copy}
                        />
                    ))}
                </div>
            )}

            {(data.clusters.prev_page_url || data.clusters.next_page_url) && (
                <div className="flex items-center justify-between gap-3">
                    <ActionButton
                        onClick={() => {
                            if (data.clusters.prev_page_url) router.get(data.clusters.prev_page_url, {}, { preserveScroll: true, preserveState: true });
                        }}
                        disabled={!data.clusters.prev_page_url}
                    >
                        {copy.previous}
                    </ActionButton>
                    <span className="text-xs text-muted-foreground">
                        {data.clusters.current_page} / {data.clusters.last_page}
                    </span>
                    <ActionButton
                        onClick={() => {
                            if (data.clusters.next_page_url) router.get(data.clusters.next_page_url, {}, { preserveScroll: true, preserveState: true });
                        }}
                        disabled={!data.clusters.next_page_url}
                    >
                        {copy.next}
                    </ActionButton>
                </div>
            )}

            {error && <p className="text-sm text-destructive">{error}</p>}
        </section>
    );
}
