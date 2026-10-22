export type ProjectSectionKey =
    | 'overview'
    | 'keywords'
    | 'reviews'
    | 'pages'
    | 'site-audit'
    | 'content'
    | 'internal-links'
    | 'tasks'
    | 'performance'
    | 'reports'
    | 'integrations'
    | 'settings';

export type ProjectContext = {
    project: {
        id: number;
        workspace_id: number;
        name: string;
    };
    mode: 'guided';
    navigation: Array<{
        key: ProjectSectionKey;
        href: string;
    }>;
};
