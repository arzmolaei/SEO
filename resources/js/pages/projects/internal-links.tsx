import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { useLocale } from '@/hooks/use-locale';

type View = 'open' | 'done' | 'dismissed';
type PageRef = { id: number; title: string; url: string | null };
type Suggestion = {
    source: PageRef;
    target: PageRef;
    anchor: string;
    alternatives: string[];
    shared: string[];
    score: number | null;
};
type Paginated = {
    data: Suggestion[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
const VIEWS: View[] = ['open', 'done', 'dismissed'];

export default function InternalLinks({
    links,
    filters,
    counts,
    focus,
    mapped,
    canEdit,
}: {
    links: Paginated;
    filters: { view: View; page_id: number | null };
    counts: Record<View, number>;
    focus: PageRef | null;
    mapped: number;
    canEdit: boolean;
}) {
    const { projectContext, errors } = usePage().props;
    const { t, locale, direction } = useLocale();
    const [busy, setBusy] = useState(false);
    const [copied, copy] = useClipboard();
    const projectBase = `/projects/${projectContext?.project.id}`;
    const base = `${projectBase}/internal-links`;
    const num = (n: number) =>
        locale === 'fa' ? n.toLocaleString('fa-IR') : String(n);
    const Arrow = direction === 'rtl' ? ArrowLeft : ArrowRight;
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
    };
    const visit = (view: View, pageId: number | null) =>
        router.get(
            base,
            {
                ...(view === 'open' ? {} : { view }),
                ...(pageId === null ? {} : { page_id: pageId }),
            },
            options,
        );
    const decide = (item: Suggestion, status: 'done' | 'dismissed' | null) =>
        router.post(
            `${base}/decisions`,
            {
                source_page_id: item.source.id,
                target_page_id: item.target.id,
                status,
                anchor: status === 'done' ? item.anchor : null,
            },
            options,
        );
    const pageLink = (page: PageRef) => (
        <Link
            href={`${projectBase}/pages/${page.id}`}
            className="font-medium hover:underline"
        >
            {page.title || page.url}
        </Link>
    );
    const anchorChip = (text: string) => (
        <button
            key={text}
            type="button"
            onClick={() => void copy(text)}
            className="rounded-full border px-3 py-1 text-sm hover:bg-muted"
            title={copied === text ? t('links.copied') : text}
        >
            {text}
            {copied === text && ` ✓`}
        </button>
    );

    return (
        <>
            <Head title={t('project.nav.internalLinks')} />
            <ProjectWorkspaceShell>
                <div className="mx-auto max-w-5xl space-y-6" aria-busy={busy}>
                    <header className="space-y-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('project.nav.internalLinks')}
                        </h1>
                        <p className="max-w-2xl text-sm leading-6 text-muted-foreground">
                            {t('links.intro')}
                        </p>
                    </header>

                    {focus && (
                        <p className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/40 p-3 text-sm">
                            <span>
                                {t('links.focus')}:{' '}
                                <strong>{focus.title || focus.url}</strong>
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => visit(filters.view, null)}
                            >
                                {t('links.clearFocus')}
                            </Button>
                        </p>
                    )}

                    <nav className="flex flex-wrap gap-2" role="tablist">
                        {VIEWS.map((view) => (
                            <Button
                                key={view}
                                role="tab"
                                aria-selected={filters.view === view}
                                variant={
                                    filters.view === view
                                        ? 'default'
                                        : 'outline'
                                }
                                size="sm"
                                onClick={() => visit(view, filters.page_id)}
                            >
                                {t(`links.view.${view}`)} ({num(counts[view])})
                            </Button>
                        ))}
                    </nav>
                    <InputError message={errors?.link} />

                    {links.data.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                            {mapped < 2
                                ? t('links.needMapping')
                                : t('links.empty')}
                        </p>
                    ) : (
                        <ul className="space-y-3">
                            {links.data.map((item) => (
                                <li
                                    key={`${item.source.id}-${item.target.id}`}
                                    data-link={`${item.source.id}-${item.target.id}`}
                                    className="space-y-3 rounded-lg border p-4"
                                >
                                    <p className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="text-muted-foreground">
                                            {t('links.from')}
                                        </span>
                                        {pageLink(item.source)}
                                        <Arrow
                                            className="size-4 text-muted-foreground"
                                            aria-hidden
                                        />
                                        {pageLink(item.target)}
                                        <span className="text-muted-foreground">
                                            {t('links.to')}
                                        </span>
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm text-muted-foreground">
                                            {t('links.anchor')}:
                                        </span>
                                        {anchorChip(item.anchor)}
                                        {item.alternatives.length > 0 && (
                                            <>
                                                <span className="text-sm text-muted-foreground">
                                                    {t('links.alternatives')}:
                                                </span>
                                                {item.alternatives.map(
                                                    anchorChip,
                                                )}
                                            </>
                                        )}
                                    </div>
                                    {item.shared.length > 0 && (
                                        <p className="text-xs text-muted-foreground">
                                            {t('links.shared')}:{' '}
                                            {item.shared.join('، ')}
                                        </p>
                                    )}
                                    {canEdit && (
                                        <div className="flex flex-wrap gap-2">
                                            {filters.view === 'open' ? (
                                                <>
                                                    <Button
                                                        size="sm"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            decide(item, 'done')
                                                        }
                                                    >
                                                        {t('links.done')}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            decide(
                                                                item,
                                                                'dismissed',
                                                            )
                                                        }
                                                    >
                                                        {t('links.dismiss')}
                                                    </Button>
                                                </>
                                            ) : (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        decide(item, null)
                                                    }
                                                >
                                                    {t('links.reopen')}
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {(links.prev_page_url || links.next_page_url) && (
                        <nav className="flex justify-between gap-2">
                            <Button
                                variant="outline"
                                disabled={busy || !links.prev_page_url}
                                onClick={() =>
                                    links.prev_page_url &&
                                    router.get(links.prev_page_url, {}, options)
                                }
                            >
                                {t('pages.prev')}
                            </Button>
                            <Button
                                variant="outline"
                                disabled={busy || !links.next_page_url}
                                onClick={() =>
                                    links.next_page_url &&
                                    router.get(links.next_page_url, {}, options)
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
