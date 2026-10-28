<?php

namespace App\Services\Pages;

use App\Domain\Keywords\PersianKeywordNormalizer;
use App\Domain\Pages\OnPageChecklist;
use App\Models\Page;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gathers the project facts the on-page checklist needs for a set of pages
 * with a fixed number of queries (never one query per page).
 */
class PageChecklistFacts
{
    /**
     * @param  Collection<int, Page>  $pages
     * @return array<int, array{items: list<array{key: string, status: 'pass'|'fix'|'na'}>, score: array{passed: int, applicable: int}, targets: list<array{cluster_id: int, cluster: string, primary_keyword: string}>}>
     */
    public function for(Project $project, Collection $pages): array
    {
        if ($pages->isEmpty()) {
            return [];
        }
        $targets = DB::table('cluster_targets')
            ->join('keyword_clusters', 'keyword_clusters.id', '=', 'cluster_targets.keyword_cluster_id')
            ->join('keywords', 'keywords.id', '=', 'keyword_clusters.primary_keyword_id')
            ->where('cluster_targets.project_id', $project->id)
            ->whereIn('cluster_targets.page_id', $pages->pluck('id')->all())
            ->whereNull('keyword_clusters.archived_at')
            ->orderBy('keyword_clusters.id')
            ->get(['cluster_targets.page_id', 'keyword_clusters.id as cluster_id', 'keyword_clusters.name as cluster', 'keywords.display_text as primary_keyword'])
            ->groupBy('page_id');

        $normalizer = new PersianKeywordNormalizer;
        $titleKey = static fn (?string $title): ?string => $title === null || trim($title) === '' ? null : mb_strtolower($normalizer->normalize($title));
        $titles = $pages->map(fn (Page $page): ?string => $titleKey($page->seo_title))->filter()->unique()->values();
        $titleCounts = [];
        if ($titles->isNotEmpty()) {
            // Compared after normalization, so the check needs the project's titles.
            foreach (Page::query()->where('project_id', $project->id)->whereNotNull('seo_title')->pluck('seo_title') as $title) {
                $key = $titleKey($title);
                if ($key !== null) {
                    $titleCounts[$key] = ($titleCounts[$key] ?? 0) + 1;
                }
            }
        }

        $facts = [];
        foreach ($pages as $page) {
            $pageTargets = array_values(($targets->get($page->id) ?? collect())->map(fn ($row): array => [
                'cluster_id' => (int) $row->cluster_id,
                'cluster' => (string) $row->cluster,
                'primary_keyword' => (string) $row->primary_keyword,
            ])->all());
            $key = $titleKey($page->seo_title);
            $items = OnPageChecklist::evaluate(
                [
                    'seo_title' => $page->seo_title,
                    'meta_description' => $page->meta_description,
                    'h1' => $page->h1,
                    'url' => $page->url,
                    'planned' => $page->kind->value === 'planned',
                ],
                $pageTargets[0]['primary_keyword'] ?? null,
                count($pageTargets),
                $key !== null && ($titleCounts[$key] ?? 0) > 1,
            );
            $facts[$page->id] = ['items' => $items, 'score' => OnPageChecklist::score($items), 'targets' => $pageTargets];
        }

        return $facts;
    }
}
