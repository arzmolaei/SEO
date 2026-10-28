import { Form, Head, Link } from '@inertiajs/react';
import { FolderPlus, FolderOpen } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLocale } from '@/hooks/use-locale';

type WorkspaceItem = {
    id: number;
    name: string;
    canCreateProjects: boolean;
};

type ProjectItem = {
    id: number;
    name: string;
    workspace_name: string | null;
    overview_url: string;
    keywords_url: string;
    imports_url?: string;
};

type Paginated<T> = {
    data: T[];
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export default function Home({
    workspaces,
    projects,
}: {
    workspaces: WorkspaceItem[];
    projects: Paginated<ProjectItem>;
}) {
    const { t, direction } = useLocale();
    const creatable = workspaces.filter((item) => item.canCreateProjects);
    const [workspaceId, setWorkspaceId] = useState<number | null>(
        creatable[0]?.id ?? null,
    );

    return (
        <>
            <Head title={t('home.title')} />
            <div
                className="mx-auto w-full max-w-5xl space-y-6 p-4"
                dir={direction}
            >
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('home.title')}
                    </h1>
                    <p className="text-sm leading-6 text-muted-foreground">
                        {t('home.description')}
                    </p>
                </div>

                {workspaceId !== null ? (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FolderPlus
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {t('home.create')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Form
                                action={`/workspaces/${workspaceId}/projects`}
                                method="post"
                                disableWhileProcessing
                                resetOnSuccess={['name']}
                                className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            {creatable.length > 1 && (
                                                <div className="space-y-2">
                                                    <Label htmlFor="project_workspace">
                                                        {t('home.workspace')}
                                                    </Label>
                                                    <select
                                                        id="project_workspace"
                                                        className="h-9 w-full rounded-md border bg-background px-3 text-sm"
                                                        value={workspaceId}
                                                        onChange={(event) =>
                                                            setWorkspaceId(
                                                                Number(
                                                                    event.target
                                                                        .value,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        {creatable.map(
                                                            (item) => (
                                                                <option
                                                                    key={
                                                                        item.id
                                                                    }
                                                                    value={
                                                                        item.id
                                                                    }
                                                                >
                                                                    {item.name}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </div>
                                            )}
                                            <div className="space-y-2">
                                                <Label htmlFor="project_name">
                                                    {t('home.projectName')}
                                                </Label>
                                                <Input
                                                    id="project_name"
                                                    name="name"
                                                    required
                                                    maxLength={255}
                                                    autoComplete="off"
                                                />
                                                <InputError
                                                    message={errors.name}
                                                />
                                            </div>
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing && <Spinner />}
                                            {t('home.create')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                ) : (
                    workspaces.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('home.noPermission')}
                        </p>
                    )
                )}

                {projects.data.length === 0 ? (
                    <Card>
                        <CardContent className="space-y-2 py-10 text-center">
                            <p className="font-medium">{t('home.empty')}</p>
                            <p className="text-sm text-muted-foreground">
                                {t('home.emptyDescription')}
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <section aria-labelledby="projects-heading">
                        <h2 id="projects-heading" className="sr-only">
                            {t('home.title')}
                        </h2>
                        <ul className="divide-y rounded-xl border bg-card">
                            {projects.data.map((project) => (
                                <li
                                    key={project.id}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                                >
                                    <div className="min-w-0">
                                        <Link
                                            href={project.overview_url}
                                            className="font-medium hover:underline"
                                        >
                                            {project.name}
                                        </Link>
                                        {project.workspace_name && (
                                            <p className="text-xs text-muted-foreground">
                                                {project.workspace_name}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href={project.keywords_url}>
                                                {t('home.keywords')}
                                            </Link>
                                        </Button>
                                        {project.imports_url && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={project.imports_url}
                                                >
                                                    {t('home.import')}
                                                </Link>
                                            </Button>
                                        )}
                                        <Button size="sm" asChild>
                                            <Link href={project.overview_url}>
                                                <FolderOpen
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                {t('home.open')}
                                            </Link>
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <nav className="mt-4 flex items-center justify-between text-sm">
                            <span className="text-muted-foreground">
                                {t('home.total')}: {projects.total}
                            </span>
                            <span className="flex gap-2">
                                {projects.prev_page_url && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={projects.prev_page_url}>
                                            {t('home.previous')}
                                        </Link>
                                    </Button>
                                )}
                                {projects.next_page_url && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={projects.next_page_url}>
                                            {t('home.next')}
                                        </Link>
                                    </Button>
                                )}
                            </span>
                        </nav>
                    </section>
                )}
            </div>
        </>
    );
}

Home.layout = {
    breadcrumbs: [{ title: 'پروژه‌ها', href: '/dashboard' }],
};
