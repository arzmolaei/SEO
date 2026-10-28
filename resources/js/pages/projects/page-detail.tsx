import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, CircleAlert, MinusCircle } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/hooks/use-locale';
import type { ChecklistStatus } from '@/lib/on-page-checklist';
import { checklistCopy, statusLabels } from '@/lib/on-page-checklist';

type Status = 'todo' | 'in_progress' | 'done';
type PageData = {
    id: number;
    title: string | null;
    kind: 'existing' | 'planned';
    url: string | null;
    seo_title: string | null;
    meta_description: string | null;
    h1: string | null;
    on_page_status: Status;
    on_page_notes: string | null;
};
type Target = { cluster_id: number; cluster: string; primary_keyword: string };

export default function PageDetail({
    page,
    targets,
    secondaryKeywords,
    checklist,
    score,
    canEdit,
}: {
    page: PageData;
    targets: Target[];
    secondaryKeywords: string[];
    checklist: { key: string; status: ChecklistStatus }[];
    score: { passed: number; applicable: number };
    canEdit: boolean;
}) {
    const { projectContext } = usePage().props;
    const { t, locale } = useLocale();
    const base = `/projects/${projectContext?.project.id}/pages`;
    const num = (n: number) =>
        locale === 'fa' ? n.toLocaleString('fa-IR') : String(n);
    const form = useForm({
        seo_title: page.seo_title ?? '',
        meta_description: page.meta_description ?? '',
        h1: page.h1 ?? '',
        on_page_status: page.on_page_status,
        on_page_notes: page.on_page_notes ?? '',
    });
    const fieldClass =
        'w-full rounded border bg-background p-2 disabled:opacity-70';
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.patch(`${base}/${page.id}`, { preserveScroll: true });
    };
    const counter = (value: string) => (
        <span className="text-xs text-muted-foreground">
            {num(value.length)} {t('pages.characters')}
        </span>
    );
    const icons = {
        pass: <CheckCircle2 className="size-5 shrink-0 text-primary" />,
        fix: <CircleAlert className="size-5 shrink-0 text-destructive" />,
        na: <MinusCircle className="size-5 shrink-0 text-muted-foreground" />,
    } as const;

    return (
        <>
            <Head
                title={page.seo_title || page.title || t('project.nav.pages')}
            />
            <ProjectWorkspaceShell>
                <div className="mx-auto max-w-5xl space-y-6">
                    <header className="space-y-2">
                        <Link
                            href={base}
                            className="text-sm text-muted-foreground hover:underline"
                        >
                            {t('pages.back')}
                        </Link>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {page.title || page.seo_title || page.url}
                        </h1>
                        <p
                            dir="ltr"
                            className="text-start text-sm text-muted-foreground"
                        >
                            {page.url ?? t('pages.noAddress')}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {t(`pages.kind.${page.kind}`)}
                            {' · '}
                            {t('pages.primaryKeyword')}:{' '}
                            {targets.length > 0
                                ? targets
                                      .map((target) => target.primary_keyword)
                                      .join('، ')
                                : t('pages.noTarget')}
                        </p>
                    </header>

                    <div className="grid gap-6 lg:grid-cols-2">
                        <section
                            aria-labelledby="page-form-title"
                            className="space-y-4 rounded-lg border p-4"
                        >
                            <h2
                                id="page-form-title"
                                className="text-lg font-semibold"
                            >
                                {t('pages.form')}
                            </h2>
                            {!canEdit && (
                                <p className="text-sm text-muted-foreground">
                                    {t('pages.readOnly')}
                                </p>
                            )}
                            <form onSubmit={submit} className="space-y-4">
                                <fieldset
                                    disabled={!canEdit || form.processing}
                                    className="space-y-4"
                                >
                                    <div className="space-y-1">
                                        <div className="flex justify-between gap-2">
                                            <Label htmlFor="seo_title">
                                                {t('pages.seoTitle')}
                                            </Label>
                                            {counter(form.data.seo_title)}
                                        </div>
                                        <input
                                            id="seo_title"
                                            className={fieldClass}
                                            maxLength={255}
                                            value={form.data.seo_title}
                                            onChange={(event) =>
                                                form.setData(
                                                    'seo_title',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.seo_title}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <div className="flex justify-between gap-2">
                                            <Label htmlFor="meta_description">
                                                {t('pages.metaDescription')}
                                            </Label>
                                            {counter(
                                                form.data.meta_description,
                                            )}
                                        </div>
                                        <textarea
                                            id="meta_description"
                                            rows={3}
                                            className={fieldClass}
                                            maxLength={500}
                                            value={form.data.meta_description}
                                            onChange={(event) =>
                                                form.setData(
                                                    'meta_description',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={
                                                form.errors.meta_description
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="h1">
                                            {t('pages.h1')}
                                        </Label>
                                        <input
                                            id="h1"
                                            className={fieldClass}
                                            maxLength={255}
                                            value={form.data.h1}
                                            onChange={(event) =>
                                                form.setData(
                                                    'h1',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError message={form.errors.h1} />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="on_page_status">
                                            {t('pages.status')}
                                        </Label>
                                        <select
                                            id="on_page_status"
                                            className={fieldClass}
                                            value={form.data.on_page_status}
                                            onChange={(event) =>
                                                form.setData(
                                                    'on_page_status',
                                                    event.target
                                                        .value as Status,
                                                )
                                            }
                                        >
                                            {Object.entries(
                                                statusLabels[locale],
                                            ).map(([value, label]) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={form.errors.on_page_status}
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor="on_page_notes">
                                            {t('pages.notes')}
                                        </Label>
                                        <textarea
                                            id="on_page_notes"
                                            rows={3}
                                            className={fieldClass}
                                            maxLength={5000}
                                            value={form.data.on_page_notes}
                                            onChange={(event) =>
                                                form.setData(
                                                    'on_page_notes',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.on_page_notes}
                                        />
                                    </div>
                                    {canEdit && (
                                        <div className="flex items-center gap-3">
                                            <Button type="submit">
                                                {t('pages.save')}
                                            </Button>
                                            <p
                                                role="status"
                                                className="text-sm text-muted-foreground"
                                            >
                                                {form.recentlySuccessful &&
                                                    t('pages.saved')}
                                                {form.hasErrors &&
                                                    t('pages.saveError')}
                                            </p>
                                        </div>
                                    )}
                                </fieldset>
                            </form>
                        </section>

                        <div className="space-y-6">
                            <section
                                aria-labelledby="checklist-title"
                                className="space-y-3 rounded-lg border p-4"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <h2
                                        id="checklist-title"
                                        className="text-lg font-semibold"
                                    >
                                        {t('pages.checklist')}
                                    </h2>
                                    <span className="rounded-full border px-3 py-1 text-sm">
                                        {num(score.passed)}/
                                        {num(score.applicable)}
                                    </span>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {t('pages.checklistHint')}
                                </p>
                                <ul className="space-y-3">
                                    {checklist.map((item) => {
                                        const copy = checklistCopy(
                                            item.key,
                                            locale,
                                        );

                                        return (
                                            <li
                                                key={item.key}
                                                data-check={item.key}
                                                data-status={item.status}
                                                className="flex gap-3"
                                            >
                                                {icons[item.status]}
                                                <div className="space-y-1">
                                                    <p
                                                        className={
                                                            item.status === 'na'
                                                                ? 'text-sm text-muted-foreground'
                                                                : 'text-sm font-medium'
                                                        }
                                                    >
                                                        {copy?.label ??
                                                            item.key}
                                                        <span className="sr-only">
                                                            {' — '}
                                                            {t(
                                                                `pages.${item.status}`,
                                                            )}
                                                        </span>
                                                    </p>
                                                    {item.status === 'fix' &&
                                                        copy && (
                                                            <p className="text-sm leading-6 text-muted-foreground">
                                                                {copy.fix}
                                                            </p>
                                                        )}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </section>

                            {secondaryKeywords.length > 0 && (
                                <section
                                    aria-labelledby="secondary-title"
                                    className="space-y-3 rounded-lg border p-4"
                                >
                                    <h2
                                        id="secondary-title"
                                        className="text-lg font-semibold"
                                    >
                                        {t('pages.secondaryKeywords')}
                                    </h2>
                                    <ul className="flex flex-wrap gap-2">
                                        {secondaryKeywords.map((keyword) => (
                                            <li
                                                key={keyword}
                                                className="rounded-full border px-3 py-1 text-sm"
                                            >
                                                {keyword}
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            )}
                        </div>
                    </div>
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
