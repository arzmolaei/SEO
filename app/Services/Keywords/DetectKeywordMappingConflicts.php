<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\ClusterTargetType;
use App\Domain\Keywords\MappingConflictType;
use App\Domain\Keywords\PersianKeywordNormalizer;
use App\Domain\Pages\PageKind;
use App\Domain\Review\ReviewItemType;
use App\Models\ActivityEvent;
use App\Models\ChangeSet;
use App\Models\ClusterTarget;
use App\Models\KeywordCluster;
use App\Models\KeywordMetric;
use App\Models\Project;
use App\Models\ReviewItem;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class DetectKeywordMappingConflicts
{
    private const CHUNK_SIZE = 500;

    public function __construct(
        private ProjectPolicy $policy,
        private PersianKeywordNormalizer $normalizer,
    ) {}

    public function handle(User $actor, Project $project): void
    {
        $this->authorize($actor, $project);

        DB::transaction(function () use ($actor, $project): void {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->syncReviews($actor, $project, $this->snapshot($project));
        });
    }

    public function currentFingerprint(User $actor, Project $project, int $keywordId): ?string
    {
        $this->authorize($actor, $project);

        return $this->snapshot($project)[$keywordId]['fingerprint'] ?? null;
    }

    private function authorize(User $actor, Project $project): void
    {
        if (! $this->policy->manageKeywords($actor, $project)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @return array<int, array{fingerprint:string, suggestion:array<string, mixed>}>
     */
    private function snapshot(Project $project): array
    {
        $clusters = $this->clusters($project);
        if ($clusters === []) {
            return [];
        }

        $targets = $this->targets($project);
        $latestVolumes = $this->latestVolumes(array_column($clusters, 'primary_keyword_id'));
        $warnings = [];
        $pageGroups = [];
        $topicGroups = [];

        foreach ($clusters as $clusterId => $cluster) {
            $target = $targets[$clusterId] ?? null;
            $invalid = $target !== null && ! $this->validTarget($target);

            if ($invalid) {
                $warnings[$clusterId][] = [
                    'type' => MappingConflictType::InvalidTarget->value,
                    'target_id' => $target['id'],
                    'target_type' => $target['type'],
                    'page_id' => $target['page_id'],
                ];
            }

            if ($target === null) {
                $metric = $latestVolumes[$cluster['primary_keyword_id']] ?? null;
                $threshold = (float) config('keyword-intelligence.mapping_conflicts.high_demand_volume', 1000);
                if ($metric !== null && $metric['value'] >= $threshold) {
                    $warnings[$clusterId][] = [
                        'type' => MappingConflictType::HighDemandUnmapped->value,
                        'metric_id' => $metric['id'],
                        'metric_source_id' => $metric['source_id'],
                        'metric_source' => $metric['source'],
                        'observed_at' => $metric['observed_at'],
                        'volume' => $metric['value'],
                        'threshold' => $threshold,
                    ];
                }

                continue;
            }

            if ($invalid || $target['type'] === ClusterTargetType::Ignore->value || $target['page_id'] === null) {
                continue;
            }

            $anchor = $cluster['anchor'];
            if ($anchor === null) {
                continue;
            }

            $pageGroups[$target['page_id']][] = $clusterId;
            if (! in_array($cluster['intent'], ['unknown', 'mixed'], true)) {
                $topicGroups[$cluster['intent'].'|'.$anchor][] = $clusterId;
            }
        }

        foreach ($pageGroups as $pageId => $clusterIds) {
            if (count($clusterIds) < 2) {
                continue;
            }
            $anchors = [];
            foreach ($clusterIds as $clusterId) {
                $anchors[] = $clusters[$clusterId]['anchor'];
            }
            $anchors = array_values(array_unique($anchors));
            sort($anchors, SORT_STRING);
            if (count($anchors) < 2) {
                continue;
            }

            sort($clusterIds, SORT_NUMERIC);
            $groupDigest = hash('sha256', json_encode([
                'page_id' => $pageId,
                'cluster_ids' => $clusterIds,
                'anchors' => $anchors,
            ], JSON_THROW_ON_ERROR));
            foreach ($clusterIds as $clusterId) {
                $warnings[$clusterId][] = [
                    'type' => MappingConflictType::OverloadedPage->value,
                    'page_id' => $pageId,
                    'related_count' => count($clusterIds),
                    'related_cluster_ids' => array_slice($clusterIds, 0, 10),
                    'group_digest' => $groupDigest,
                ];
            }
        }

        foreach ($topicGroups as $groupKey => $clusterIds) {
            if (count($clusterIds) < 2) {
                continue;
            }
            $pageIds = [];
            foreach ($clusterIds as $clusterId) {
                $pageId = $targets[$clusterId]['page_id'] ?? null;
                if ($pageId !== null) {
                    $pageIds[] = $pageId;
                }
            }
            $pageIds = array_values(array_unique($pageIds));
            sort($pageIds, SORT_NUMERIC);
            if (count($pageIds) < 2) {
                continue;
            }

            sort($clusterIds, SORT_NUMERIC);
            $groupDigest = hash('sha256', json_encode([
                'group' => $groupKey,
                'cluster_ids' => $clusterIds,
                'page_ids' => $pageIds,
            ], JSON_THROW_ON_ERROR));
            foreach ($clusterIds as $clusterId) {
                $warnings[$clusterId][] = [
                    'type' => MappingConflictType::CompetingPages->value,
                    'page_ids' => array_slice($pageIds, 0, 10),
                    'related_count' => count($clusterIds),
                    'related_cluster_ids' => array_slice($clusterIds, 0, 10),
                    'group_digest' => $groupDigest,
                ];
            }
        }

        $result = [];
        $algorithmVersion = (string) config('keyword-intelligence.mapping_conflicts.algorithm_version', 'mapping-conflicts-v1');
        foreach ($warnings as $clusterId => $clusterWarnings) {
            usort($clusterWarnings, fn (array $a, array $b): int => json_encode($a, JSON_THROW_ON_ERROR) <=> json_encode($b, JSON_THROW_ON_ERROR));
            $cluster = $clusters[$clusterId];
            $fingerprint = hash('sha256', json_encode([
                'algorithm' => $algorithmVersion,
                'keyword_id' => $cluster['primary_keyword_id'],
                'warnings' => $clusterWarnings,
            ], JSON_THROW_ON_ERROR));
            $result[$cluster['primary_keyword_id']] = [
                'fingerprint' => $fingerprint,
                'suggestion' => [
                    'original_text' => $cluster['display_text'],
                    'normalized_text' => $cluster['normalized_text'],
                    'intent' => $cluster['intent'],
                    'confidence' => 'medium',
                    'evidence' => $this->evidence($clusterWarnings),
                    'warnings' => $clusterWarnings,
                    'algorithm' => $algorithmVersion,
                    'cluster_id' => $clusterId,
                ],
            ];
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }

    /**
     * @return array<int, array{primary_keyword_id:int, display_text:string, normalized_text:string, intent:string, anchor:?string}>
     */
    private function clusters(Project $project): array
    {
        $rows = [];
        KeywordCluster::query()
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->with('primaryKeyword:id,project_id,display_text,normalized_text')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($clusters) use (&$rows): void {
                foreach ($clusters as $cluster) {
                    $keyword = $cluster->primaryKeyword;
                    $rows[$cluster->id] = [
                        'primary_keyword_id' => $keyword->id,
                        'display_text' => $keyword->display_text,
                        'normalized_text' => $keyword->normalized_text,
                        'intent' => $cluster->intent->value,
                        'anchor' => $this->anchor($keyword->normalized_text),
                    ];
                }
            });

        return $rows;
    }

    /**
     * @return array<int, array{id:int, type:string, page_id:?int, page_kind:?string, normalized_url:?string}>
     */
    private function targets(Project $project): array
    {
        $rows = [];
        ClusterTarget::query()
            ->where('project_id', $project->id)
            ->with('page:id,project_id,kind,normalized_url')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($targets) use (&$rows): void {
                foreach ($targets as $target) {
                    $rows[$target->keyword_cluster_id] = [
                        'id' => $target->id,
                        'type' => $target->type->value,
                        'page_id' => $target->page_id,
                        'page_kind' => $target->page?->kind->value,
                        'normalized_url' => $target->page?->normalized_url,
                    ];
                }
            });

        return $rows;
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, array{id:int, source_id:int, source:string, value:float, observed_at:string}>
     */
    private function latestVolumes(array $keywordIds): array
    {
        $rows = [];
        foreach (array_chunk($keywordIds, self::CHUNK_SIZE) as $chunk) {
            $latestObserved = KeywordMetric::query()
                ->whereIn('keyword_id', $chunk)
                ->where('metric', 'volume')
                ->selectRaw('keyword_id, MAX(observed_at) as latest_observed_at')
                ->groupBy('keyword_id')
                ->pluck('latest_observed_at', 'keyword_id');

            if ($latestObserved->isEmpty()) {
                continue;
            }

            $metrics = KeywordMetric::query()
                ->with('source:id,source')
                ->whereIn('keyword_id', array_map('intval', $latestObserved->keys()->all()))
                ->where('metric', 'volume')
                ->orderBy('keyword_id')
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->get();

            foreach ($metrics as $metric) {
                if (isset($rows[$metric->keyword_id])) {
                    continue;
                }
                $expected = $latestObserved->get($metric->keyword_id);
                if ($expected === null || $metric->observed_at->format('Y-m-d H:i:s') !== (string) $expected) {
                    continue;
                }
                $rows[$metric->keyword_id] = [
                    'id' => $metric->id,
                    'source_id' => $metric->keyword_source_id,
                    'source' => (string) $metric->source?->source,
                    'value' => (float) $metric->value,
                    'observed_at' => $metric->observed_at->toIso8601String(),
                ];
            }
        }

        return $rows;
    }

    /** @param array{id:int, type:string, page_id:?int, page_kind:?string, normalized_url:?string} $target */
    private function validTarget(array $target): bool
    {
        if ($target['type'] === ClusterTargetType::Ignore->value) {
            return $target['page_id'] === null;
        }
        if ($target['page_id'] === null) {
            return false;
        }

        $expectedKind = $target['type'] === ClusterTargetType::PlannedPage->value
            ? PageKind::Planned->value
            : PageKind::Existing->value;

        if ($target['page_kind'] !== $expectedKind) {
            return false;
        }

        return $expectedKind !== PageKind::Existing->value || $target['normalized_url'] !== null;
    }

    private function anchor(string $text): ?string
    {
        $stopWords = array_fill_keys(config('keyword-intelligence.clustering.stop_words', []), true);
        $normalized = $this->normalizer->normalize($text);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_unique(array_filter($tokens, fn (string $token): bool => ! isset($stopWords[$token]))));
        if (count($tokens) < 2) {
            return null;
        }

        return implode('\u{001F}', array_slice($tokens, 0, 2));
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     * @return list<string>
     */
    private function evidence(array $warnings): array
    {
        return array_map(function (array $warning): string {
            return match ($warning['type']) {
                MappingConflictType::CompetingPages->value => 'چند صفحه برای یک موضوع و intent مشترک رقابت می‌کنند؛ نگاشت به‌صورت خودکار تغییر نکرد.',
                MappingConflictType::OverloadedPage->value => 'یک صفحه به چند موضوع واژگانی متفاوت نگاشت شده است؛ این مورد نیازمند بازبینی است.',
                MappingConflictType::HighDemandUnmapped->value => sprintf('خوشه بدون نگاشت است و آخرین حجم جستجو %.0f (metric #%d) ثبت شده است.', $warning['volume'], $warning['metric_id']),
                MappingConflictType::InvalidTarget->value => 'نگاشت فعلی با قرارداد نوع صفحه/هدف سازگار نیست و بدون تغییر خودکار برای بازبینی علامت‌گذاری شد.',
                default => 'هشدار نگاشت نیازمند بازبینی است.',
            };
        }, $warnings);
    }

    /**
     * @param  array<int, array{fingerprint:string, suggestion:array<string, mixed>}>  $snapshot
     */
    private function syncReviews(User $actor, Project $project, array $snapshot): void
    {
        $existing = ReviewItem::query()
            ->where('project_id', $project->id)
            ->where('type', ReviewItemType::MappingConflict)
            ->lockForUpdate()
            ->get()
            ->keyBy('keyword_id');

        foreach ($snapshot as $keywordId => $current) {
            /** @var ReviewItem|null $review */
            $review = $existing->get($keywordId);
            if (! $review instanceof ReviewItem) {
                ReviewItem::query()->create([
                    'project_id' => $project->id,
                    'keyword_id' => $keywordId,
                    'type' => ReviewItemType::MappingConflict,
                    'status' => 'pending',
                    'fingerprint' => $current['fingerprint'],
                    'suggestion' => $current['suggestion'],
                ]);

                continue;
            }

            if (hash_equals($review->fingerprint, $current['fingerprint'])) {
                continue;
            }

            $wasResolved = $review->status === 'resolved';
            $review->update([
                'status' => 'pending',
                'fingerprint' => $current['fingerprint'],
                'suggestion' => $current['suggestion'],
                'resolution' => null,
                'resolved_by_id' => null,
                'resolved_at' => null,
            ]);
            if ($wasResolved) {
                $this->audit($actor, $project, $review, 'review.mapping_conflict.reopened', 'evidence_changed');
            }
        }

        foreach ($existing as $keywordId => $review) {
            if (isset($snapshot[(int) $keywordId]) || $review->status !== 'pending') {
                continue;
            }
            $review->update([
                'status' => 'resolved',
                'resolution' => 'superseded',
                'resolved_by_id' => $actor->id,
                'resolved_at' => now(),
            ]);
            $this->audit($actor, $project, $review, 'review.mapping_conflict.superseded', 'superseded');
        }
    }

    private function audit(User $actor, Project $project, ReviewItem $review, string $event, string $resolution): void
    {
        $changeSet = ChangeSet::query()->create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'actor_id' => $actor->id,
            'action' => $event,
            'changes' => [[
                'keyword_id' => $review->keyword_id,
                'before' => 'pending_or_resolved',
                'after' => $resolution,
            ]],
        ]);
        ActivityEvent::query()->create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'actor_id' => $actor->id,
            'change_set_id' => $changeSet->id,
            'event' => $event,
            'metadata' => [
                'review_id' => $review->id,
                'keyword_id' => $review->keyword_id,
                'fingerprint' => $review->fingerprint,
                'resolution' => $resolution,
            ],
        ]);
    }
}
