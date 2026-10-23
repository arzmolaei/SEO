<?php

namespace App\Services\Exports;

use App\Domain\Keywords\ClusterPriorityScore;
use App\Domain\Keywords\ClusterReviewState;
use App\Domain\Review\ReviewItemType;
use App\Models\ClusterTarget;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordClusterMember;
use App\Models\KeywordIntentAssignment;
use App\Models\KeywordMetric;
use App\Models\KeywordSource;
use App\Models\Page;
use App\Models\Project;
use App\Models\ReviewItem;
use App\Support\Exports\SpreadsheetText;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

final class ProjectSeoExportSnapshot
{
    /**
     * @return array{rows:list<array<string,mixed>>,next_after_id:?int}
     */
    public function loadBatch(Project $project, ?int $afterId, int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Export batch limit must be positive.');
        }

        $keywords = Keyword::query()
            ->where('project_id', $project->id)
            ->when($afterId !== null, fn ($query) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $keywords->count() > $limit;
        $keywords = $keywords->take($limit)->values();
        if ($keywords->isEmpty()) {
            return ['rows' => [], 'next_after_id' => null];
        }

        /** @var list<int> $keywordIds */
        $keywordIds = $keywords->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $memberships = KeywordClusterMember::query()
            ->whereIn('keyword_id', $keywordIds)
            ->orderBy('id')
            ->get();
        $membershipByKeyword = [];
        $clusterIds = [];
        foreach ($memberships as $membership) {
            if ($membership->project_id !== $project->id || isset($membershipByKeyword[$membership->keyword_id])) {
                throw ProjectSeoExportException::projectRelation();
            }
            $membershipByKeyword[$membership->keyword_id] = $membership;
            $clusterIds[$membership->keyword_cluster_id] = $membership->keyword_cluster_id;
        }

        $clusters = KeywordCluster::query()->whereIn('id', array_values($clusterIds))->get()->keyBy('id');
        $primaryIds = [];
        foreach ($clusters as $cluster) {
            if ($cluster->project_id !== $project->id) {
                throw ProjectSeoExportException::projectRelation();
            }
            $primaryIds[$cluster->primary_keyword_id] = $cluster->primary_keyword_id;
        }
        if ($clusters->count() !== count($clusterIds)) {
            throw ProjectSeoExportException::projectRelation();
        }

        $relationKeywordIds = array_values(array_unique([...$keywordIds, ...array_values($primaryIds)]));
        $relationKeywords = Keyword::query()->whereIn('id', $relationKeywordIds)->get()->keyBy('id');
        foreach ($relationKeywordIds as $relationKeywordId) {
            $relationKeyword = $relationKeywords->get($relationKeywordId);
            if (! $relationKeyword instanceof Keyword || $relationKeyword->project_id !== $project->id) {
                throw ProjectSeoExportException::projectRelation();
            }
        }

        $sources = KeywordSource::query()
            ->whereIn('keyword_id', $relationKeywordIds)
            ->orderBy('id')
            ->get();
        $sourcesByKeyword = [];
        $sourceById = [];
        foreach ($sources as $source) {
            if (! in_array($source->keyword_id, $relationKeywordIds, true)) {
                throw ProjectSeoExportException::projectRelation();
            }
            $sourcesByKeyword[$source->keyword_id][] = $source;
            $sourceById[$source->id] = $source;
        }

        $currentIntents = $this->currentIntents($project, $keywordIds);
        $latestMetrics = $this->latestMetrics($relationKeywordIds, $sourceById);
        $targets = $this->targets($project, array_values($clusterIds));
        $pages = $this->pages($project, $targets);
        $pendingReviews = $this->pendingReviews($project, $relationKeywordIds);

        $rows = [];
        foreach ($keywords as $keyword) {
            $row = array_fill_keys(ProjectSeoExportSchema::HEADERS, null);
            $row['schema_version'] = ProjectSeoExportSchema::ID;
            $row['project_id'] = $project->id;
            $row['project_name'] = $project->name;
            $row['keyword_id'] = $keyword->id;
            $row['keyword_display_text'] = $keyword->display_text;
            $row['keyword_normalized_text'] = $keyword->normalized_text;
            $row['keyword_status'] = $keyword->status->value;

            $keywordSources = $sourcesByKeyword[$keyword->id] ?? [];
            $sourceNames = [];
            foreach ($keywordSources as $source) {
                $name = trim($source->source);
                if ($name !== '') {
                    $sourceNames[$name] = $name;
                }
            }
            $sourceNames = array_values($sourceNames);
            sort($sourceNames, SORT_STRING);
            $row['source_count'] = count($keywordSources);
            $row['source_names_json'] = json_encode(
                $sourceNames,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );

            $intent = $currentIntents[$keyword->id] ?? null;
            if ($intent instanceof KeywordIntentAssignment) {
                $row['intent_assignment_id'] = $intent->id;
                $row['intent'] = $intent->intent->value;
                $row['intent_confidence'] = $intent->confidence->value;
                $row['intent_source'] = $intent->source;
                $row['intent_updated_at_utc'] = $this->iso($intent->updated_at);
            }

            $this->fillMetric($row, 'volume', $latestMetrics[$keyword->id]['volume'] ?? null, $sourceById);
            $this->fillMetric($row, 'difficulty', $latestMetrics[$keyword->id]['difficulty'] ?? null, $sourceById);

            $membership = $membershipByKeyword[$keyword->id] ?? null;
            $cluster = $membership instanceof KeywordClusterMember
                ? $clusters->get($membership->keyword_cluster_id)
                : null;
            $target = null;
            $page = null;
            if ($cluster instanceof KeywordCluster) {
                if (! $relationKeywords->has($cluster->primary_keyword_id)) {
                    throw ProjectSeoExportException::projectRelation();
                }

                $row['cluster_id'] = $cluster->id;
                $row['cluster_name'] = $cluster->name;
                $row['cluster_primary_keyword_id'] = $cluster->primary_keyword_id;
                $row['cluster_is_primary'] = $cluster->primary_keyword_id === $keyword->id;
                $row['cluster_intent'] = $cluster->intent->value;
                $row['cluster_confidence'] = $cluster->confidence->value;
                $row['cluster_review_state'] = $cluster->review_state->value;
                $row['cluster_business_relevance'] = $cluster->business_relevance;
                $row['cluster_lifecycle_state'] = $cluster->lifecycle_state->value;
                $row['cluster_is_locked'] = $cluster->is_locked;
                $row['cluster_revision'] = $cluster->revision;
                $row['cluster_origin'] = $cluster->origin;
                $row['cluster_archived_at_utc'] = $this->iso($cluster->archived_at);

                $target = $targets[$cluster->id] ?? null;
                if ($target instanceof ClusterTarget) {
                    $row['target_id'] = $target->id;
                    $row['target_type'] = $target->type->value;
                    $row['target_revision'] = $target->revision;
                    if ($target->page_id !== null) {
                        $page = $pages[$target->page_id] ?? null;
                        if (! $page instanceof Page) {
                            throw ProjectSeoExportException::projectRelation();
                        }
                        $row['page_id'] = $page->id;
                        $row['page_title'] = $page->title;
                        $row['page_kind'] = $page->kind->value;
                        $row['page_type'] = $page->page_type;
                        $row['page_url'] = $page->url;
                    }
                }
            }

            $row['keyword_pending_review_count'] = $pendingReviews[$keyword->id]['count'] ?? 0;
            if ($cluster instanceof KeywordCluster) {
                $primaryPending = $pendingReviews[$cluster->primary_keyword_id]['count'] ?? 0;
                $row['cluster_primary_pending_review_count'] = $primaryPending;
                $row['cluster_primary_pending_mapping_conflict'] = (bool) ($pendingReviews[$cluster->primary_keyword_id]['mapping_conflict'] ?? false);

                if ($cluster->archived_at === null) {
                    $primaryVolume = $latestMetrics[$cluster->primary_keyword_id]['volume'] ?? null;
                    $primaryDifficulty = $latestMetrics[$cluster->primary_keyword_id]['difficulty'] ?? null;
                    $priority = ClusterPriorityScore::calculate(
                        volume: $primaryVolume instanceof KeywordMetric && $primaryVolume->value !== null ? (float) $primaryVolume->value : null,
                        difficulty: $primaryDifficulty instanceof KeywordMetric && $primaryDifficulty->value !== null ? (float) $primaryDifficulty->value : null,
                        businessRelevance: $cluster->business_relevance,
                        hasMapping: $target instanceof ClusterTarget,
                        reviewComplete: $cluster->review_state === ClusterReviewState::Reviewed && $primaryPending === 0,
                        volumeEvidence: $this->metricEvidence($primaryVolume, $sourceById),
                        difficultyEvidence: $this->metricEvidence($primaryDifficulty, $sourceById),
                    );

                    $row['priority_basis_keyword_id'] = $cluster->primary_keyword_id;
                    $this->fillMetric($row, 'priority_volume', $primaryVolume, $sourceById);
                    $this->fillMetric($row, 'priority_difficulty', $primaryDifficulty, $sourceById);
                    $row['priority_business_relevance'] = $cluster->business_relevance;
                    $row['priority_has_mapping'] = $target instanceof ClusterTarget;
                    $row['priority_review_complete'] = $cluster->review_state === ClusterReviewState::Reviewed && $primaryPending === 0;
                    $row['priority_score'] = $priority->score;
                    $row['priority_data_completeness'] = $priority->dataCompleteness;
                    $row['priority_search_demand_available'] = $priority->components['search_demand']['available'];
                    $row['priority_search_demand_points'] = $priority->components['search_demand']['points'];
                    $row['priority_difficulty_available'] = $priority->components['difficulty']['available'];
                    $row['priority_difficulty_points'] = $priority->components['difficulty']['points'];
                    $row['priority_business_relevance_available'] = $priority->components['business_relevance']['available'];
                    $row['priority_business_relevance_points'] = $priority->components['business_relevance']['points'];
                    $row['priority_mapping_points'] = $priority->components['mapping']['points'];
                    $row['priority_review_points'] = $priority->components['review']['points'];
                }
            }

            $row['exact_text_json'] = SpreadsheetText::exactJson([
                'project_name' => $project->name,
                'keyword_display_text' => $keyword->display_text,
                'keyword_normalized_text' => $keyword->normalized_text,
                'cluster_name' => $cluster instanceof KeywordCluster ? $cluster->name : null,
                'page_title' => $page instanceof Page ? $page->title : null,
                'page_url' => $page instanceof Page ? $page->url : null,
            ]);

            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'next_after_id' => $hasMore ? (int) $keywords->last()->id : null,
        ];
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int,KeywordIntentAssignment>
     */
    private function currentIntents(Project $project, array $keywordIds): array
    {
        $grouped = [];
        $intents = KeywordIntentAssignment::query()
            ->whereIn('keyword_id', $keywordIds)
            ->where('is_current', true)
            ->orderBy('id')
            ->get();

        foreach ($intents as $intent) {
            if ($intent->project_id !== $project->id || ! in_array($intent->keyword_id, $keywordIds, true)) {
                throw ProjectSeoExportException::projectRelation();
            }
            $grouped[$intent->keyword_id][] = $intent;
        }

        $result = [];
        foreach ($grouped as $keywordId => $assignments) {
            if (count($assignments) > 1) {
                throw ProjectSeoExportException::currentIntent();
            }
            $result[$keywordId] = $assignments[0];
        }

        return $result;
    }

    /**
     * @param  list<int>  $keywordIds
     * @param  array<int,KeywordSource>  $sourceById
     * @return array<int,array<string,KeywordMetric>>
     */
    private function latestMetrics(array $keywordIds, array $sourceById): array
    {
        if ($keywordIds === []) {
            return [];
        }

        $metrics = KeywordMetric::query()
            ->whereIn('keyword_id', $keywordIds)
            ->whereIn('metric', ['volume', 'difficulty'])
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('keyword_metrics as newer')
                    ->whereColumn('newer.keyword_id', 'keyword_metrics.keyword_id')
                    ->whereColumn('newer.metric', 'keyword_metrics.metric')
                    ->where(function (Builder $newer): void {
                        $newer->whereColumn('newer.observed_at', '>', 'keyword_metrics.observed_at')
                            ->orWhere(function (Builder $tie): void {
                                $tie->whereColumn('newer.observed_at', '=', 'keyword_metrics.observed_at')
                                    ->whereColumn('newer.id', '>', 'keyword_metrics.id');
                            });
                    });
            })
            ->orderBy('keyword_id')
            ->orderBy('metric')
            ->get();

        $allowedKeywordIds = array_fill_keys($keywordIds, true);
        $result = [];
        foreach ($metrics as $metric) {
            $source = $sourceById[$metric->keyword_source_id] ?? null;
            if (! isset($allowedKeywordIds[$metric->keyword_id])
                || ! $source instanceof KeywordSource || $source->keyword_id !== $metric->keyword_id) {
                throw ProjectSeoExportException::projectRelation();
            }
            $result[$metric->keyword_id][$metric->metric] = $metric;
        }

        return $result;
    }

    /**
     * @param  list<int>  $clusterIds
     * @return array<int,ClusterTarget>
     */
    private function targets(Project $project, array $clusterIds): array
    {
        if ($clusterIds === []) {
            return [];
        }

        $result = [];
        foreach (ClusterTarget::query()->whereIn('keyword_cluster_id', $clusterIds)->orderBy('id')->get() as $target) {
            if ($target->project_id !== $project->id || isset($result[$target->keyword_cluster_id])) {
                throw ProjectSeoExportException::projectRelation();
            }
            $result[$target->keyword_cluster_id] = $target;
        }

        return $result;
    }

    /**
     * @param  array<int,ClusterTarget>  $targets
     * @return array<int,Page>
     */
    private function pages(Project $project, array $targets): array
    {
        $pageIds = array_values(array_unique(array_filter(
            array_map(fn (ClusterTarget $target): ?int => $target->page_id, $targets),
            fn (?int $id): bool => $id !== null,
        )));
        if ($pageIds === []) {
            return [];
        }

        $pages = Page::query()->whereIn('id', $pageIds)->get()->keyBy('id');
        foreach ($pageIds as $pageId) {
            $page = $pages->get($pageId);
            if (! $page instanceof Page || $page->project_id !== $project->id) {
                throw ProjectSeoExportException::projectRelation();
            }
        }

        return $pages->all();
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int,array{count:int,mapping_conflict:bool}>
     */
    private function pendingReviews(Project $project, array $keywordIds): array
    {
        if ($keywordIds === []) {
            return [];
        }

        $result = [];
        $reviews = ReviewItem::query()
            ->whereIn('keyword_id', $keywordIds)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();
        foreach ($reviews as $review) {
            if ($review->project_id !== $project->id) {
                throw ProjectSeoExportException::projectRelation();
            }
            $entry = $result[$review->keyword_id] ?? ['count' => 0, 'mapping_conflict' => false];
            $entry['count']++;
            $entry['mapping_conflict'] = $entry['mapping_conflict'] || $review->type === ReviewItemType::MappingConflict;
            $result[$review->keyword_id] = $entry;
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<int,KeywordSource>  $sourceById
     */
    private function fillMetric(array &$row, string $prefix, ?KeywordMetric $metric, array $sourceById): void
    {
        if (! $metric instanceof KeywordMetric) {
            return;
        }

        $source = $sourceById[$metric->keyword_source_id] ?? null;
        if (! $source instanceof KeywordSource || $source->keyword_id !== $metric->keyword_id) {
            throw ProjectSeoExportException::projectRelation();
        }

        $row[$prefix.'_value'] = $metric->value;
        $row[$prefix.'_metric_id'] = $metric->id;
        $row[$prefix.'_source_id'] = $metric->keyword_source_id;
        $row[$prefix.'_source'] = $source->source;
        $row[$prefix.'_observed_at_utc'] = $this->iso($metric->observed_at);
    }

    /**
     * @param  array<int,KeywordSource>  $sourceById
     * @return array<string,mixed>|null
     */
    private function metricEvidence(?KeywordMetric $metric, array $sourceById): ?array
    {
        if (! $metric instanceof KeywordMetric) {
            return null;
        }

        $source = $sourceById[$metric->keyword_source_id] ?? null;
        if (! $source instanceof KeywordSource || $source->keyword_id !== $metric->keyword_id) {
            throw ProjectSeoExportException::projectRelation();
        }

        return [
            'metric_id' => $metric->id,
            'source_id' => $metric->keyword_source_id,
            'source' => $source->source,
            'observed_at' => $this->iso($metric->observed_at),
        ];
    }

    private function iso(mixed $value): ?string
    {
        return $value instanceof CarbonInterface
            ? $value->copy()->utc()->toIso8601String()
            : null;
    }
}
