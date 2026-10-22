import { Head, router, usePage } from '@inertiajs/react';
import { Check, RefreshCw, SkipForward } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ProjectStatePanel } from '@/components/project-state-panel';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/hooks/use-locale';

type Intent =
    | 'informational'
    | 'commercial'
    | 'transactional'
    | 'navigational'
    | 'local'
    | 'mixed'
    | 'unknown';
type Review = {
    type: 'intent' | 'cluster' | 'mapping_conflict';
    id: number;
    fingerprint: string;
    suggestion: {
        original_text: string;
        intent: Intent;
        confidence: 'high' | 'medium' | 'low';
        evidence: string[];
    };
};
type Reviews = {
    data: Review[];
    total: number;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export default function ReviewQueue({
    reviews,
    canManage,
    intents,
}: {
    reviews: Reviews;
    canManage: boolean;
    intents: Intent[];
}) {
    const { projectContext } = usePage().props;
    const { t } = useLocale();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<'error' | 'stale' | null>(null);
    const [queued, setQueued] = useState(false);
    const [choices, setChoices] = useState<Record<number, Intent>>({});
    const rows = useRef<Array<HTMLElement | null>>([]);
    const base = `/projects/${projectContext?.project.id}/reviews`;

    useEffect(() => {
        const stopHttp = router.on('httpException', (event) => {
            event.preventDefault();
            setError(event.detail.response.status === 409 ? 'stale' : 'error');
        });
        const stopNetwork = router.on('networkError', (event) => {
            event.preventDefault();
            setError('error');
        });
        return () => {
            stopHttp();
            stopNetwork();
        };
    }, []);

    const visitOptions = {
        preserveScroll: true,
        onStart: () => {
            setBusy(true);
            setError(null);
        },
        onError: () => setError('error'),
        onFinish: () => setBusy(false),
    };
    const reload = (url = base) => router.get(url, {}, visitOptions);
    const decide = (item: Review, decision: 'accept' | 'change' | 'skip') => {
        if (busy || !canManage) return;
        router.post(
            `${base}/${item.id}/resolve`,
            {
                decision,
                fingerprint: item.fingerprint,
                ...(decision === 'change'
                    ? { intent: choices[item.id] ?? item.suggestion.intent }
                    : {}),
            },
            {
                ...visitOptions,
                onSuccess: () => {
                    setChoices({});
                    requestAnimationFrame(() => rows.current[0]?.focus());
                },
            },
        );
    };

    return (
        <>
            <Head title={t('project.nav.reviews')} />
            <ProjectWorkspaceShell>
                <div className="mx-auto max-w-5xl space-y-6" aria-busy={busy}>
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div className="space-y-2">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {t('project.nav.reviews')}
                            </h1>
                            <p className="max-w-2xl text-sm leading-6 text-muted-foreground">
                                {t('review.description')}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => reload()}
                            >
                                <RefreshCw
                                    className={busy ? 'animate-spin' : ''}
                                />
                                {t('review.reload')}
                            </Button>
                            {canManage && (
                                <Button
                                    disabled={busy}
                                    onClick={() =>
                                        router.post(
                                            `${base}/refresh`,
                                            {},
                                            {
                                                ...visitOptions,
                                                onSuccess: () =>
                                                    setQueued(true),
                                            },
                                        )
                                    }
                                >
                                    {t('review.refresh')}
                                </Button>
                            )}
                        </div>
                    </header>
                    <div
                        role="status"
                        aria-live="polite"
                        className="text-sm text-muted-foreground"
                    >
                        {busy
                            ? t('review.pending')
                            : queued
                              ? t('review.queued')
                              : !canManage
                                ? t('review.readOnly')
                                : t('review.keyboard')}
                    </div>
                    {error && (
                        <div
                            role="alert"
                            className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-destructive/40 p-4 text-sm text-destructive"
                        >
                            <span>
                                {t(
                                    error === 'stale'
                                        ? 'review.stale'
                                        : 'review.error',
                                )}
                            </span>
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => reload()}
                            >
                                {t('keywords.retry')}
                            </Button>
                        </div>
                    )}
                    {reviews.data.length === 0 ? (
                        <ProjectStatePanel
                            state="empty"
                            title={t('review.empty')}
                            description={t('review.emptyDescription')}
                        />
                    ) : (
                        <div className="space-y-3">
                            {reviews.data.map((item, index) => (
                                <article
                                    key={`${item.id}-${item.fingerprint}`}
                                    ref={(node) => {
                                        rows.current[index] = node;
                                    }}
                                    tabIndex={0}
                                    aria-label={item.suggestion.original_text}
                                    className="space-y-4 rounded-xl border bg-card p-5 shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    onKeyDown={(event) => {
                                        if (
                                            event.target !==
                                                event.currentTarget ||
                                            event.altKey ||
                                            event.ctrlKey ||
                                            event.metaKey
                                        )
                                            return;
                                        if (
                                            event.key === 'ArrowDown' ||
                                            event.key === 'ArrowUp'
                                        ) {
                                            event.preventDefault();
                                            rows.current[
                                                Math.max(
                                                    0,
                                                    Math.min(
                                                        reviews.data.length - 1,
                                                        index +
                                                            (event.key ===
                                                            'ArrowDown'
                                                                ? 1
                                                                : -1),
                                                    ),
                                                )
                                            ]?.focus();
                                        } else if (
                                            event.key === 'Enter' ||
                                            event.key.toLowerCase() === 's'
                                        ) {
                                            event.preventDefault();
                                            decide(
                                                item,
                                                event.key === 'Enter'
                                                    ? 'accept'
                                                    : 'skip',
                                            );
                                        }
                                    }}
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <h2 className="text-lg font-semibold">
                                            {item.type === 'cluster'
                                                ? `${t('review.cluster')}: `
                                                : item.type ===
                                                    'mapping_conflict'
                                                  ? `${t('review.mappingConflict')}: `
                                                  : ''}
                                            {item.suggestion.original_text}
                                        </h2>
                                        <div className="flex flex-wrap gap-2 text-xs">
                                            <span className="rounded-full bg-primary/10 px-3 py-1 text-primary">
                                                {t(
                                                    `review.${item.suggestion.intent}`,
                                                )}
                                            </span>
                                            <span className="rounded-full border px-3 py-1 text-muted-foreground">
                                                {t('review.confidence')}:{' '}
                                                {t(
                                                    `review.${item.suggestion.confidence}`,
                                                )}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="space-y-1 text-sm leading-6 text-muted-foreground">
                                        <p className="font-medium text-foreground">
                                            {t('review.evidence')}
                                        </p>
                                        {item.suggestion.evidence.length ? (
                                            <ul className="list-inside list-disc">
                                                {item.suggestion.evidence.map(
                                                    (evidence, i) => (
                                                        <li key={i}>
                                                            {evidence}
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        ) : (
                                            <p>{t('review.noEvidence')}</p>
                                        )}
                                    </div>
                                    {canManage && (
                                        <div className="flex flex-wrap items-end gap-3 border-t pt-4">
                                            <Button
                                                disabled={busy}
                                                onClick={() =>
                                                    decide(item, 'accept')
                                                }
                                            >
                                                <Check />
                                                {t('review.accept')}
                                            </Button>
                                            {item.type === 'intent' && (
                                                <>
                                                    <label className="flex min-w-40 flex-col gap-1 text-xs text-muted-foreground">
                                                        {t('review.intent')}
                                                        <select
                                                            className="h-9 rounded-md border bg-background px-3 text-sm text-foreground"
                                                            disabled={busy}
                                                            value={
                                                                choices[
                                                                    item.id
                                                                ] ??
                                                                item.suggestion
                                                                    .intent
                                                            }
                                                            onChange={(event) =>
                                                                setChoices({
                                                                    ...choices,
                                                                    [item.id]:
                                                                        event
                                                                            .target
                                                                            .value as Intent,
                                                                })
                                                            }
                                                        >
                                                            {intents.map(
                                                                (intent) => (
                                                                    <option
                                                                        key={
                                                                            intent
                                                                        }
                                                                        value={
                                                                            intent
                                                                        }
                                                                    >
                                                                        {t(
                                                                            `review.${intent}`,
                                                                        )}
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    </label>
                                                    <Button
                                                        variant="outline"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            decide(
                                                                item,
                                                                'change',
                                                            )
                                                        }
                                                    >
                                                        {t('review.change')}
                                                    </Button>
                                                </>
                                            )}
                                            <Button
                                                variant="ghost"
                                                disabled={busy}
                                                onClick={() =>
                                                    decide(item, 'skip')
                                                }
                                            >
                                                <SkipForward />
                                                {t('review.skip')}
                                            </Button>
                                        </div>
                                    )}
                                </article>
                            ))}
                        </div>
                    )}
                    <footer className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
                        <span>
                            {t('keywords.total')}: {reviews.total} ·{' '}
                            {reviews.current_page} / {reviews.last_page}
                        </span>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                disabled={busy || !reviews.prev_page_url}
                                onClick={() =>
                                    reviews.prev_page_url &&
                                    reload(reviews.prev_page_url)
                                }
                            >
                                {t('keywords.previous')}
                            </Button>
                            <Button
                                variant="outline"
                                disabled={busy || !reviews.next_page_url}
                                onClick={() =>
                                    reviews.next_page_url &&
                                    reload(reviews.next_page_url)
                                }
                            >
                                {t('keywords.next')}
                            </Button>
                        </div>
                    </footer>
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
