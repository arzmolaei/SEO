import { Head, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/hooks/use-locale';
import { briefStatusLabels } from '@/lib/content-brief';
import { statusLabels } from '@/lib/on-page-checklist';
import type { GuideStatus } from '@/lib/project-guide';
import { guideStatusLabel, guideStepCopy } from '@/lib/project-guide';
import { activityLabel } from '@/lib/report';
import type { Totals } from '@/lib/search-console';
import {
    formatNumber,
    formatPercent,
    perDayChange,
} from '@/lib/search-console';

type Report = {
    period: { from: string; to: string };
    generated_at: string;
    path: {
        progress: { done: number; total: number };
        steps: { key: string; status: GuideStatus }[];
    };
    keywords: {
        keywords: number;
        clusters: number;
        mapped: number;
        ready: number;
    };
    pages: {
        existing: number;
        planned: number;
        on_page: { todo: number; in_progress: number; done: number };
        last_check: {
            finished_at: string | null;
            checked: number;
            errors: number;
        } | null;
    };
    content: {
        draft: number;
        review: number;
        published: number;
        published_in_period: number;
        due_in_period: number;
    };
    tasks: {
        open: number;
        overdue: number;
        done_in_period: number;
        created_in_period: number;
    };
    links: { added_in_period: number; added_total: number };
    performance: {
        period: { from: string; to: string };
        totals: Totals;
        previous: Totals | null;
        top_queries: {
            key: string;
            clicks: number;
            impressions: number;
            position: number;
        }[];
    } | null;
    activity: { event: string; count: number }[];
};

export default function ProjectReportPage({ report }: { report: Report }) {
    const { projectContext } = usePage().props;
    const { t, locale } = useLocale();
    const base = `/projects/${projectContext?.project.id}/reports`;
    const num = (value: number) => formatNumber(value, locale);
    const date = (value: string) =>
        new Date(
            value.length === 10 ? `${value}T00:00:00` : value,
        ).toLocaleDateString(locale === 'fa' ? 'fa-IR' : 'en');
    const fieldClass = 'rounded border bg-background p-2 text-sm';
    const query = `from=${report.period.from}&to=${report.period.to}`;
    const show = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const value = (name: string) => {
            const entry = form.get(name);

            return typeof entry === 'string' ? entry : '';
        };
        router.get(base, { from: value('from'), to: value('to') });
    };
    const stat = (value: number, label: string) => (
        <div>
            <dd className="text-2xl font-semibold">{num(value)}</dd>
            <dt className="text-xs text-muted-foreground">{label}</dt>
        </div>
    );
    const card = (title: string, children: React.ReactNode) => (
        <section className="break-inside-avoid space-y-3 rounded-lg border p-4">
            <h2 className="font-semibold">{title}</h2>
            {children}
        </section>
    );
    const clicksChange = report.performance
        ? perDayChange(
              report.performance.totals,
              report.performance.previous,
              'clicks',
          )
        : null;

    return (
        <>
            <Head title={t('project.nav.reports')} />
            <ProjectWorkspaceShell>
                <div className="mx-auto max-w-5xl space-y-6" data-print-root>
                    <header className="space-y-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('project.nav.reports')}:{' '}
                            {projectContext?.project.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('report.period')}: {date(report.period.from)} –{' '}
                            {date(report.period.to)} · {t('report.generated')}:{' '}
                            {date(report.generated_at)}
                        </p>
                        <p
                            data-print-hide
                            className="max-w-2xl text-sm leading-6 text-muted-foreground"
                        >
                            {t('report.intro')}
                        </p>
                    </header>

                    <div
                        data-print-hide
                        className="flex flex-wrap items-end justify-between gap-3"
                    >
                        <form
                            onSubmit={show}
                            className="flex flex-wrap items-end gap-2"
                        >
                            <label className="space-y-1 text-sm">
                                <span className="block">
                                    {t('report.from')}
                                </span>
                                <input
                                    name="from"
                                    type="date"
                                    defaultValue={report.period.from}
                                    className={fieldClass}
                                />
                            </label>
                            <label className="space-y-1 text-sm">
                                <span className="block">{t('report.to')}</span>
                                <input
                                    name="to"
                                    type="date"
                                    defaultValue={report.period.to}
                                    className={fieldClass}
                                />
                            </label>
                            <Button type="submit" variant="outline">
                                {t('report.show')}
                            </Button>
                        </form>
                        <div className="flex gap-2">
                            <Button onClick={() => window.print()}>
                                {t('report.print')}
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={`${base}/report.csv?${query}`}>
                                    {t('report.csv')}
                                </a>
                            </Button>
                        </div>
                    </div>

                    {card(
                        `${t('report.path')} (${num(report.path.progress.done)}/${num(report.path.progress.total)})`,
                        <ol className="grid gap-1 text-sm sm:grid-cols-2">
                            {report.path.steps.map((step) => (
                                <li
                                    key={step.key}
                                    data-report-step={step.key}
                                    className="flex justify-between gap-2"
                                >
                                    <span>
                                        {guideStepCopy(step.key, locale)
                                            ?.title ?? step.key}
                                    </span>
                                    <span
                                        className={
                                            step.status === 'done'
                                                ? 'text-primary'
                                                : step.status === 'attention'
                                                  ? 'text-destructive'
                                                  : 'text-muted-foreground'
                                        }
                                    >
                                        {guideStatusLabel[locale][step.status]}
                                    </span>
                                </li>
                            ))}
                        </ol>,
                    )}

                    <div className="grid gap-4 md:grid-cols-2">
                        {card(
                            t('report.keywords'),
                            <dl className="grid grid-cols-4 gap-2">
                                {stat(
                                    report.keywords.keywords,
                                    t('report.keywordCount'),
                                )}
                                {stat(
                                    report.keywords.clusters,
                                    t('report.clusters'),
                                )}
                                {stat(
                                    report.keywords.mapped,
                                    t('report.mapped'),
                                )}
                                {stat(report.keywords.ready, t('report.ready'))}
                            </dl>,
                        )}
                        {card(
                            t('report.pages'),
                            <>
                                <dl className="grid grid-cols-5 gap-2">
                                    {stat(
                                        report.pages.existing,
                                        t('report.existing'),
                                    )}
                                    {stat(
                                        report.pages.planned,
                                        t('report.planned'),
                                    )}
                                    {stat(
                                        report.pages.on_page.todo,
                                        statusLabels[locale].todo,
                                    )}
                                    {stat(
                                        report.pages.on_page.in_progress,
                                        statusLabels[locale].in_progress,
                                    )}
                                    {stat(
                                        report.pages.on_page.done,
                                        statusLabels[locale].done,
                                    )}
                                </dl>
                                <p className="text-sm text-muted-foreground">
                                    {report.pages.last_check
                                        ? `${t('report.lastCheck')}: ${num(report.pages.last_check.checked)} ${t('report.checked')} · ${num(report.pages.last_check.errors)} ${t('report.errors')}`
                                        : t('report.noCheck')}
                                </p>
                            </>,
                        )}
                        {card(
                            t('report.content'),
                            <dl className="grid grid-cols-5 gap-2">
                                {stat(
                                    report.content.draft,
                                    briefStatusLabels[locale].draft,
                                )}
                                {stat(
                                    report.content.review,
                                    briefStatusLabels[locale].review,
                                )}
                                {stat(
                                    report.content.published,
                                    briefStatusLabels[locale].published,
                                )}
                                {stat(
                                    report.content.published_in_period,
                                    t('report.publishedInPeriod'),
                                )}
                                {stat(
                                    report.content.due_in_period,
                                    t('report.dueInPeriod'),
                                )}
                            </dl>,
                        )}
                        {card(
                            t('report.tasks'),
                            <dl className="grid grid-cols-4 gap-2">
                                {stat(report.tasks.open, t('report.open'))}
                                {stat(
                                    report.tasks.overdue,
                                    t('report.overdue'),
                                )}
                                {stat(
                                    report.tasks.done_in_period,
                                    t('report.doneInPeriod'),
                                )}
                                {stat(
                                    report.tasks.created_in_period,
                                    t('report.createdInPeriod'),
                                )}
                            </dl>,
                        )}
                        {card(
                            t('report.links'),
                            <dl className="grid grid-cols-2 gap-2">
                                {stat(
                                    report.links.added_in_period,
                                    t('report.linksInPeriod'),
                                )}
                                {stat(
                                    report.links.added_total,
                                    t('report.linksTotal'),
                                )}
                            </dl>,
                        )}
                    </div>

                    {card(
                        t('report.performance'),
                        report.performance ? (
                            <div className="space-y-3">
                                <p className="text-xs text-muted-foreground">
                                    {date(report.performance.period.from)} –{' '}
                                    {date(report.performance.period.to)}
                                </p>
                                <dl className="grid grid-cols-4 gap-2">
                                    {stat(
                                        report.performance.totals.clicks,
                                        t('perf.clicks'),
                                    )}
                                    {stat(
                                        report.performance.totals.impressions,
                                        t('perf.impressions'),
                                    )}
                                    <div>
                                        <dd className="text-2xl font-semibold">
                                            {formatPercent(
                                                report.performance.totals.ctr,
                                                locale,
                                            )}
                                        </dd>
                                        <dt className="text-xs text-muted-foreground">
                                            {t('perf.ctr')}
                                        </dt>
                                    </div>
                                    <div>
                                        <dd className="text-2xl font-semibold">
                                            {report.performance.totals
                                                .position === null
                                                ? '—'
                                                : num(
                                                      report.performance.totals
                                                          .position,
                                                  )}
                                        </dd>
                                        <dt className="text-xs text-muted-foreground">
                                            {t('perf.position')}
                                        </dt>
                                    </div>
                                </dl>
                                {clicksChange !== null && (
                                    <p className="text-sm">
                                        {t('perf.clicks')} —{' '}
                                        {t('perf.vsPrevious')}:{' '}
                                        <span
                                            className={
                                                clicksChange < 0
                                                    ? 'text-destructive'
                                                    : 'text-primary'
                                            }
                                        >
                                            {clicksChange > 0 ? '+' : ''}
                                            {formatPercent(
                                                clicksChange,
                                                locale,
                                            )}
                                        </span>
                                    </p>
                                )}
                                {report.performance.top_queries.length > 0 && (
                                    <table className="w-full text-sm">
                                        <caption className="pb-2 text-start font-medium">
                                            {t('report.topQueries')}
                                        </caption>
                                        <thead>
                                            <tr className="text-muted-foreground">
                                                <th className="p-1 text-start">
                                                    {t('perf.kind.queries')}
                                                </th>
                                                <th className="p-1 text-start">
                                                    {t('perf.clicks')}
                                                </th>
                                                <th className="p-1 text-start">
                                                    {t('perf.impressions')}
                                                </th>
                                                <th className="p-1 text-start">
                                                    {t('perf.position')}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {report.performance.top_queries.map(
                                                (row) => (
                                                    <tr
                                                        key={row.key}
                                                        className="border-t"
                                                    >
                                                        <td
                                                            className="p-1"
                                                            dir="auto"
                                                        >
                                                            {row.key}
                                                        </td>
                                                        <td className="p-1">
                                                            {num(row.clicks)}
                                                        </td>
                                                        <td className="p-1">
                                                            {num(
                                                                row.impressions,
                                                            )}
                                                        </td>
                                                        <td className="p-1">
                                                            {num(row.position)}
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                )}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('report.noPerformance')}
                            </p>
                        ),
                    )}

                    {card(
                        t('report.activity'),
                        report.activity.length > 0 ? (
                            <ul className="grid gap-1 text-sm sm:grid-cols-2">
                                {report.activity.map((row) => (
                                    <li
                                        key={row.event}
                                        data-activity={row.event}
                                        className="flex justify-between gap-2"
                                    >
                                        <span>
                                            {activityLabel(row.event, locale)}
                                        </span>
                                        <span className="font-medium">
                                            {num(row.count)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {t('report.noActivity')}
                            </p>
                        ),
                    )}
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
