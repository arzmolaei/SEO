<?php

namespace App\Services\Keywords;

use App\Domain\Keywords\ClusterPriorityScore;
use App\Domain\Keywords\ClusterReviewState;
use App\Models\ClusterTarget;
use App\Models\KeywordCluster;
use App\Models\KeywordMetric;
use App\Models\Project;
use App\Models\ReviewItem;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Auth\Access\AuthorizationException;

class CalculateClusterPriority
{
    public function __construct(private ProjectPolicy $policy) {}

    public function handle(User $actor, Project $project, KeywordCluster $cluster): ClusterPriorityScore
    {
        if (! $this->policy->view($actor, $project) || $cluster->project_id !== $project->id) {
            throw new AuthorizationException;
        }

        $cluster = KeywordCluster::query()
            ->where('project_id', $project->id)
            ->findOrFail($cluster->id);
        $volume = $this->latestMetric($cluster->primary_keyword_id, 'volume');
        $difficulty = $this->latestMetric($cluster->primary_keyword_id, 'difficulty');
        $hasMapping = ClusterTarget::query()
            ->where('project_id', $project->id)
            ->where('keyword_cluster_id', $cluster->id)
            ->exists();
        $hasPendingReview = ReviewItem::query()
            ->where('project_id', $project->id)
            ->where('keyword_id', $cluster->primary_keyword_id)
            ->where('status', 'pending')
            ->exists();

        return ClusterPriorityScore::calculate(
            volume: $this->metricValue($volume),
            difficulty: $this->metricValue($difficulty),
            businessRelevance: $cluster->business_relevance,
            hasMapping: $hasMapping,
            reviewComplete: $cluster->review_state === ClusterReviewState::Reviewed && ! $hasPendingReview,
            volumeEvidence: $this->metricEvidence($volume),
            difficultyEvidence: $this->metricEvidence($difficulty),
        );
    }

    private function latestMetric(int $keywordId, string $metric): ?KeywordMetric
    {
        return KeywordMetric::query()
            ->with('source:id,source')
            ->where('keyword_id', $keywordId)
            ->where('metric', $metric)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
    }

    private function metricValue(?KeywordMetric $metric): ?float
    {
        return $metric?->value === null ? null : (float) $metric->value;
    }

    /** @return array<string,mixed>|null */
    private function metricEvidence(?KeywordMetric $metric): ?array
    {
        if (! $metric instanceof KeywordMetric) {
            return null;
        }

        return [
            'metric_id' => $metric->id,
            'source_id' => $metric->keyword_source_id,
            'source' => $metric->source?->source,
            'observed_at' => $metric->observed_at->toIso8601String(),
        ];
    }
}
