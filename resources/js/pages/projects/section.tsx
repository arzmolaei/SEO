import { Head } from '@inertiajs/react';
import { ProjectStatePanel } from '@/components/project-state-panel';
import { ProjectWorkspaceShell } from '@/components/project-workspace-shell';
import { useLocale } from '@/hooks/use-locale';
import type { TranslationKey } from '@/i18n';
import type { ProjectSectionKey } from '@/types';

const sectionLabels: Record<ProjectSectionKey, TranslationKey> = {
    overview: 'project.nav.overview',
    keywords: 'project.nav.keywords',
    pages: 'project.nav.pages',
    'site-audit': 'project.nav.siteAudit',
    content: 'project.nav.content',
    'internal-links': 'project.nav.internalLinks',
    tasks: 'project.nav.tasks',
    performance: 'project.nav.performance',
    reports: 'project.nav.reports',
    integrations: 'project.nav.integrations',
    settings: 'project.nav.settings',
};

export default function ProjectSection({
    section,
}: {
    section: ProjectSectionKey;
}) {
    const { t } = useLocale();
    const title = t(sectionLabels[section]);

    return (
        <>
            <Head title={title} />
            <ProjectWorkspaceShell>
                <div className="mx-auto w-full max-w-7xl space-y-6">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {title}
                        </h1>
                    </div>

                    <ProjectStatePanel state="empty" />
                </div>
            </ProjectWorkspaceShell>
        </>
    );
}
