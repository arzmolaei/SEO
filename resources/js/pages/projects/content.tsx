import { Head, Link, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/hooks/use-locale';
import type { AgendaBucket, BriefStatus } from '@/lib/content-brief';
import { agendaLabels, briefStatusLabels } from '@/lib/content-brief';

type BriefRow = {
    id: number;
    title: string;
    primary_keyword: string;
    intent: string;
    status: BriefStatus;
    due_on: string | null;
    published_on: string | null;
};
type Paginated = {
    data: BriefRow[];
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type ClusterOption = {
    id: number;
    name: string;
    primary_keyword: string;
    has_brief: boolean;
};
const BUCKETS: AgendaBucket[] = [
    'overdue',
    'week',
    'month',
    'later',
    'unscheduled',
    'published',
];

export default function Content({
    briefs,
    filters,
    agenda,
    clusterOptions,
    canEdit,
}: {
    briefs: Paginated;
    filters: { status: BriefStatus | null };
    agenda: Record<AgendaBucket, { count: number; items: BriefRow[] }>;
    clusterOptions: ClusterOption[];
    canEdit: boolean;
}) {
    const { projectContext, errors } = usePage().props;
    const { t, locale } = useLocale();
    const [busy, setBusy] = useState(false);
    const [clusterId, setClusterId] = useState<number | null>(null);
    const base = `/projects/${projectContext?.project.id}/content`;
    const num = (n: number) =>
        locale === 'fa' ? n.toLocaleString('fa-IR') : String(n);
    const date = (value: string | null) =>
        value
            ? new Date(`${value}T00:00:00`).toLocaleDateString(
                  locale === 'fa' ? 'fa-IR' : 'en',
              )
            : '—';
    const fieldClass = 'rounded border bg-background p-2 text-sm';
    const reload = (
        url: string,
        data: Record<string, string>,
        only: string[],
    ) =>
        router.get(url, data, {
            preserveScroll: true,
            preserveState: true,
            only,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    const searchClusters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const value = new FormData(event.currentTarget).get('cluster_q');
        reload(
            base,
            typeof value === 'string' && value.trim() !== ''
                ? { cluster_q: value.trim() }
                : {},
            ['clusterOptions'],
        );
    };
    const create = () => {
        if (clusterId === null) return;
        router.post(
            base,
            { cluster_id: clusterId },
            {
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    };
    const briefLink = (brief: BriefRow) => (
        <Link
            href={`${base}/${brief.id}`}
            className="font-medium hover:underline"
        >
            {brief.title}
        </Link>
    );

    return (
        <>
            <Head title={t('project.nav.content')} />
            <ProjectWorkspaceShell>
                <div className="mx-auto max-w-5xl space-y-6" aria-busy={busy}>
                    <header className="space-y-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('project.nav.content')}
                        </h1>
                        <p className="max-w-2xl text-sm leading-6 text-muted-foreground">
                            {t('content.intro')}
                        </p>
                    </header>

                    {canEdit && (
                        <section
                            aria-labelledby="new-brief-title"
                            className="space-y-3 rounded-lg border p-4"
                        >
                            <h2
                                id="new-brief-title"
                                className="text-lg font-semibold"
                            >
                                {t('content.newTitle')}
                            </h2>
                            <form
                                onSubmit={searchClusters}
                                role="search"
                                className="flex flex-wrap gap-2"
                            >
                                <input
                                    name="cluster_q"
                                    type="search"
                                    aria-label={t('content.clusterSearch')}
                                    placeholder={t('content.clusterSearch')}
                                    className={`${fieldClass} min-w-56 flex-1`}
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={busy}
                                >
                                    {t('content.clusterSearch')}
                                </Button>
                            </form>
                            {clusterOptions.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('content.noClusters')}
                                </p>
                            ) : (
                                <fieldset className="space-y-2">
                                    <legend className="sr-only">
                                        {t('content.newTitle')}
                                    </legend>
                                    {clusterOptions.map((cluster) => (
                                        <label
                                            key={cluster.id}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <input
                                                type="radio"
                                                name="cluster_id"
                                                value={cluster.id}
                                                checked={
                                                    clusterId === cluster.id
                                                }
                                                onChange={() =>
                                                    setClusterId(cluster.id)
                                                }
                                            />
                                            <span>
                                                {cluster.primary_keyword}
                                            </span>
                                            {cluster.has_brief && (
                                                <span className="rounded-full border px-2 text-xs text-muted-foreground">
                                                    {t('content.open')}
                                                </span>
                                            )}
                                        </label>
                                    ))}
                                    <Button
                                        disabled={busy || clusterId === null}
                                        onClick={create}
                                    >
                                        {clusterOptions.find(
                                            (cluster) =>
                                                cluster.id === clusterId,
                                        )?.has_brief
                                            ? t('content.open')
                                            : t('content.create')}
                                    </Button>
                                    <InputError message={errors?.cluster} />
                                </fieldset>
                            )}
                        </section>
                    )}

                    <section
                        aria-labelledby="calendar-title"
                        className="space-y-4"
                    >
                        <h2
                            id="calendar-title"
                            className="text-lg font-semibold"
                        >
                            {t('content.calendarTitle')}
                        </h2>
                        <div className="grid gap-4 md:grid-cols-2">
                            {BUCKETS.map((bucket) => (
                                <div
                                    key={bucket}
                                    data-bucket={bucket}
                                    className="space-y-2 rounded-lg border p-4"
                                >
                                    <h3
                                        className={`text-sm font-semibold ${bucket === 'overdue' && agenda[bucket].count > 0 ? 'text-destructive' : ''}`}
                                    >
                                        {agendaLabels[locale][bucket]} (
                                        {num(agenda[bucket].count)})
                                    </h3>
                                    <ul className="space-y-1 text-sm">
                                        {agenda[bucket].items.map((brief) => (
                                            <li
                                                key={brief.id}
                                                className="flex flex-wrap justify-between gap-2"
                                            >
                                                {briefLink(brief)}
                                                <span className="text-muted-foreground">
                                                    {date(
                                                        bucket === 'published'
                                                            ? brief.published_on
                                                            : brief.due_on,
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                    {agenda[bucket].count >
                                        agenda[bucket].items.length && (
                                        <p className="text-xs text-muted-foreground">
                                            +
                                            {num(
                                                agenda[bucket].count -
                                                    agenda[bucket].items.length,
                                            )}{' '}
                                            {t('content.more')}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>
                    </section>

                    <section aria-labelledby="list-title" className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2
                                id="list-title"
                                className="text-lg font-semibold"
                            >
                                {t('content.listTitle')}
                            </h2>
                            <select
                                aria-label={t('content.status')}
                                className={fieldClass}
                                value={filters.status ?? ''}
                                onChange={(event) =>
                                    reload(
                                        base,
                                        event.target.value
                                            ? { status: event.target.value }
                                            : {},
                                        ['briefs', 'filters'],
                                    )
                                }
                            >
                                <option value="">
                                    {t('content.allStatuses')}
                                </option>
                                {Object.entries(briefStatusLabels[locale]).map(
                                    ([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                        {briefs.data.length === 0 ? (
                            <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                                {t('content.empty')}
                            </p>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {briefs.data.map((brief) => (
                                    <li
                                        key={brief.id}
                                        data-brief={brief.id}
                                        className="flex flex-wrap items-center justify-between gap-3 p-4"
                                    >
                                        <div className="space-y-1">
                                            {briefLink(brief)}
                                            <p className="text-xs text-muted-foreground">
                                                {brief.primary_keyword}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2 text-xs">
                                            <span className="text-muted-foreground">
                                                {t('content.due')}:{' '}
                                                {date(brief.due_on)}
                                            </span>
                                            <span className="rounded-full border px-3 py-1">
                                                {
                                                    briefStatusLabels[locale][
                                                        brief.status
                                                    ]
                                                }
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {(briefs.prev_page_url || briefs.next_page_url) && (
                            <nav className="flex justify-between gap-2">
                                <Button
                                    variant="outline"
                                    disabled={busy || !briefs.prev_page_url}
                                    onClick={() =>
                                        briefs.prev_page_url &&
                                        reload(briefs.prev_page_url, {}, [
                                            'briefs',
                                            'filters',
                                        ])
                                    }
                                >
                                    {t('pages.prev')}
                                </Button>
                                <Button
                                    variant="outline"
                                    disabled={busy || !briefs.next_page_url}
                                    onClick={() =>
                                        briefs.next_page_url &&
                                        reload(briefs.next_page_url, {}, [
                                            'briefs',
                                            'filters',
                                        ])
                                    }
                                >
                                    {t('pages.next')}
                                </Button>
                            </nav>
                        )}
                    </section>
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
