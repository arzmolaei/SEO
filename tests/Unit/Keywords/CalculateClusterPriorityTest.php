<?php

namespace Tests\Unit\Keywords;

use App\Domain\Keywords\ClusterPriorityScore;
use PHPUnit\Framework\TestCase;

class CalculateClusterPriorityTest extends TestCase
{
    public function test_score_is_deterministic_and_explainable_from_known_signals(): void
    {
        $first = ClusterPriorityScore::calculate(
            volume: 1000,
            difficulty: 40,
            businessRelevance: 4,
            hasMapping: true,
            reviewComplete: true,
        );
        $second = ClusterPriorityScore::calculate(
            volume: 1000,
            difficulty: 40,
            businessRelevance: 4,
            hasMapping: true,
            reviewComplete: true,
        );

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame(74, $first->score);
        $this->assertSame(100, $first->dataCompleteness);
        $this->assertSame(1000.0, $first->components['search_demand']['value']);
        $this->assertSame(18, $first->components['search_demand']['points']);
        $this->assertSame(40.0, $first->components['difficulty']['value']);
        $this->assertSame(12, $first->components['difficulty']['points']);
        $this->assertSame(4, $first->components['business_relevance']['value']);
        $this->assertSame(24, $first->components['business_relevance']['points']);
        $this->assertSame(10, $first->components['mapping']['points']);
        $this->assertSame(10, $first->components['review']['points']);
    }

    public function test_missing_metrics_are_explicit_and_never_replaced_with_invented_values(): void
    {
        $score = ClusterPriorityScore::calculate(
            volume: null,
            difficulty: null,
            businessRelevance: null,
            hasMapping: true,
            reviewComplete: true,
        );

        $this->assertSame(20, $score->score);
        $this->assertSame(0, $score->dataCompleteness);
        $this->assertNull($score->components['search_demand']['value']);
        $this->assertNull($score->components['difficulty']['value']);
        $this->assertNull($score->components['business_relevance']['value']);
        $this->assertSame(0, $score->components['search_demand']['points']);
        $this->assertSame(0, $score->components['difficulty']['points']);
        $this->assertSame(0, $score->components['business_relevance']['points']);
    }

    public function test_out_of_range_inputs_are_clamped_without_creating_extra_priority(): void
    {
        $score = ClusterPriorityScore::calculate(
            volume: 1000000,
            difficulty: 150,
            businessRelevance: 5,
            hasMapping: false,
            reviewComplete: false,
        );

        $this->assertSame(60, $score->score);
        $this->assertSame(30, $score->components['search_demand']['points']);
        $this->assertSame(0, $score->components['difficulty']['points']);
        $this->assertSame(30, $score->components['business_relevance']['points']);
    }
}
