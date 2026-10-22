<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\KeywordGridQuery;
use App\Models\Project;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

class QueryKeywordGrid
{
    /**
     * @return LengthAwarePaginator<int, array{
     *     id: int,
     *     display_text: string,
     *     normalized_text: string,
     *     status: string,
     *     latest_volume: string|null,
     *     latest_volume_source: string|null,
     *     created_at: string|null
     * }>
     */
    public function handle(Project $project, KeywordGridQuery $filters): LengthAwarePaginator
    {
        $projection = $this->projectKeywords($project, $filters);
        $query = DB::query()->fromSub($projection, 'keyword_grid');

        if ($filters->volume !== null) {
            match ($filters->volumeOperator) {
                'eq' => $query->where('keyword_grid.latest_volume', '=', $filters->volume),
                'gt' => $query->where('keyword_grid.latest_volume', '>', $filters->volume),
                'gte' => $query->where('keyword_grid.latest_volume', '>=', $filters->volume),
                'lt' => $query->where('keyword_grid.latest_volume', '<', $filters->volume),
                'lte' => $query->where('keyword_grid.latest_volume', '<=', $filters->volume),
                default => null,
            };
        }

        $this->applySort($query, $filters);

        /** @var LengthAwarePaginator<int, stdClass> $paginator */
        $paginator = $query->paginate($filters->perPage)->withQueryString();

        return $paginator->through(fn (stdClass $row): array => [
            'id' => (int) $row->id,
            'display_text' => (string) $row->display_text,
            'normalized_text' => (string) $row->normalized_text,
            'status' => (string) $row->status,
            'latest_volume' => $this->formatVolume($row->latest_volume ?? null),
            'latest_volume_source' => isset($row->latest_volume_source) ? (string) $row->latest_volume_source : null,
            'created_at' => isset($row->created_at) ? (string) $row->created_at : null,
        ]);
    }

    private function projectKeywords(Project $project, KeywordGridQuery $filters): Builder
    {
        $latestVolume = $this->latestVolumeSubquery($filters->source);
        $latestVolumeSource = $this->latestVolumeSourceSubquery($filters->source);

        $query = DB::table('keywords')
            ->select([
                'keywords.id',
                'keywords.display_text',
                'keywords.normalized_text',
                'keywords.status',
                'keywords.created_at',
            ])
            ->selectSub($latestVolume, 'latest_volume')
            ->selectSub($latestVolumeSource, 'latest_volume_source')
            ->where('keywords.project_id', $project->id);

        if ($filters->search !== null) {
            $search = '%'.$filters->search.'%';
            $query->where(function (Builder $nested) use ($search): void {
                $nested
                    ->where('keywords.display_text', 'like', $search)
                    ->orWhere('keywords.normalized_text', 'like', $search);
            });
        }

        if ($filters->source !== null) {
            $query->whereExists(function (Builder $sourceQuery) use ($filters): void {
                $sourceQuery
                    ->selectRaw('1')
                    ->from('keyword_sources as source_filter')
                    ->whereColumn('source_filter.keyword_id', 'keywords.id')
                    ->where('source_filter.source', $filters->source);
            });
        }

        if ($filters->status !== null) {
            $query->where('keywords.status', $filters->status->value);
        }

        return $query;
    }

    private function applySort(Builder $query, KeywordGridQuery $filters): void
    {
        if ($filters->sort === 'volume') {
            if ($filters->direction === 'desc') {
                $query->orderByDesc('keyword_grid.latest_volume');
            } else {
                $query->orderBy('keyword_grid.latest_volume');
            }
        } elseif ($filters->sort === 'created_at') {
            if ($filters->direction === 'desc') {
                $query->orderByDesc('keyword_grid.created_at');
            } else {
                $query->orderBy('keyword_grid.created_at');
            }
        } elseif ($filters->direction === 'desc') {
            $query->orderByDesc('keyword_grid.normalized_text');
        } else {
            $query->orderBy('keyword_grid.normalized_text');
        }

        $query->orderBy('keyword_grid.id');
    }

    private function latestVolumeSubquery(?string $source): Builder
    {
        $query = DB::table('keyword_metrics as latest_metric')
            ->select('latest_metric.value')
            ->whereColumn('latest_metric.keyword_id', 'keywords.id')
            ->where('latest_metric.metric', 'volume')
            ->orderByDesc('latest_metric.observed_at')
            ->orderByDesc('latest_metric.id')
            ->limit(1);

        if ($source !== null) {
            $query
                ->join('keyword_sources as latest_source', 'latest_source.id', '=', 'latest_metric.keyword_source_id')
                ->where('latest_source.source', $source);
        }

        return $query;
    }

    private function latestVolumeSourceSubquery(?string $source): Builder
    {
        $query = DB::table('keyword_metrics as source_metric')
            ->join('keyword_sources as metric_source', 'metric_source.id', '=', 'source_metric.keyword_source_id')
            ->select('metric_source.source')
            ->whereColumn('source_metric.keyword_id', 'keywords.id')
            ->where('source_metric.metric', 'volume')
            ->orderByDesc('source_metric.observed_at')
            ->orderByDesc('source_metric.id')
            ->limit(1);

        if ($source !== null) {
            $query->where('metric_source.source', $source);
        }

        return $query;
    }

    private function formatVolume(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }
}
