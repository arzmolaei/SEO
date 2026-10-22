import { router } from '@inertiajs/react';
import { BookmarkPlus, Save, Star } from 'lucide-react';
import { useState } from 'react';
import { useLocale } from '@/hooks/use-locale';

export type SavedKeywordViewState = {
    query: {
        q: string | null;
        source: string | null;
        status: 'review' | 'ready' | null;
        volume_operator: string | null;
        volume: number | string | null;
        sort: string;
        direction: 'asc' | 'desc';
        per_page: number;
    };
    columns: string[];
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
    onApplyColumns: (columns: string[]) => void;
};

export function SavedKeywordViewsToolbar({
    path,
    savedViews,
    activeView,
    currentState,
    disabled = false,
    onApplyColumns,
}: Props) {
    const { t } = useLocale();
    const [isCreating, setIsCreating] = useState(false);
    const [name, setName] = useState('');
    const [actionError, setActionError] = useState(false);
    const projectBase = path.replace(/\/keywords$/, '');

    const applyView = (id: number) => {
        const view = savedViews.find((candidate) => candidate.id === id);

        if (view === undefined) {
            return;
        }

        setActionError(false);
        onApplyColumns(view.state.columns);
        router.get(path, { view: id }, {
            preserveScroll: true,
            onError: () => setActionError(true),
        });
    };

    const createView = () => {
        const trimmedName = name.trim();

        if (trimmedName === '') {
            return;
        }

        setActionError(false);
        router.post(
            `${projectBase}/keyword-views`,
            {
                name: trimmedName,
                state: currentState,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setName('');
                    setIsCreating(false);
                },
                onError: () => setActionError(true),
            },
        );
    };

    const updateActiveView = () => {
        if (activeView === null) {
            return;
        }

        setActionError(false);
        router.patch(
            `${projectBase}/keyword-views/${activeView.id}`,
            {
                name: activeView.name,
                state: currentState,
            },
            {
                preserveScroll: true,
                onError: () => setActionError(true),
            },
        );
    };

    const setDefault = () => {
        if (activeView === null || activeView.is_default) {
            return;
        }

        setActionError(false);
        router.post(
            `${projectBase}/keyword-views/${activeView.id}/default`,
            {},
            {
                preserveScroll: true,
                onError: () => setActionError(true),
            },
        );
    };

    return (
        <div className="space-y-2 rounded-lg border bg-card p-3">
            <div className="flex flex-wrap items-center gap-2">
                <label className="min-w-52 flex-1 space-y-1 text-sm sm:flex-none">
                    <span className="sr-only">{t('keywords.savedViews')}</span>
                    <select
                        value={activeView?.id ?? ''}
                        onChange={(event) => {
                            const id = Number(event.target.value);

                            if (Number.isInteger(id) && id > 0) {
                                applyView(id);
                            }
                        }}
                        disabled={disabled || savedViews.length === 0}
                        className="h-9 w-full rounded-md border bg-background px-3 outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50"
                    >
                        <option value="">{t('keywords.noSavedView')}</option>
                        {savedViews.map((view) => (
                            <option key={view.id} value={view.id}>
                                {view.is_default ? '★ ' : ''}
                                {view.name}
                            </option>
                        ))}
                    </select>
                </label>

                {activeView !== null ? (
                    <>
                        <button
                            type="button"
                            onClick={updateActiveView}
                            disabled={disabled}
                            className="inline-flex h-9 items-center gap-2 rounded-md border bg-background px-3 text-sm font-medium hover:bg-muted disabled:opacity-50"
                        >
                            <Save className="size-4" />
                            {t('keywords.updateView')}
                        </button>
                        <button
                            type="button"
                            onClick={setDefault}
                            disabled={disabled || activeView.is_default}
                            className="inline-flex h-9 items-center gap-2 rounded-md border bg-background px-3 text-sm font-medium hover:bg-muted disabled:opacity-50"
                        >
                            <Star className="size-4" />
                            {activeView.is_default
                                ? t('keywords.defaultView')
                                : t('keywords.setDefaultView')}
                        </button>
                    </>
                ) : null}

                <div className="ms-auto flex min-w-60 flex-1 items-center justify-end gap-2 sm:flex-none">
                    {isCreating ? (
                        <>
                            <input
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        createView();
                                    }
                                }}
                                maxLength={80}
                                autoFocus
                                placeholder={t('keywords.viewName')}
                                className="h-9 min-w-0 flex-1 rounded-md border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            />
                            <button
                                type="button"
                                onClick={createView}
                                disabled={disabled || name.trim() === ''}
                                className="h-9 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-50"
                            >
                                {t('keywords.saveView')}
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setIsCreating(false);
                                    setName('');
                                }}
                                disabled={disabled}
                                className="h-9 rounded-md border bg-background px-3 text-sm font-medium hover:bg-muted disabled:opacity-50"
                            >
                                {t('common.cancel')}
                            </button>
                        </>
                    ) : (
                        <button
                            type="button"
                            onClick={() => setIsCreating(true)}
                            disabled={disabled}
                            className="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground disabled:opacity-50"
                        >
                            <BookmarkPlus className="size-4" />
                            {t('keywords.saveView')}
                        </button>
                    )}
                </div>
            </div>

            {actionError ? (
                <p role="alert" className="text-sm text-destructive">
                    {t('keywords.savedViewError')}
                </p>
            ) : null}
        </div>
    );
}
