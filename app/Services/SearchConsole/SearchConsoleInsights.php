<?php

namespace App\Services\SearchConsole;

use App\Domain\Keywords\PersianKeywordNormalizer;
use App\Domain\SearchConsole\SearchOpportunities;
use App\Models\Project;
use App\Models\SearchConsoleImport;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read side of imported Search Console data: totals per import, the
 * latest-vs-previous comparison and the opportunity lists. Candidate rows
 * are narrowed in SQL so a large export never loads whole into memory.
 */
class SearchConsoleInsights
{
    public function latest(Project $project, string $kind, int $skip = 0): ?SearchConsoleImport
    {
        return SearchConsoleImport::query()->where('project_id', $project->id)->where('kind', $kind)
            ->orderByDesc('period_end')->orderByDesc('id')->skip($skip)->first();
    }

    /** @return array{clicks: int, impressions: int, ctr: float, position: float|null} */
    public function totals(SearchConsoleImport $import): array
    {
        $row = DB::table('search_console_rows')->where('search_console_import_id', $import->id)
            ->selectRaw('coalesce(sum(clicks), 0) as clicks, coalesce(sum(impressions), 0) as impressions, sum(position * impressions) as weighted')
            ->first();
        $clicks = (int) ($row->clicks ?? 0);
        $impressions = (int) ($row->impressions ?? 0);

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions === 0 ? 0.0 : round($clicks / $impressions, 4),
            // Impression-weighted, as Search Console averages position.
            'position' => $impressions === 0 ? null : round((float) $row->weighted / $impressions, 1),
        ];
    }

    /**
     * @return array{near_top: list<array<string, mixed>>, low_ctr: list<array<string, mixed>>, declining: list<array<string, mixed>>}
     */
    public function opportunities(Project $project): array
    {
        $queries = $this->latest($project, 'queries');
        $pages = $this->latest($project, 'pages');
        $previousPages = $this->latest($project, 'pages', 1);

        $nearTop = $queries === null ? [] : SearchOpportunities::nearTop($this->candidates($queries, fn ($q) => $q
            ->whereBetween('position', [SearchOpportunities::NEAR_TOP_MIN_POSITION, SearchOpportunities::NEAR_TOP_MAX_POSITION])
            ->where('impressions', '>=', SearchOpportunities::NEAR_TOP_MIN_IMPRESSIONS)
            ->orderByDesc('impressions')));
        $lowCtr = $queries === null ? [] : SearchOpportunities::lowCtr($this->candidates($queries, fn ($q) => $q
            ->where('position', '<=', SearchOpportunities::LOW_CTR_MAX_POSITION)
            ->where('impressions', '>=', SearchOpportunities::LOW_CTR_MIN_IMPRESSIONS)
            ->orderByDesc('impressions'), 2000));

        $declining = [];
        if ($pages !== null && $previousPages !== null) {
            $minimum = (int) ceil(SearchOpportunities::DECLINE_MIN_PREVIOUS_CLICKS_PER_DAY * $previousPages->days());
            $before = DB::table('search_console_rows')->where('search_console_import_id', $previousPages->id)
                ->where('clicks', '>=', $minimum)->orderByDesc('clicks')->limit(2000)
                ->get(['normalized_key', 'key_hash', 'clicks']);
            $now = DB::table('search_console_rows')->where('search_console_import_id', $pages->id)
                ->whereIn('key_hash', $before->pluck('key_hash')->all())
                ->get(['normalized_key', 'clicks', 'impressions', 'position']);
            $declining = SearchOpportunities::declining(
                array_values($now->map(fn ($row): array => [
                    'key' => (string) $row->normalized_key, 'clicks' => (int) $row->clicks,
                    'impressions' => (int) $row->impressions, 'position' => (float) $row->position,
                ])->all()),
                $pages->days(),
                $before->mapWithKeys(fn ($row): array => [(string) $row->normalized_key => (int) $row->clicks])->all(),
                $previousPages->days(),
            );
        }

        return [
            'near_top' => $this->withMatches($project, 'queries', $nearTop),
            'low_ctr' => $this->withMatches($project, 'queries', $lowCtr),
            'declining' => $this->withMatches($project, 'pages', $declining),
        ];
    }

    /**
     * @param  callable(Builder): Builder  $filter
     * @return list<array{key: string, clicks: int, impressions: int, position: float}>
     */
    private function candidates(SearchConsoleImport $import, callable $filter, int $limit = 1000): array
    {
        $query = $filter(DB::table('search_console_rows')->where('search_console_import_id', $import->id));

        return array_values($query->limit($limit)->get(['key_text', 'clicks', 'impressions', 'position'])
            ->map(fn ($row): array => [
                'key' => (string) $row->key_text, 'clicks' => (int) $row->clicks,
                'impressions' => (int) $row->impressions, 'position' => (float) $row->position,
            ])->all());
    }

    /**
     * Links a query to the owner's keyword (and its page) or a page address to
     * the owner's page, so an opportunity leads straight to the right screen.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function withMatches(Project $project, string $kind, array $items): array
    {
        if ($items === []) {
            return [];
        }
        if ($kind === 'pages') {
            $pages = DB::table('pages')->where('project_id', $project->id)
                ->whereIn('url_hash', array_map(fn (array $item): string => hash('sha256', (string) $item['key']), $items))
                ->get(['id', 'url_hash'])->keyBy('url_hash');

            return array_map(fn (array $item): array => $item + [
                'page_id' => isset($pages[hash('sha256', (string) $item['key'])]) ? (int) $pages[hash('sha256', (string) $item['key'])]->id : null,
                'keyword_id' => null,
            ], $items);
        }
        $normalizer = new PersianKeywordNormalizer;
        $keys = array_map(fn (array $item): string => $normalizer->normalize((string) $item['key']), $items);
        $keywords = DB::table('keywords')
            ->leftJoin('keyword_cluster_members', 'keyword_cluster_members.keyword_id', '=', 'keywords.id')
            ->leftJoin('cluster_targets', 'cluster_targets.keyword_cluster_id', '=', 'keyword_cluster_members.keyword_cluster_id')
            ->where('keywords.project_id', $project->id)
            ->whereIn('keywords.normalized_text', array_values(array_unique($keys)))
            ->get(['keywords.id', 'keywords.normalized_text', 'cluster_targets.page_id'])
            ->keyBy('normalized_text');

        return array_map(function (array $item, string $key) use ($keywords): array {
            $match = $keywords->get($key);

            return $item + [
                'keyword_id' => $match === null ? null : (int) $match->id,
                'page_id' => $match?->page_id === null ? null : (int) $match->page_id,
            ];
        }, $items, $keys);
    }
}
