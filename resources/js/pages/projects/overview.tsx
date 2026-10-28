import { Head, Link, usePage } from '@inertiajs/react';
import { CheckCircle2, CircleAlert, CircleDot, Clock3 } from 'lucide-react';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { useLocale } from '@/hooks/use-locale';
import {
    guideStatusLabel,
    guideStepCopy,
    type GuideStatus,
    type GuideStep,
} from '@/lib/project-guide';

type Guide = {
    steps: GuideStep[];
    next: string[];
    progress: { done: number; total: number };
};

const icons: Record<GuideStatus, typeof CheckCircle2> = {
    done: CheckCircle2,
    todo: CircleDot,
    attention: CircleAlert,
    waiting: Clock3,
};

const tone: Record<GuideStatus, string> = {
    done: 'text-emerald-600 dark:text-emerald-400',
    todo: 'text-primary',
    attention: 'text-amber-600 dark:text-amber-400',
    waiting: 'text-muted-foreground',
};

// The SEO plan export is a file download, not an Inertia page.
function StepLink({ step, label }: { step: GuideStep; label: string }) {
    const className =
        'inline-flex rounded-md border px-3 py-1.5 text-sm font-medium hover:bg-muted';

    return step.key === 'export' ? (
        <a href={step.href} className={className}>
            {label}
        </a>
    ) : (
        <Link href={step.href} className={className}>
            {label}
        </Link>
    );
}

export default function ProjectOverview({ guide }: { guide: Guide }) {
    const { t, locale, direction } = useLocale();
    const { projectContext } = usePage().props;
    const status = guideStatusLabel[locale];
    const next = guide.next
        .map((key) => guide.steps.find((step) => step.key === key))
        .filter((step): step is GuideStep => step !== undefined);

    return (
        <>
            <Head title={t('project.overview.title')} />
            <ProjectWorkspaceShell>
                <div
                    className="mx-auto w-full max-w-5xl space-y-8"
                    dir={direction}
                >
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {projectContext?.project.name ??
                                t('project.overview.title')}
                        </h1>
                        <p className="text-sm leading-6 text-muted-foreground">
                            {t('guide.intro')}
                        </p>
                    </div>

                    <section
                        aria-labelledby="guide-next"
                        className="space-y-3 rounded-xl border bg-card p-5"
                    >
                        <h2 id="guide-next" className="text-lg font-semibold">
                            {t('guide.nextTitle')}
                        </h2>
                        {next.length === 0 ? (
                            <p>{t('guide.allDone')}</p>
                        ) : (
                            <ol className="space-y-3">
                                {next.map((step) => {
                                    const text = guideStepCopy(
                                        step.key,
                                        locale,
                                    );
                                    if (!text) return null;

                                    return (
                                        <li
                                            key={step.key}
                                            className="flex flex-wrap items-center justify-between gap-3"
                                        >
                                            <div>
                                                <p className="font-medium">
                                                    {text.title}
                                                </p>
                                                <p className="text-sm text-muted-foreground">
                                                    {text.detail(
                                                        step.count,
                                                        step.total,
                                                    )}
                                                </p>
                                            </div>
                                            <StepLink
                                                step={step}
                                                label={text.action}
                                            />
                                        </li>
                                    );
                                })}
                            </ol>
                        )}
                    </section>

                    <section aria-labelledby="guide-path" className="space-y-3">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2
                                id="guide-path"
                                className="text-lg font-semibold"
                            >
                                {t('guide.pathTitle')}
                            </h2>
                            <p
                                className="text-sm text-muted-foreground"
                                data-test="guide-progress"
                            >
                                {t('guide.progress')}:{' '}
                                {guide.progress.done.toLocaleString(
                                    locale === 'fa' ? 'fa-IR' : 'en',
                                )}{' '}
                                /{' '}
                                {guide.progress.total.toLocaleString(
                                    locale === 'fa' ? 'fa-IR' : 'en',
                                )}
                            </p>
                        </div>
                        <ol className="space-y-3">
                            {guide.steps.map((step, index) => {
                                const text = guideStepCopy(step.key, locale);
                                if (!text) return null;
                                const Icon = icons[step.status];

                                return (
                                    <li
                                        key={step.key}
                                        className="rounded-xl border p-4"
                                        data-step={step.key}
                                        data-status={step.status}
                                    >
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="flex items-start gap-3">
                                                <Icon
                                                    className={`mt-0.5 size-5 shrink-0 ${tone[step.status]}`}
                                                    aria-hidden="true"
                                                />
                                                <div className="space-y-1">
                                                    <h3 className="font-medium">
                                                        {(
                                                            index + 1
                                                        ).toLocaleString(
                                                            locale === 'fa'
                                                                ? 'fa-IR'
                                                                : 'en',
                                                        )}
                                                        . {text.title}
                                                    </h3>
                                                    <p className="text-sm">
                                                        <span
                                                            className={
                                                                tone[
                                                                    step.status
                                                                ]
                                                            }
                                                        >
                                                            {
                                                                status[
                                                                    step.status
                                                                ]
                                                            }
                                                        </span>{' '}
                                                        —{' '}
                                                        {text.detail(
                                                            step.count,
                                                            step.total,
                                                        )}
                                                    </p>
                                                    <p className="text-sm leading-6 text-muted-foreground">
                                                        {text.explain}
                                                    </p>
                                                </div>
                                            </div>
                                            {step.status !== 'waiting' && (
                                                <StepLink
                                                    step={step}
                                                    label={text.action}
                                                />
                                            )}
                                        </div>
                                    </li>
                                );
                            })}
                        </ol>
                    </section>
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
