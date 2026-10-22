import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    FileChartColumn,
    FilePenLine,
    Files,
    LayoutDashboard,
    LayoutGrid,
    Link2,
    ListTodo,
    Plug,
    Settings,
    ShieldCheck,
    Tags,
    type LucideIcon,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useLocale } from '@/hooks/use-locale';
import type { TranslationKey } from '@/i18n';
import { dashboard } from '@/routes';
import type { NavItem, ProjectSectionKey } from '@/types';

const sectionMeta: Record<
    ProjectSectionKey,
    { label: TranslationKey; icon: LucideIcon }
> = {
    overview: { label: 'project.nav.overview', icon: LayoutDashboard },
    keywords: { label: 'project.nav.keywords', icon: Tags },
    reviews: { label: 'project.nav.reviews', icon: ListTodo },
    pages: { label: 'project.nav.pages', icon: Files },
    'site-audit': { label: 'project.nav.siteAudit', icon: ShieldCheck },
    content: { label: 'project.nav.content', icon: FilePenLine },
    'internal-links': { label: 'project.nav.internalLinks', icon: Link2 },
    tasks: { label: 'project.nav.tasks', icon: ListTodo },
    performance: { label: 'project.nav.performance', icon: BarChart3 },
    reports: { label: 'project.nav.reports', icon: FileChartColumn },
    integrations: { label: 'project.nav.integrations', icon: Plug },
    settings: { label: 'project.nav.settings', icon: Settings },
};

export function AppSidebar() {
    const { projectContext } = usePage().props;
    const { t, direction } = useLocale();

    const items: NavItem[] = projectContext
        ? projectContext.navigation.map((item) => ({
              title: t(sectionMeta[item.key].label),
              href: item.href,
              icon: sectionMeta[item.key].icon,
          }))
        : [
              {
                  title: 'Dashboard',
                  href: dashboard(),
                  icon: LayoutGrid,
              },
          ];

    const homeHref = projectContext?.navigation[0]?.href ?? dashboard();

    return (
        <Sidebar
            side={direction === 'rtl' ? 'right' : 'left'}
            collapsible="icon"
            variant="inset"
        >
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={homeHref} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>

                {projectContext && (
                    <div className="mx-2 rounded-lg border border-sidebar-border/70 px-3 py-2 group-data-[collapsible=icon]:hidden">
                        <div className="truncate text-sm font-semibold">
                            {projectContext.project.name}
                        </div>
                        <div className="mt-1 text-xs text-muted-foreground">
                            {t('project.health.setup')}
                        </div>
                    </div>
                )}
            </SidebarHeader>

            <SidebarContent>
                <NavMain
                    items={items}
                    label={projectContext ? t('project.nav.label') : 'Platform'}
                />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
