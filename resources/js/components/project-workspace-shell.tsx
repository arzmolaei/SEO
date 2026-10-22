import { usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { useLocale } from '@/hooks/use-locale';

type ProjectMode = 'guided' | 'expert';

export function ProjectWorkspaceShell({
    children,
}: {
    children: React.ReactNode;
}) {
    const { projectContext } = usePage().props;
    const { t } = useLocale();
    const [mode, setMode] = useState<ProjectMode>(
        projectContext?.mode ?? 'guided',
    );

    if (!projectContext) {
        return <>{children}</>;
    }

    return (
        <div className="flex min-w-0 flex-1 flex-col">
            <div className="border-b bg-background/95 px-4 py-3 backdrop-blur md:px-6">
                <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                    <div className="min-w-0">
                        <div className="truncate text-sm font-semibold">
                            {projectContext.project.name}
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            <span className="rounded-full border px-2 py-0.5">
                                {t('project.health.setup')}
                            </span>
                            <span>{t('project.sync.none')}</span>
                        </div>
                    </div>

                    <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                        <button
                            type="button"
                            className="flex h-9 min-w-0 items-center gap-2 rounded-md border bg-background px-3 text-start text-sm text-muted-foreground shadow-xs sm:w-64"
                            aria-disabled="true"
                        >
                            <Search className="size-4 shrink-0" />
                            <span className="truncate">{t('project.search')}</span>
                            <span className="ms-auto hidden rounded border px-1.5 py-0.5 text-[10px] sm:inline">
                                ⌘K
                            </span>
                        </button>

                        <div
                            className="grid grid-cols-2 rounded-md border bg-muted/40 p-1"
                            aria-label={`${t('project.mode.guided')} / ${t('project.mode.expert')}`}
                        >
                            <button
                                type="button"
                                className="rounded px-3 py-1 text-xs font-medium data-[active=true]:bg-background data-[active=true]:shadow-sm"
                                data-active={mode === 'guided'}
                                aria-pressed={mode === 'guided'}
                                onClick={() => setMode('guided')}
                            >
                                {t('project.mode.guided')}
                            </button>
                            <button
                                type="button"
                                className="rounded px-3 py-1 text-xs font-medium data-[active=true]:bg-background data-[active=true]:shadow-sm"
                                data-active={mode === 'expert'}
                                aria-pressed={mode === 'expert'}
                                onClick={() => setMode('expert')}
                            >
                                {t('project.mode.expert')}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <main className="min-w-0 flex-1 p-4 md:p-6">{children}</main>
        </div>
    );
}
