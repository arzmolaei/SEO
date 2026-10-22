import { AlertCircle, Inbox, LoaderCircle } from 'lucide-react';
import { useLocale } from '@/hooks/use-locale';

type ProjectState = 'empty' | 'loading' | 'error';

export function ProjectStatePanel({
    state,
    title,
    description,
}: {
    state: ProjectState;
    title?: string;
    description?: string;
}) {
    const { t } = useLocale();

    if (state === 'loading') {
        return (
            <div
                className="flex min-h-64 items-center justify-center rounded-xl border border-dashed bg-muted/20 p-8 text-center"
                aria-live="polite"
            >
                <div className="space-y-3">
                    <LoaderCircle className="mx-auto size-6 animate-spin text-muted-foreground" />
                    <p className="text-sm text-muted-foreground">
                        {t('project.state.loading')}
                    </p>
                </div>
            </div>
        );
    }

    const isError = state === 'error';
    const Icon = isError ? AlertCircle : Inbox;
    const fallbackTitle = isError
        ? t('project.state.errorTitle')
        : t('project.section.emptyTitle');
    const fallbackDescription = isError
        ? t('project.state.errorDescription')
        : t('project.section.emptyDescription');

    return (
        <div
            className="flex min-h-64 items-center justify-center rounded-xl border border-dashed bg-muted/20 p-8 text-center"
            role={isError ? 'alert' : undefined}
        >
            <div className="max-w-lg space-y-3">
                <Icon className="mx-auto size-7 text-muted-foreground" />
                <div className="space-y-1">
                    <h2 className="font-medium">{title ?? fallbackTitle}</h2>
                    <p className="text-sm leading-6 text-muted-foreground">
                        {description ?? fallbackDescription}
                    </p>
                </div>
            </div>
        </div>
    );
}
