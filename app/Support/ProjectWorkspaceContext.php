<?php

namespace App\Support;

use App\Models\Project;

class ProjectWorkspaceContext
{
    /**
     * @var list<string>
     */
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
                    'href' => $section === 'imports'
                        ? route('projects.keyword-imports.index', $project)
                        : ($section === 'overview'
                        ? route('projects.overview', $project)
                        : route('projects.section', [
                            'project' => $project,
                            'section' => $section,
                        ])),
                ],
                self::SECTIONS,
            ),
        ];
    }
}
