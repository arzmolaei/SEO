import { router } from '@inertiajs/react';
import { BookmarkPlus, Save, Star } from 'lucide-react';
import { useState } from 'react';
import { useLocale } from '@/hooks/use-locale';

export type SavedKeywordViewQuery = {
    q: string | null;
    source: string | null;
    status: 'review' | 'ready' | null;
    volume_operator: string | null;
    volume: number | null;
    sort: string;
    direction: 'asc' | 'desc';
    per_page: number;
};

export type SavedKeywordViewColumn =
    | 'keyword'
    | 'normalized'
    | 'status'
    | 'volume'
    | 'source'
    | 'created_at';

export type SavedKeywordViewState = {
    query: SavedKeywordViewQuery;
    columns: SavedKeywordViewColumn[];
};

export type SavedKeywordView = {
    id: number;
    name: string;
    schema_version: number;
    state: SavedKeywordViewState;
    is_default: boolean;
};

type Props = {
    path: string;
    savedViews: SavedKeywordView[];
    activeView: SavedKeywordView | null;
    currentState: SavedKeywordViewState;
    disabled?: boolean;
};

export function SavedKeywordViews({
    path,
    savedViews,
    activeView,
    currentState,
    disabled = false,
}: Props) {
    const { t } = useLocale();
    const [name, setName] = useState('');
    const [isWorking, setIsWorking] = useState(false);
    const [hasError, setHasError] = useState(false);
    const endpoint = path.replace(/\/keywords$/, '/keyword-views');

    const visitOptions = {
        preserveScroll: true,
        onStart: () => {
            setHasError(false);
            setIsWorking(true);
        },
        onFinish: () => setIsWorking(false),
        onError: () => setHasError(true),
    };

    const applyView = (viewId: string) => {
        if (viewId === '' || isWorking || disabled) {
            return;
        }

        router.get(
            path,
            { view: Number(viewId) },
            {
                ...visitOptions,
                preserveState: true,
                replace: true,
            },
        );
    };

    const createView = () => {
        const trimmedName = name.trim();

        if (trimmedName === '' || isWorking || disabled) {
            return;
        }

        router.post(
            endpoint,
            {
                name: trimmedName,
                state: currentState,
            },
            {
                ...visitOptions,
                preserveState: true,
                onSuccess: () => setName(''),
            },
        );
    };

    const updateView = () => {
        if (activeView === null || isWorking || disabled) {
            return;
        }

        router.patch(
            `${endpoint}/${activeView.id}`,
            {
                name: activeView.name,
                state: currentState,
            },
            {
                ...visitOptions,
                preserveState: true,
            },
        );
    };

    const setDefault = () => {
        if (
            activeView === null ||
            activeView.is_default ||
            isWorking ||
            disabled
        ) {
            return;
        }

        router.post(
            `${endpoint}/${activeView.id}/default`,
            {},
            {
                ...visitOptions,
                preserveState: true,
            },
        );
    };

    return (
        <section className="space-y-3 rounded-lg border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold">
                        {t('keywords.savedViews')}
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {t('keywords.savedViewsDescription')}
                    </p>
                </div>

                {activeView !== null ? (
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <span className="font-medium text-foreground">
                            {activeView.name}
                        </span>
                        {activeView.is_default ? (
                            <span className="inline-flex items-center gap-1 rounded-full border px-2 py-0.5">
                                <Star className="size-3" />
                                {t('keywords.defaultView')}
                            </span>
                        ) : null}
                    </div>
                ) : null}
            </div>

            <div className="grid gap-2 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                <label className="space-y-1 text-sm">
                    <span className="sr-only">{t('keywords.savedViews')}</span>
                    <select
                        value={activeView?.id ?? ''}
                        onChange={(event) => applyView(event.target.value)}
                        disabled={disabled || isWorking}
                        className="h-9 w-full rounded-md border bg-background px-3 outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                    >
                        <option value="" disabled>
                            {t('keywords.noSavedView')}
                        </option>
                        {savedViews.map((view) => (
                            <option key={view.id} value={view.id}>
                                {view.name}
                                {view.is_default
                                    ? ` — ${t('keywords.defaultView')}`
                                    : ''}
                            </option>
                        ))}
                    </select>
                </label>

                <label className="space-y-1 text-sm">
                    <span className="sr-only">{t('keywords.viewName')}</span>
                    <input
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        maxLength={80}
                        disabled={disabled || isWorking}
                        placeholder={t('keywords.viewName')}
                        className="h-9 w-full rounded-md border bg-background px-3 outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                    />
                </label>

                <button
                    type="button"
                    onClick={createView}
                    disabled={disabled || isWorking || name.trim().length === 0}
                    className="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-50"
                >
                    <BookmarkPlus className="size-4" />
                    {t('keywords.saveView')}
                </button>
            </div>

            {activeView !== null ? (
                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        onClick={updateView}
                        disabled={disabled || isWorking}
                        className="inline-flex h-8 items-center gap-2 rounded-md border bg-background px-3 text-sm font-medium hover:bg-muted disabled:opacity-50"
                    >
                        <Save className="size-4" />
                        {t('keywords.updateView')}
                    </button>
                    <button
                        type="button"
                        onClick={setDefault}
                        disabled={
                            disabled || isWorking || activeView.is_default
                        }
                        className="inline-flex h-8 items-center gap-2 rounded-md border bg-background px-3 text-sm font-medium hover:bg-muted disabled:opacity-50"
                    >
                        <Star className="size-4" />
                        {t('keywords.setDefaultView')}
                    </button>
                </div>
            ) : null}

            {hasError ? (
                <p role="alert" className="text-xs text-destructive">
                    {t('keywords.savedViewError')}
                </p>
            ) : null}
        </section>
    );
}
