<?php

namespace App\Services\Crawl;

use App\Models\CrawlChange;
use App\Models\CrawlRun;
use App\Models\CrawlUrl;
use Illuminate\Support\Collection;

/**
 * Builds a run's change set against the latest earlier comparable completed
 * run of the same project (master spec §17). Comparison runs in bounded,
 * checkpointed keyset batches so a killed worker resumes; inserts are
 * idempotent. A partial or differently scoped crawl is never used as
 * evidence, and a capped crawl never asserts that URLs were removed.
 */
class CrawlRunComparator
{
    public const BATCH = 500;

    private const COMPARED_FIELDS = [
        'status' => 'status_code',
        'title' => 'title',
        'meta_description' => 'meta_description',
        'canonical' => 'canonical',
        'indexability' => 'indexable',
        'internal_links' => 'internal_links',
    ];

    /** Returns true once the comparison is finished (or not applicable). */
    public function compare(CrawlRun $run): bool
    {
        if ($run->comparison_status === null) {
            $this->begin($run);
        }
        if ($run->comparison_status !== 'in_progress') {
            return true;
        }

        $baselineId = (int) $run->baseline_run_id;
        if ($run->comparison_phase === 'current') {
            $rows = $this->batch($run->id, $run->comparison_cursor);
            $this->compareCurrent($run, $baselineId, $rows);
            if ($rows->count() === self::BATCH) {
                $this->checkpoint($run, 'current', (string) $rows->last()?->url_hash);

                return false;
            }
            $this->checkpoint($run, $this->isTruncated($run) ? 'done' : 'removed', null);
            if ($run->comparison_phase === 'done') {
                return $this->finish($run);
            }

            return false;
        }

        $rows = $this->batch($baselineId, $run->comparison_cursor);
        $this->recordRemoved($run, $baselineId, $rows);
        if ($rows->count() === self::BATCH) {
            $this->checkpoint($run, 'removed', (string) $rows->last()?->url_hash);

            return false;
        }

        return $this->finish($run);
    }

    private function begin(CrawlRun $run): void
    {
        $earlier = CrawlRun::query()
            ->where('project_id', $run->project_id)
            ->where('id', '<', $run->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->get(['id', 'mode', 'normalized_start_url', 'allowed_hosts', 'settings']);
        $baseline = $earlier->first(fn (CrawlRun $candidate): bool => $this->comparable($run, $candidate));

        if ($baseline === null) {
            $run->forceFill([
                'comparison_status' => $earlier->isEmpty() ? 'no_baseline' : 'scope_changed',
                'change_counts' => null,
            ])->save();

            return;
        }

        $run->forceFill([
            'comparison_status' => 'in_progress',
            'baseline_run_id' => $baseline->id,
            'comparison_phase' => 'current',
            'comparison_cursor' => null,
        ])->save();
    }

    private function comparable(CrawlRun $run, CrawlRun $candidate): bool
    {
        $scope = static fn (CrawlRun $r): array => [
            $r->mode,
            $r->normalized_start_url,
            self::sorted($r->allowed_hosts),
            self::sorted($r->settings['include'] ?? []),
            self::sorted($r->settings['exclude'] ?? []),
            // Depth and URL caps change what a crawl can see.
            (int) ($r->settings['max_depth'] ?? 0),
            (int) ($r->settings['max_urls'] ?? 0),
        ];

        return $scope($run) === $scope($candidate);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /** @return Collection<int, CrawlUrl> */
    private function batch(int $runId, ?string $cursor): Collection
    {
        return CrawlUrl::query()
            ->where('crawl_run_id', $runId)
            ->when($cursor !== null, fn ($query) => $query->where('url_hash', '>', $cursor))
            ->orderBy('url_hash')
            ->limit(self::BATCH)
            ->get(['id', 'url_hash', 'normalized_url', 'state', 'status_code', 'title', 'meta_description', 'canonical', 'indexable', 'internal_links']);
    }

    /** @param  Collection<int, CrawlUrl>  $rows */
    private function compareCurrent(CrawlRun $run, int $baselineId, Collection $rows): void
    {
        $fetched = $rows->where('state', 'fetched');
        if ($fetched->isEmpty()) {
            return;
        }
        $baseline = CrawlUrl::query()
            ->where('crawl_run_id', $baselineId)
            ->whereIn('url_hash', $fetched->pluck('url_hash')->all())
            ->get(['url_hash', 'state', 'status_code', 'title', 'meta_description', 'canonical', 'indexable', 'internal_links'])
            ->keyBy('url_hash');

        $changes = [];
        foreach ($fetched as $row) {
            $before = $baseline->get($row->url_hash);
            if ($before === null) {
                $changes[] = $this->change($run, $baselineId, $row, 'added', null, $row->status_code);

                continue;
            }
            // A URL the baseline did not fetch proves nothing about change.
            if ($before->state !== 'fetched') {
                continue;
            }
            foreach (self::COMPARED_FIELDS as $type => $field) {
                $old = self::normalizedValue($before->getAttribute($field));
                $new = self::normalizedValue($row->getAttribute($field));
                if ($old !== $new) {
                    $changes[] = $this->change($run, $baselineId, $row, $type, $old, $new);
                }
            }
        }
        $this->insert($changes);
    }

    /** @param  Collection<int, CrawlUrl>  $rows */
    private function recordRemoved(CrawlRun $run, int $baselineId, Collection $rows): void
    {
        $fetched = $rows->where('state', 'fetched');
        if ($fetched->isEmpty()) {
            return;
        }
        $present = CrawlUrl::query()
            ->where('crawl_run_id', $run->id)
            ->whereIn('url_hash', $fetched->pluck('url_hash')->all())
            ->pluck('url_hash')
            ->flip();

        $changes = [];
        foreach ($fetched as $row) {
            if (! $present->has($row->url_hash)) {
                $changes[] = $this->change($run, $baselineId, $row, 'removed', $row->status_code, null);
            }
        }
        $this->insert($changes);
    }

    /** @return array<string, mixed> */
    private function change(CrawlRun $run, int $baselineId, CrawlUrl $row, string $type, mixed $before, mixed $after): array
    {
        return [
            'crawl_run_id' => $run->id,
            'baseline_run_id' => $baselineId,
            'url_hash' => $row->url_hash,
            'normalized_url' => $row->normalized_url,
            'change_type' => $type,
            'before' => json_encode(['value' => $before], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'after' => json_encode(['value' => $after], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ];
    }

    /** Whitespace-only differences in text are not changes. */
    private static function normalizedValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));

            return $value === '' ? null : $value;
        }

        return $value;
    }

    /** @param  list<array<string, mixed>>  $changes */
    private function insert(array $changes): void
    {
        foreach (array_chunk($changes, 200) as $chunk) {
            CrawlChange::query()->insertOrIgnore($chunk);
        }
    }

    private function isTruncated(CrawlRun $run): bool
    {
        return CrawlUrl::query()->where('crawl_run_id', $run->id)->count() >= $run->settings['max_urls'];
    }

    private function checkpoint(CrawlRun $run, string $phase, ?string $cursor): void
    {
        $run->forceFill(['comparison_phase' => $phase, 'comparison_cursor' => $cursor])->save();
    }

    private function finish(CrawlRun $run): bool
    {
        $counts = CrawlChange::query()->where('crawl_run_id', $run->id)
            ->selectRaw('change_type, count(*) as total')->groupBy('change_type')
            ->pluck('total', 'change_type')->map(fn ($total): int => (int) $total)->all();
        $counts['removed_authoritative'] = ! $this->isTruncated($run);
        $run->forceFill([
            'comparison_status' => 'compared',
            'comparison_phase' => null,
            'comparison_cursor' => null,
            'change_counts' => $counts,
        ])->save();

        return true;
    }
}
