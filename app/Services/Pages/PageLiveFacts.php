<?php

namespace App\Services\Pages;

use App\Domain\Pages\LivePageCheck;
use App\Models\CrawlUrl;
use App\Models\Page;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The latest completed check of each page's own address, read from the
 * existing crawler's results (no new fetching here). Pages are matched by
 * their normalized-URL hash, so a page that answered with an error or a
 * redirect is still found. Fixed number of queries for any page set.
 */
class PageLiveFacts
{
    /**
     * @param  Collection<int, Page>  $pages
     * @return array<int, array{checked_at: ?string, status_code: ?int, final_url: ?string, title: ?string, meta_description: ?string, h1: list<string>, word_count: ?int, problems: list<array{key: string, value: string|int|null}>}>
     */
    public function for(Project $project, Collection $pages): array
    {
        $hashes = $pages->pluck('url_hash')->filter()->unique()->values()->all();
        if ($hashes === []) {
            return [];
        }
        // Row ids grow with every run, so the highest id is the latest check.
        $latest = DB::table('crawl_urls')
            ->join('crawl_runs', 'crawl_runs.id', '=', 'crawl_urls.crawl_run_id')
            ->where('crawl_runs.project_id', $project->id)
            ->where('crawl_runs.status', 'completed')
            ->whereIn('crawl_urls.url_hash', $hashes)
            ->whereIn('crawl_urls.state', ['fetched', 'failed', 'blocked'])
            ->groupBy('crawl_urls.url_hash')
            ->selectRaw('max(crawl_urls.id) as id');
        $rows = CrawlUrl::query()->whereIn('id', $latest)->get()->keyBy('url_hash');

        $facts = [];
        foreach ($pages as $page) {
            $row = $page->url_hash === null ? null : $rows->get($page->url_hash);
            if (! $row instanceof CrawlUrl) {
                continue;
            }
            $headings = $row->h1 ?? [];
            $live = [
                'state' => $row->state,
                'error_code' => $row->error_code,
                'status_code' => $row->status_code,
                'final_url' => $row->final_url,
                'content_type' => $row->content_type,
                'title' => $row->title,
                'meta_description' => $row->meta_description,
                'robots' => $row->robots,
                'canonical' => $row->canonical,
                'h1' => $headings,
                'word_count' => $row->word_count,
                'normalized_url' => $row->normalized_url,
            ];
            $facts[$page->id] = [
                'checked_at' => $row->fetched_at?->toIso8601String(),
                'status_code' => $row->status_code,
                'final_url' => $row->final_url,
                'title' => $row->title,
                'meta_description' => $row->meta_description,
                'h1' => $headings,
                'word_count' => $row->word_count,
                'problems' => LivePageCheck::problems($live, [
                    'seo_title' => $page->seo_title,
                    'meta_description' => $page->meta_description,
                    'h1' => $page->h1,
                ]),
            ];
        }

        return $facts;
    }
}
