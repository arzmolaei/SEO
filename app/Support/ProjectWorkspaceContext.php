<?php

namespace App\Support;

use App\Models\Project;

class ProjectWorkspaceContext
{
    /**
     * @var list<string>
     */
    /**
     * Sections shown in the project navigation: only built, in-scope work
     * (docs/OWNER_INTENT.md). Planned slices are added here as they ship.
     *
     * @var list<string>
     */
    public const NAVIGATION = [
        'overview',
        'keywords',
        'imports',
        'reviews',
        'pages',
        'site-audit',
    ];

    public const SECTIONS = [
        'overview',
        'keywords',
        'imports',
        'reviews',
        'pages',
        'site-audit',
        'content',
        'internal-links',
        'tasks',
        'performance',
        'reports',
        'integrations',
        'settings',
    ];

    /**
     * @return array{
     *     project: array{id: int, workspace_id: int, name: string},
     *     mode: 'guided',
     *     navigation: list<array{key: string, href: string}>
     * }
     */
    public static function for(Project $project): array
    {
        return [
            'project' => [
                'id' => $project->id,
                'workspace_id' => $project->workspace_id,
                'name' => $project->name,
            ],
            'mode' => 'guided',
            'navigation' => array_map(
                fn (string $section): array => [
                    'key' => $section,
                    'href' => $section === 'pages'
                        ? route('projects.pages.index', $project)
                        : ($section === 'site-audit'
                        ? route('projects.crawls.index', $project)
                        : ($section === 'imports'
                        ? route('projects.keyword-imports.index', $project)
                        : ($section === 'overview'
                        ? route('projects.overview', $project)
                        : route('projects.section', [
                            'project' => $project,
                            'section' => $section,
                        ])))),
                ],
                self::NAVIGATION,
            ),
        ];
    }
}
