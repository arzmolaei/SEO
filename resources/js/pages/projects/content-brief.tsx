import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import { useLocale } from '@/hooks/use-locale';
import type { BriefStatus } from '@/lib/content-brief';
import {
    briefAsText,
    briefStatusLabels,
    intentGuidance,
} from '@/lib/content-brief';

type Brief = {
    id: number;
    title: string;
    primary_keyword: string;
    intent: string;
    status: BriefStatus;
    due_on: string | null;
    published_on: string | null;
    secondary_keywords: string[];
    headings: string[];
    notes: string | null;
};

export default function ContentBrief({
    brief,
    page,
    canEdit,
}: {
    brief: Brief;
    page: { id: number; title: string | null; url: string | null } | null;
    canEdit: boolean;
}) {
    const { projectContext } = usePage().props;
    const { t, locale } = useLocale();
    const [copied, copy] = useClipboard();
    const base = `/projects/${projectContext?.project.id}`;
    const form = useForm({
        title: brief.title,
        headings: brief.headings.join('\n'),
        notes: brief.notes ?? '',
        status: brief.status,
        due_on: brief.due_on ?? '',
        published_on: brief.published_on ?? '',
    });
    const fieldClass =
        'w-full rounded border bg-background p-2 disabled:opacity-70';
    const headingsList = () =>
        form.data.headings
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== '');
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            headings: headingsList(),
            due_on: data.due_on || null,
            published_on: data.published_on || null,
        }));
        form.patch(`${base}/content/${brief.id}`, {
            preserveScroll: true,
            // The server may fill the publication date; show what was saved.
            onSuccess: (visit) => {
                const saved = visit.props.brief as Brief;
                form.setData('published_on', saved.published_on ?? '');
            },
        });
    };
    const remove = () => {
        if (window.confirm(t('content.deleteConfirm'))) {
            router.delete(`${base}/content/${brief.id}`);
        }
    };
    const text = briefAsText(
        {
            ...brief,
            title: form.data.title,
            headings: headingsList(),
            notes: form.data.notes || null,
        },
        locale,
    );

    return (
        <>
            <Head title={brief.title} />
            <ProjectWorkspaceShell>
                <div className="mx-auto max-w-5xl space-y-6">
                    <header className="space-y-2">
                        <Link
                            href={`${base}/content`}
                            className="text-sm text-muted-foreground hover:underline"
                        >
                            {t('content.back')}
                        </Link>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {brief.title}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {briefStatusLabels[locale][brief.status]}
                        </p>
                    </header>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <section
                            aria-labelledby="brief-facts"
                            className="space-y-4 rounded-lg border p-4"
                        >
                            <h2 id="brief-facts" className="sr-only">
                                {t('content.primary')}
                            </h2>
                            <div className="space-y-1">
                                <h3 className="text-sm text-muted-foreground">
                                    {t('content.primary')}
                                </h3>
                                <p className="font-medium">
                                    {brief.primary_keyword}
                                </p>
                            </div>
                            {brief.secondary_keywords.length > 0 && (
                                <div className="space-y-2">
                                    <h3 className="text-sm text-muted-foreground">
                                        {t('content.secondary')}
                                    </h3>
                                    <ul className="flex flex-wrap gap-2">
                                        {brief.secondary_keywords.map(
                                            (keyword) => (
                                                <li
                                                    key={keyword}
                                                    className="rounded-full border px-3 py-1 text-sm"
                                                >
                                                    {keyword}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </div>
                            )}
                            <div className="space-y-1">
                                <h3 className="text-sm text-muted-foreground">
                                    {t('content.intent')}
                                </h3>
                                <p className="text-sm leading-6">
                                    {intentGuidance(brief.intent, locale)}
                                </p>
                            </div>
                            <div className="space-y-1">
                                <h3 className="text-sm text-muted-foreground">
                                    {t('content.page')}
                                </h3>
                                {page ? (
                                    <Link
                                        href={`${base}/pages/${page.id}`}
                                        className="text-sm hover:underline"
                                    >
                                        {page.title || page.url}
                                    </Link>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {t('content.noPage')}
                                    </p>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <Button
                                    variant="outline"
                                    onClick={() => void copy(text)}
                                >
                                    {t('content.copy')}
                                </Button>
                                <span
                                    role="status"
                                    className="text-sm text-muted-foreground"
                                >
                                    {copied === text && t('content.copied')}
                                </span>
                            </div>
                        </section>

                        <section
                            aria-labelledby="brief-form"
                            className="space-y-4 rounded-lg border p-4"
                        >
                            <h2
                                id="brief-form"
                                className="text-lg font-semibold"
                            >
                                {t('content.title')}
                            </h2>
                            {!canEdit && (
                                <p className="text-sm text-muted-foreground">
                                    {t('content.readOnly')}
                                </p>
                            )}
                            <form onSubmit={submit}>
                                <fieldset
                                    disabled={!canEdit || form.processing}
                                    className="space-y-4"
                                >
                                    <div className="space-y-1">
                                        <Label htmlFor="title">
                                            {t('content.title')}
                                        </Label>
                                        <input
                                            id="title"
                                            className={fieldClass}
                                            maxLength={255}
                                            value={form.data.title}
                                            onChange={(event) =>
                                                form.setData(
                                                    'title',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.title}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="headings">
                                            {t('content.headings')}
                                        </Label>
                                        <textarea
                                            id="headings"
                                            rows={8}
                                            className={fieldClass}
                                            value={form.data.headings}
                                            onChange={(event) =>
                                                form.setData(
                                                    'headings',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.headings}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="notes">
                                            {t('content.notes')}
                                        </Label>
                                        <textarea
                                            id="notes"
                                            rows={4}
                                            className={fieldClass}
                                            value={form.data.notes}
                                            onChange={(event) =>
                                                form.setData(
                                                    'notes',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <div className="space-y-1">
                                            <Label htmlFor="status">
                                                {t('content.status')}
                                            </Label>
                                            <select
                                                id="status"
                                                className={fieldClass}
                                                value={form.data.status}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'status',
                                                        event.target
                                                            .value as BriefStatus,
                                                    )
                                                }
                                            >
                                                {Object.entries(
                                                    briefStatusLabels[locale],
                                                ).map(([value, label]) => (
                                                    <option
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="due_on">
                                                {t('content.due')}
                                            </Label>
                                            <input
                                                id="due_on"
                                                type="date"
                                                className={fieldClass}
                                                value={form.data.due_on}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'due_on',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={form.errors.due_on}
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="published_on">
                                                {t('content.publishedOn')}
                                            </Label>
                                            <input
                                                id="published_on"
                                                type="date"
                                                className={fieldClass}
                                                value={form.data.published_on}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'published_on',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={
                                                    form.errors.published_on
                                                }
                                            />
                                        </div>
                                    </div>
                                    {canEdit && (
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div className="flex items-center gap-3">
                                                <Button type="submit">
                                                    {t('content.save')}
                                                </Button>
                                                <p
                                                    role="status"
                                                    className="text-sm text-muted-foreground"
                                                >
                                                    {form.recentlySuccessful &&
                                                        t('content.saved')}
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="destructive"
                                                onClick={remove}
                                            >
                                                {t('content.delete')}
                                            </Button>
                                        </div>
                                    )}
                                </fieldset>
                            </form>
                        </section>
                    </div>
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
