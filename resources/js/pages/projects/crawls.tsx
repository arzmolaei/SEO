import { Form, Head, Link, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/hooks/use-locale';
import { useServerPoll } from '@/hooks/use-server-poll';

type Page<T> = {
    data: T[];
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Run = {
    id: number;
    mode: string;
    start_url: string;
    status: string;
    discovered_count: number;
    fetched_count: number;
    failed_count: number;
    blocked_count: number;
    failure_code: string | null;
    pause_requested_at: string | null;
    cancel_requested_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string;
};
type Url = {
    id: number;
    url: string;
    title: string | null;
    state: string;
    status_code: number | null;
    depth: number;
    indexable: boolean | null;
    error_code: string | null;
    response_ms: number | null;
    meta_description: string | null;
    canonical: string | null;
    robots: string | null;
    final_url: string | null;
    redirect_count: number;
    word_count: number | null;
    internal_links: number | null;
    external_links: number | null;
    images: number | null;
    images_missing_alt: number | null;
    h1: string[] | null;
    h2: string[] | null;
    schema_types: string[] | null;
    hreflang: { lang: string; href: string }[] | null;
};

export default function Crawls({
    activeRun,
    runs,
    urls,
    canCrawl,
    filters,
    limits,
}: {
    activeRun: Run | null;
    runs: Page<Run>;
    urls: Page<Url> | null;
    canCrawl: boolean;
    filters: Record<string, string>;
    limits: { max_urls: number; max_depth: number };
}) {
    const { projectContext, errors } = usePage().props;
    const { t, direction } = useLocale();
    const busy =
        activeRun?.status === 'queued' || activeRun?.status === 'running';
    const poll = useServerPoll(['activeRun', 'urls', 'runs'], busy);
    if (!projectContext) return null;
    const base = `/projects/${projectContext.project.id}/crawls`;
    const statuses: Record<string, string> = {
        queued: t('crawl.queued'),
        running: t('crawl.running'),
        paused: t('crawl.paused'),
        completed: t('crawl.completed'),
        cancelled: t('crawl.cancelled'),
        failed: t('crawl.failed'),
        pending: t('crawl.pending'),
        fetched: t('crawl.fetched'),
        blocked: t('crawl.blocked'),
        skipped: t('crawl.skipped'),
    };
    const pagination = (page: Page<unknown>) => (
        <nav className="flex gap-4" aria-label={t('crawl.pagination')}>
            {page.prev_page_url && (
                <Link href={page.prev_page_url} preserveScroll>
                    {t('home.previous')}
                </Link>
            )}
            {page.next_page_url && (
                <Link href={page.next_page_url} preserveScroll>
                    {t('home.next')}
                </Link>
            )}
        </nav>
    );
    const fieldClass = 'w-full rounded border bg-background p-2';

    return (
        <>
            <Head title={t('crawl.title')} />
            <ProjectWorkspaceShell>
                <div
                    dir={direction}
                    className="mx-auto w-full max-w-7xl space-y-6"
                >
                    <h1 className="text-2xl font-semibold">
                        {t('crawl.title')}
                    </h1>
                    <p>{t('crawl.description')}</p>
                    {errors.crawl && (
                        <p role="alert" className="text-destructive">
                            {errors.crawl}
                        </p>
                    )}
                    {canCrawl && (
                        <Form
                            action={base}
                            method="post"
                            disableWhileProcessing
                            className="space-y-4 rounded border p-4"
                        >
                            {({ processing, errors: formErrors }) => (
                                <>
                                    <h2 className="text-lg font-semibold">
                                        {t('crawl.start')}
                                    </h2>
                                    <Label htmlFor="crawl-start-url">
                                        {t('crawl.startUrl')}
                                    </Label>
                                    <Input
                                        id="crawl-start-url"
                                        name="start_url"
                                        type="url"
                                        dir="ltr"
                                        required
                                        maxLength={8192}
                                        placeholder="https://example.com/"
                                        aria-describedby="crawl-scope-note"
                                    />
                                    <InputError
                                        message={formErrors.start_url}
                                    />
                                    <p
                                        id="crawl-scope-note"
                                        className="text-sm text-muted-foreground"
                                    >
                                        {t('crawl.scope')}
                                    </p>
                                    <Label htmlFor="crawl-mode">
                                        {t('crawl.mode')}
                                    </Label>
                                    <select
                                        id="crawl-mode"
                                        name="mode"
                                        className={fieldClass}
                                        defaultValue="site"
                                    >
                                        <option value="site">
                                            {t('crawl.siteMode')}
                                        </option>
                                        <option value="sitemap">
                                            {t('crawl.sitemapMode')}
                                        </option>
                                        <option value="list">
                                            {t('crawl.listMode')}
                                        </option>
                                    </select>
                                    <InputError message={formErrors.mode} />
                                    <Label htmlFor="crawl-urls">
                                        {t('crawl.urlList')}
                                    </Label>
                                    <textarea
                                        id="crawl-urls"
                                        name="urls"
                                        dir="ltr"
                                        className={fieldClass}
                                        rows={4}
                                        maxLength={1048576}
                                    />
                                    <InputError message={formErrors.urls} />
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            name="include_www"
                                            value="1"
                                        />
                                        {t('crawl.includeWww')}
                                    </label>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="crawl-max-urls">
                                                {t('crawl.maxUrls')}
                                            </Label>
                                            <Input
                                                id="crawl-max-urls"
                                                type="number"
                                                name="max_urls"
                                                min={1}
                                                max={limits.max_urls}
                                                defaultValue={Math.min(
                                                    1000,
                                                    limits.max_urls,
                                                )}
                                            />
                                            <InputError
                                                message={formErrors.max_urls}
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label htmlFor="crawl-max-depth">
                                                {t('crawl.maxDepth')}
                                            </Label>
                                            <Input
                                                id="crawl-max-depth"
                                                type="number"
                                                name="max_depth"
                                                min={0}
                                                max={limits.max_depth}
                                                defaultValue={Math.min(
                                                    10,
                                                    limits.max_depth,
                                                )}
                                            />
                                            <InputError
                                                message={formErrors.max_depth}
                                            />
                                        </div>
                                    </div>
                                    <details className="space-y-2">
                                        <summary>
                                            {t('crawl.pathFilters')}
                                        </summary>
                                        <Label htmlFor="crawl-include">
                                            {t('crawl.include')}
                                        </Label>
                                        <textarea
                                            id="crawl-include"
                                            name="include"
                                            dir="ltr"
                                            className={fieldClass}
                                            maxLength={25600}
                                        />
                                        <InputError
                                            message={formErrors.include}
                                        />
                                        <Label htmlFor="crawl-exclude">
                                            {t('crawl.exclude')}
                                        </Label>
                                        <textarea
                                            id="crawl-exclude"
                                            name="exclude"
                                            dir="ltr"
                                            className={fieldClass}
                                            maxLength={25600}
                                        />
                                        <InputError
                                            message={formErrors.exclude}
                                        />
                                    </details>
                                    <InputError message={formErrors.crawl} />
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing ||
                                            busy ||
                                            activeRun?.status === 'paused'
                                        }
                                    >
                                        {processing
                                            ? t('crawl.sending')
                                            : t('crawl.start')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                    {activeRun ? (
                        <section
                            className="space-y-4 rounded border p-4"
                            aria-label={t('crawl.current')}
                        >
                            <h2 className="text-lg font-semibold">
                                {t('crawl.current')} #{activeRun.id}
                            </h2>
                            <p className="break-all" dir="ltr">
                                {activeRun.start_url}
                            </p>
                            <div role="status" aria-live="polite">
                                <p>
                                    {statuses[activeRun.status] ??
                                        t('crawl.failed')}
                                </p>
                                {activeRun.status === 'queued' && (
                                    <p>{t('crawl.waitingWorker')}</p>
                                )}
                                {activeRun.pause_requested_at && busy && (
                                    <p>{t('crawl.pauseRequested')}</p>
                                )}
                                {activeRun.cancel_requested_at && busy && (
                                    <p>{t('crawl.cancelRequested')}</p>
                                )}
                            </div>
                            <dl className="flex flex-wrap gap-6">
                                {(
                                    [
                                        'discovered',
                                        'fetched',
                                        'failed',
                                        'blocked',
                                    ] as const
                                ).map((key) => (
                                    <div key={key}>
                                        <dt>{t(`crawl.${key}`)}</dt>
                                        <dd className="text-xl font-semibold">
                                            {activeRun[`${key}_count`]}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            {activeRun.started_at && (
                                <p>
                                    {t('crawl.startedAt')}{' '}
                                    <time dateTime={activeRun.started_at}>
                                        {new Date(
                                            activeRun.started_at,
                                        ).toLocaleString()}
                                    </time>
                                </p>
                            )}
                            {activeRun.finished_at && (
                                <p>
                                    {t('crawl.finishedAt')}{' '}
                                    <time dateTime={activeRun.finished_at}>
                                        {new Date(
                                            activeRun.finished_at,
                                        ).toLocaleString()}
                                    </time>
                                </p>
                            )}
                            {activeRun.failure_code && (
                                <p role="alert">{t('crawl.runFailed')}</p>
                            )}
                            {busy && (
                                <p className="text-sm text-muted-foreground">
                                    {t('crawl.polling')}
                                </p>
                            )}
                            {poll.failed && (
                                <p role="alert">{t('crawl.pollFailed')}</p>
                            )}
                            {canCrawl && (
                                <div className="flex flex-wrap gap-3">
                                    {(['pause', 'resume', 'cancel'] as const)
                                        .filter((action) =>
                                            action === 'resume'
                                                ? activeRun.status === 'paused'
                                                : action === 'pause'
                                                  ? busy
                                                  : busy ||
                                                    activeRun.status ===
                                                        'paused',
                                        )
                                        .map((action) => (
                                            <Form
                                                key={action}
                                                action={`${base}/${activeRun.id}/${action}`}
                                                method="post"
                                                disableWhileProcessing
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        variant={
                                                            action === 'cancel'
                                                                ? 'destructive'
                                                                : 'outline'
                                                        }
                                                        disabled={
                                                            processing ||
                                                            activeRun.cancel_requested_at !==
                                                                null ||
                                                            (action ===
                                                                'pause' &&
                                                                activeRun.pause_requested_at !==
                                                                    null)
                                                        }
                                                    >
                                                        {processing
                                                            ? t('crawl.sending')
                                                            : t(
                                                                  `crawl.${action}`,
                                                              )}
                                                    </Button>
                                                )}
                                            </Form>
                                        ))}
                                </div>
                            )}
                            <Form
                                action={base}
                                method="get"
                                className="grid items-end gap-3 sm:grid-cols-3 lg:grid-cols-6"
                            >
                                <input
                                    type="hidden"
                                    name="run"
                                    value={activeRun.id}
                                />
                                <label>
                                    {t('crawl.state')}
                                    <select
                                        className={fieldClass}
                                        name="state"
                                        defaultValue={filters.state ?? ''}
                                    >
                                        <option value="">
                                            {t('crawl.all')}
                                        </option>
                                        {[
                                            'pending',
                                            'fetched',
                                            'failed',
                                            'blocked',
                                            'skipped',
                                        ].map((state) => (
                                            <option key={state} value={state}>
                                                {statuses[state]}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label>
                                    {t('crawl.httpStatus')}
                                    <select
                                        className={fieldClass}
                                        name="status_class"
                                        defaultValue={
                                            filters.status_class ?? ''
                                        }
                                    >
                                        <option value="">
                                            {t('crawl.all')}
                                        </option>
                                        {['2xx', '3xx', '4xx', '5xx'].map(
                                            (value) => (
                                                <option key={value}>
                                                    {value}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </label>
                                <label>
                                    {t('crawl.indexable')}
                                    <select
                                        className={fieldClass}
                                        name="indexable"
                                        defaultValue={filters.indexable ?? ''}
                                    >
                                        <option value="">
                                            {t('crawl.all')}
                                        </option>
                                        <option value="yes">
                                            {t('crawl.yes')}
                                        </option>
                                        <option value="no">
                                            {t('crawl.no')}
                                        </option>
                                        <option value="unknown">
                                            {t('crawl.unknown')}
                                        </option>
                                    </select>
                                </label>
                                <label>
                                    {t('crawl.sort')}
                                    <select
                                        className={fieldClass}
                                        name="sort"
                                        defaultValue={filters.sort ?? 'id'}
                                    >
                                        <option value="id">
                                            {t('crawl.discoveryOrder')}
                                        </option>
                                        <option value="depth">
                                            {t('crawl.depth')}
                                        </option>
                                        <option value="status_code">
                                            {t('crawl.httpStatus')}
                                        </option>
                                        <option value="response_ms">
                                            {t('crawl.responseMs')}
                                        </option>
                                        <option value="title">
                                            {t('crawl.pageTitle')}
                                        </option>
                                    </select>
                                </label>
                                <label>
                                    {t('crawl.direction')}
                                    <select
                                        className={fieldClass}
                                        name="direction"
                                        defaultValue={
                                            filters.direction ?? 'asc'
                                        }
                                    >
                                        <option value="asc">
                                            {t('crawl.ascending')}
                                        </option>
                                        <option value="desc">
                                            {t('crawl.descending')}
                                        </option>
                                    </select>
                                </label>
                                <Button type="submit" variant="outline">
                                    {t('crawl.filter')}
                                </Button>
                            </Form>
                            {urls && urls.data.length > 0 ? (
                                <>
                                    <p>
                                        {t('crawl.total')} {urls.total}
                                    </p>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-start text-sm">
                                            <caption className="sr-only">
                                                {t('crawl.results')}
                                            </caption>
                                            <thead>
                                                <tr>
                                                    {(
                                                        [
                                                            'url',
                                                            'pageTitle',
                                                            'state',
                                                            'httpStatus',
                                                            'indexable',
                                                            'depth',
                                                            'details',
                                                        ] as const
                                                    ).map((key) => (
                                                        <th
                                                            key={key}
                                                            scope="col"
                                                            className="p-2 text-start"
                                                        >
                                                            {t(`crawl.${key}`)}
                                                        </th>
                                                    ))}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {urls.data.map((row) => (
                                                    <tr
                                                        key={row.id}
                                                        className="border-t"
                                                    >
                                                        <td
                                                            dir="ltr"
                                                            className="max-w-xs p-2 break-all"
                                                        >
                                                            {row.url}
                                                        </td>
                                                        <td
                                                            className="p-2"
                                                            dir="auto"
                                                        >
                                                            {row.title ?? '—'}
                                                        </td>
                                                        <td className="p-2">
                                                            {
                                                                statuses[
                                                                    row.state
                                                                ]
                                                            }
                                                            {row.error_code && (
                                                                <p>
                                                                    {t(
                                                                        'crawl.fetchRefused',
                                                                    )}{' '}
                                                                    <code dir="ltr">
                                                                        {
                                                                            row.error_code
                                                                        }
                                                                    </code>
                                                                </p>
                                                            )}
                                                        </td>
                                                        <td className="p-2">
                                                            {row.status_code ??
                                                                '—'}
                                                        </td>
                                                        <td className="p-2">
                                                            {row.indexable ===
                                                            null
                                                                ? t(
                                                                      'crawl.unknown',
                                                                  )
                                                                : row.indexable
                                                                  ? t(
                                                                        'crawl.yes',
                                                                    )
                                                                  : t(
                                                                        'crawl.no',
                                                                    )}
                                                        </td>
                                                        <td className="p-2">
                                                            {row.depth}
                                                        </td>
                                                        <td className="p-2">
                                                            <details>
                                                                <summary>
                                                                    {t(
                                                                        'crawl.details',
                                                                    )}
                                                                </summary>
                                                                <dl className="space-y-2">
                                                                    {(
                                                                        [
                                                                            'meta_description',
                                                                            'canonical',
                                                                            'robots',
                                                                            'final_url',
                                                                            'redirect_count',
                                                                            'response_ms',
                                                                            'word_count',
                                                                            'internal_links',
                                                                            'external_links',
                                                                            'images',
                                                                            'images_missing_alt',
                                                                            'h1',
                                                                            'h2',
                                                                            'schema_types',
                                                                            'hreflang',
                                                                        ] as const
                                                                    ).map(
                                                                        (
                                                                            key,
                                                                        ) => (
                                                                            <div
                                                                                key={
                                                                                    key
                                                                                }
                                                                            >
                                                                                <dt>
                                                                                    {t(
                                                                                        `crawl.detail.${key}`,
                                                                                    )}
                                                                                </dt>
                                                                                <dd
                                                                                    dir="auto"
                                                                                    className="max-w-sm break-all"
                                                                                >
                                                                                    {row[
                                                                                        key
                                                                                    ] ===
                                                                                    null
                                                                                        ? '—'
                                                                                        : Array.isArray(
                                                                                                row[
                                                                                                    key
                                                                                                ],
                                                                                            )
                                                                                          ? JSON.stringify(
                                                                                                row[
                                                                                                    key
                                                                                                ],
                                                                                            )
                                                                                          : row[
                                                                                                key
                                                                                            ]}
                                                                                </dd>
                                                                            </div>
                                                                        ),
                                                                    )}
                                                                </dl>
                                                            </details>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    {pagination(urls)}
                                </>
                            ) : (
                                <p>{t('crawl.noResults')}</p>
                            )}
                        </section>
                    ) : (
                        <p className="rounded border p-6">{t('crawl.empty')}</p>
                    )}
                    <section className="space-y-3">
                        <h2 className="text-lg font-semibold">
                            {t('crawl.history')}
                        </h2>
                        {runs.data.length === 0 ? (
                            <p>{t('crawl.empty')}</p>
                        ) : (
                            <ul className="space-y-2">
                                {runs.data.map((run) => (
                                    <li key={run.id}>
                                        <Link
                                            href={`${base}?run=${run.id}`}
                                            className="underline"
                                        >
                                            #{run.id} — {statuses[run.status]} —{' '}
                                            <time dateTime={run.created_at}>
                                                {new Date(
                                                    run.created_at,
                                                ).toLocaleString()}
                                            </time>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {pagination(runs)}
                    </section>
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
