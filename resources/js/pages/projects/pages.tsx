import { Head, Link, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/hooks/use-locale';
import { statusLabels } from '@/lib/on-page-checklist';

type PageRow = {
    id: number;
    title: string | null;
    kind: 'existing' | 'planned';
    url: string | null;
    seo_title: string | null;
    on_page_status: 'todo' | 'in_progress' | 'done';
    score: { passed: number; applicable: number };
    targets: { cluster_id: number; cluster: string; primary_keyword: string }[];
};
type Paginated = {
    data: PageRow[];
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Filters = { q?: string; status?: string; kind?: string };

export default function Pages({
    pages,
    filters,
}: {
    pages: Paginated;
    filters: Filters;
    canEdit: boolean;
}) {
    const { projectContext } = usePage().props;
    const { t, locale } = useLocale();
    const [busy, setBusy] = useState(false);
    const base = `/projects/${projectContext?.project.id}/pages`;
    const num = (n: number) =>
        locale === 'fa' ? n.toLocaleString('fa-IR') : String(n);
    const fieldClass = 'rounded border bg-background p-2 text-sm';
    const hasFilters = Boolean(filters.q || filters.status || filters.kind);

    const visit = (url: string, data: Filters = {}) =>
        router.get(url, data, {
            preserveScroll: true,
            preserveState: true,
            only: ['pages', 'filters'],
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
        });
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const data: Filters = {};
        for (const key of ['q', 'status', 'kind'] as const) {
            const value = form.get(key);
            if (typeof value === 'string' && value.trim() !== '') {
                data[key] = value.trim();
            }
        }
        visit(base, data);
    };

    return (
        <>
            <Head title={t('project.nav.pages')} />
            <ProjectWorkspaceShell>
                <div className="mx-auto max-w-5xl space-y-6" aria-busy={busy}>
                    <header className="space-y-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('project.nav.pages')}
                        </h1>
                        <p className="max-w-2xl text-sm leading-6 text-muted-foreground">
                            {t('pages.intro')}
                        </p>
                    </header>

                    <form
                        onSubmit={submit}
                        className="flex flex-wrap items-end gap-2"
                        role="search"
                    >
                        <input
                            name="q"
                            type="search"
                            aria-label={t('pages.search')}
                            placeholder={t('pages.search')}
                            defaultValue={filters.q ?? ''}
                            className={`${fieldClass} min-w-56 flex-1`}
                        />
                        <select
                            name="status"
                            aria-label={t('pages.status')}
                            defaultValue={filters.status ?? ''}
                            className={fieldClass}
                        >
                            <option value="">{t('pages.allStatuses')}</option>
                            {Object.entries(statusLabels[locale]).map(
                                ([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ),
                            )}
                        </select>
                        <select
                            name="kind"
                            aria-label={t('pages.allKinds')}
                            defaultValue={filters.kind ?? ''}
                            className={fieldClass}
                        >
                            <option value="">{t('pages.allKinds')}</option>
                            <option value="existing">
                                {t('pages.kind.existing')}
                            </option>
                            <option value="planned">
                                {t('pages.kind.planned')}
                            </option>
                        </select>
                        <Button type="submit" disabled={busy}>
                            {t('pages.filter')}
                        </Button>
                    </form>

                    {pages.data.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                            {hasFilters ? t('pages.noMatch') : t('pages.empty')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {pages.data.map((page) => {
                                const complete =
                                    page.score.applicable > 0 &&
                                    page.score.passed === page.score.applicable;

                                return (
                                    <li
                                        key={page.id}
                                        data-page={page.id}
                                        className="flex flex-wrap items-center justify-between gap-3 p-4"
                                    >
                                        <div className="min-w-0 space-y-1">
                                            <Link
                                                href={`${base}/${page.id}`}
                                                className="font-medium hover:underline"
                                            >
                                                {page.seo_title ||
                                                    page.title ||
                                                    page.url}
                                            </Link>
                                            <p
                                                dir="ltr"
                                                className="truncate text-start text-xs text-muted-foreground"
                                            >
                                                {page.url ??
                                                    t('pages.noAddress')}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {t(`pages.kind.${page.kind}`)}
                                                {' · '}
                                                {page.targets.length > 0
                                                    ? page.targets
                                                          .map(
                                                              (target) =>
                                                                  target.primary_keyword,
                                                          )
                                                          .join('، ')
                                                    : t('pages.noTarget')}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2 text-xs">
                                            <span
                                                className={`rounded-full px-3 py-1 ${complete ? 'bg-primary/10 text-primary' : 'border text-muted-foreground'}`}
                                                aria-label={t('pages.score')}
                                            >
                                                {num(page.score.passed)}/
                                                {num(page.score.applicable)}
                                            </span>
                                            <span className="rounded-full border px-3 py-1">
                                                {
                                                    statusLabels[locale][
                                                        page.on_page_status
                                                    ]
                                                }
                                            </span>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {(pages.prev_page_url || pages.next_page_url) && (
                        <nav className="flex justify-between gap-2">
                            <Button
                                variant="outline"
                                disabled={busy || !pages.prev_page_url}
                                onClick={() =>
                                    pages.prev_page_url &&
                                    visit(pages.prev_page_url)
                                }
                            >
                                {t('pages.prev')}
                            </Button>
                            <Button
                                variant="outline"
                                disabled={busy || !pages.next_page_url}
                                onClick={() =>
                                    pages.next_page_url &&
                                    visit(pages.next_page_url)
                                }
                            >
                                {t('pages.next')}
                            </Button>
                        </nav>
                    )}
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
