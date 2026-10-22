import { Head } from '@inertiajs/react';
import { ProjectStatePanel } from '@/components/project-state-panel';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { useLocale } from '@/hooks/use-locale';

export default function ProjectOverview() {
    const { t } = useLocale();

    return (
        <>
            <Head title={t('project.overview.title')} />
            <ProjectWorkspaceShell>
                <div className="mx-auto w-full max-w-7xl space-y-6">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('project.overview.title')}
                        </h1>
                        <p className="text-sm leading-6 text-muted-foreground">
                            {t('project.overview.description')}
                        </p>
                    </div>

                    <ProjectStatePanel
                        state="empty"
                        title={t('project.overview.emptyTitle')}
                        description={t('project.overview.emptyDescription')}
                    />
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
