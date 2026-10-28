import { Head, Link, router, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/hooks/use-locale';

type Status = 'todo' | 'doing' | 'done';
type View = 'open' | 'overdue' | 'done' | 'all';
type Linked = { id: number; label: string };
type Task = {
    id: number;
    title: string;
    notes: string | null;
    status: Status;
    due_on: string | null;
    overdue: boolean;
    completed_at: string | null;
    page: Linked | null;
    cluster: Linked | null;
};
type Paginated = {
    data: Task[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Filters = {
    view: View;
    page_id?: string;
    cluster_id?: string;
    q?: string;
};
const VIEWS: View[] = ['open', 'overdue', 'done', 'all'];
const STATUSES: Status[] = ['todo', 'doing', 'done'];

export default function Tasks({
    tasks,
    filters,
    counts,
    context,
    canEdit,
}: {
    tasks: Paginated;
    filters: Filters;
    counts: { open: number; overdue: number; done: number };
    context: { page: Linked | null; cluster: Linked | null };
    canEdit: boolean;
}) {
    const { projectContext, errors } = usePage().props;
    const { t, locale } = useLocale();
    const [busy, setBusy] = useState(false);
    const [title, setTitle] = useState('');
    const [due, setDue] = useState('');
    const [editing, setEditing] = useState<number | null>(null);
    const [draft, setDraft] = useState({ title: '', notes: '', due_on: '' });
    const projectBase = `/projects/${projectContext?.project.id}`;
    const base = `${projectBase}/tasks`;
    const num = (n: number) =>
        locale === 'fa' ? n.toLocaleString('fa-IR') : String(n);
    const date = (value: string) =>
        new Date(`${value}T00:00:00`).toLocaleDateString(
            locale === 'fa' ? 'fa-IR' : 'en',
        );
    const fieldClass = 'rounded border bg-background p-2 text-sm';
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
    };
    const query = (next: Partial<Filters>) => {
        const merged: Record<string, string> = {};
        for (const [key, value] of Object.entries({ ...filters, ...next })) {
            if (value) merged[key] = String(value);
        }

        return merged;
    };
    const visit = (next: Partial<Filters>) =>
        router.get(base, query(next), {
            ...options,
            only: ['tasks', 'filters', 'counts', 'context'],
        });
    const add = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (title.trim() === '') return;
        router.post(
            base,
            {
                title: title.trim(),
                due_on: due || null,
                page_id: context.page?.id ?? null,
                keyword_cluster_id: context.cluster?.id ?? null,
            },
            {
                ...options,
                onSuccess: () => {
                    setTitle('');
                    setDue('');
                },
            },
        );
    };
    const patch = (task: Task, data: Record<string, string | null>) =>
        router.patch(`${base}/${task.id}`, data, {
            ...options,
            onSuccess: () => setEditing(null),
        });
    const remove = (task: Task) => {
        if (window.confirm(t('tasks.deleteConfirm'))) {
            router.delete(`${base}/${task.id}`, options);
        }
    };
    const startEdit = (task: Task) => {
        setEditing(task.id);
        setDraft({
            title: task.title,
            notes: task.notes ?? '',
            due_on: task.due_on ?? '',
        });
    };
    const searchSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const value = new FormData(event.currentTarget).get('q');
        visit({ q: typeof value === 'string' ? value.trim() : '' });
    };
    const scoped = context.page ?? context.cluster;

    return (
        <>
            <Head title={t('project.nav.tasks')} />
            <ProjectWorkspaceShell>
                <div className="mx-auto max-w-5xl space-y-6" aria-busy={busy}>
                    <header className="space-y-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('project.nav.tasks')}
                        </h1>
                        <p className="max-w-2xl text-sm leading-6 text-muted-foreground">
                            {t('tasks.intro')}
                        </p>
                    </header>

                    {scoped && (
                        <p className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/40 p-3 text-sm">
                            <span>
                                {context.page
                                    ? t('tasks.forPage')
                                    : t('tasks.forCluster')}
                                : <strong>{scoped.label}</strong>
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    visit({
                                        page_id: undefined,
                                        cluster_id: undefined,
                                    })
                                }
                            >
                                {t('tasks.clearFilter')}
                            </Button>
                        </p>
                    )}

                    {canEdit ? (
                        <form onSubmit={add} className="flex flex-wrap gap-2">
                            <input
                                aria-label={t('tasks.newPlaceholder')}
                                placeholder={t('tasks.newPlaceholder')}
                                className={`${fieldClass} min-w-64 flex-1`}
                                maxLength={255}
                                value={title}
                                onChange={(event) =>
                                    setTitle(event.target.value)
                                }
                            />
                            <input
                                type="date"
                                aria-label={t('tasks.due')}
                                className={fieldClass}
                                value={due}
                                onChange={(event) => setDue(event.target.value)}
                            />
                            <Button
                                type="submit"
                                disabled={busy || title.trim() === ''}
                            >
                                {t('tasks.add')}
                            </Button>
                            <InputError
                                message={
                                    errors?.title ??
                                    errors?.due_on ??
                                    errors?.page_id ??
                                    errors?.keyword_cluster_id
                                }
                            />
                        </form>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {t('tasks.readOnly')}
                        </p>
                    )}

                    <div className="flex flex-wrap items-center justify-between gap-2">
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
                                    onClick={() => visit({ view })}
                                >
                                    {t(`tasks.view.${view}`)}
                                    {view !== 'all' &&
                                        ` (${num(counts[view])})`}
                                </Button>
                            ))}
                        </nav>
                        <form
                            onSubmit={searchSubmit}
                            role="search"
                            className="flex gap-2"
                        >
                            <input
                                name="q"
                                type="search"
                                aria-label={t('tasks.search')}
                                placeholder={t('tasks.search')}
                                defaultValue={filters.q ?? ''}
                                className={fieldClass}
                            />
                        </form>
                    </div>

                    {tasks.data.length === 0 ? (
                        <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                            {t('tasks.empty')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border">
                            {tasks.data.map((task) => (
                                <li
                                    key={task.id}
                                    data-task={task.id}
                                    data-status={task.status}
                                    className="space-y-2 p-4"
                                >
                                    {editing === task.id ? (
                                        <form
                                            className="space-y-2"
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                patch(task, {
                                                    title: draft.title,
                                                    notes: draft.notes || null,
                                                    due_on:
                                                        draft.due_on || null,
                                                });
                                            }}
                                        >
                                            <input
                                                aria-label={t('tasks.edit')}
                                                className={`${fieldClass} w-full`}
                                                maxLength={255}
                                                value={draft.title}
                                                onChange={(event) =>
                                                    setDraft({
                                                        ...draft,
                                                        title: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            <textarea
                                                aria-label={t('tasks.notes')}
                                                placeholder={t('tasks.notes')}
                                                rows={2}
                                                className={`${fieldClass} w-full`}
                                                value={draft.notes}
                                                onChange={(event) =>
                                                    setDraft({
                                                        ...draft,
                                                        notes: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            <div className="flex flex-wrap gap-2">
                                                <input
                                                    type="date"
                                                    aria-label={t('tasks.due')}
                                                    className={fieldClass}
                                                    value={draft.due_on}
                                                    onChange={(event) =>
                                                        setDraft({
                                                            ...draft,
                                                            due_on: event.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={busy}
                                                >
                                                    {t('tasks.save')}
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setEditing(null)
                                                    }
                                                >
                                                    {t('tasks.cancel')}
                                                </Button>
                                            </div>
                                        </form>
                                    ) : (
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="flex min-w-0 items-start gap-3">
                                                <input
                                                    type="checkbox"
                                                    className="mt-1"
                                                    aria-label={t(
                                                        'tasks.markDone',
                                                    )}
                                                    checked={
                                                        task.status === 'done'
                                                    }
                                                    disabled={!canEdit || busy}
                                                    onChange={(event) =>
                                                        patch(task, {
                                                            status: event.target
                                                                .checked
                                                                ? 'done'
                                                                : 'todo',
                                                        })
                                                    }
                                                />
                                                <div className="min-w-0 space-y-1">
                                                    <p
                                                        className={
                                                            task.status ===
                                                            'done'
                                                                ? 'text-muted-foreground line-through'
                                                                : 'font-medium'
                                                        }
                                                    >
                                                        {task.title}
                                                    </p>
                                                    {task.notes && (
                                                        <p className="text-sm whitespace-pre-line text-muted-foreground">
                                                            {task.notes}
                                                        </p>
                                                    )}
                                                    <p className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                                                        {task.due_on && (
                                                            <span
                                                                className={
                                                                    task.overdue
                                                                        ? 'text-destructive'
                                                                        : ''
                                                                }
                                                            >
                                                                {t('tasks.due')}
                                                                :{' '}
                                                                {date(
                                                                    task.due_on,
                                                                )}
                                                                {task.overdue &&
                                                                    ` · ${t('tasks.overdue')}`}
                                                            </span>
                                                        )}
                                                        {task.page && (
                                                            <Link
                                                                href={`${projectBase}/pages/${task.page.id}`}
                                                                className="hover:underline"
                                                            >
                                                                {t(
                                                                    'tasks.page',
                                                                )}
                                                                :{' '}
                                                                {
                                                                    task.page
                                                                        .label
                                                                }
                                                            </Link>
                                                        )}
                                                        {task.cluster && (
                                                            <span>
                                                                {t(
                                                                    'tasks.cluster',
                                                                )}
                                                                :{' '}
                                                                {
                                                                    task.cluster
                                                                        .label
                                                                }
                                                            </span>
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                            {canEdit && (
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <select
                                                        aria-label={t(
                                                            'tasks.edit',
                                                        )}
                                                        className={fieldClass}
                                                        value={task.status}
                                                        disabled={busy}
                                                        onChange={(event) =>
                                                            patch(task, {
                                                                status: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                    >
                                                        {STATUSES.map(
                                                            (status) => (
                                                                <option
                                                                    key={status}
                                                                    value={
                                                                        status
                                                                    }
                                                                >
                                                                    {t(
                                                                        `tasks.status.${status}`,
                                                                    )}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            startEdit(task)
                                                        }
                                                    >
                                                        {t('tasks.edit')}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            remove(task)
                                                        }
                                                    >
                                                        {t('tasks.delete')}
                                                    </Button>
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {(tasks.prev_page_url || tasks.next_page_url) && (
                        <nav className="flex justify-between gap-2">
                            <Button
                                variant="outline"
                                disabled={busy || !tasks.prev_page_url}
                                onClick={() =>
                                    tasks.prev_page_url &&
                                    router.get(tasks.prev_page_url, {}, options)
                                }
                            >
                                {t('pages.prev')}
                            </Button>
                            <Button
                                variant="outline"
                                disabled={busy || !tasks.next_page_url}
                                onClick={() =>
                                    tasks.next_page_url &&
                                    router.get(tasks.next_page_url, {}, options)
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
