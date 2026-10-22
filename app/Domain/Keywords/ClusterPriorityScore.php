<?php

namespace App\Domain\Keywords;

final readonly class ClusterPriorityScore
{
    /**
     * @param  array<string, array{value:mixed, points:int, weight:int, available:bool, evidence?:array<string,mixed>|null}>  $components
     */
    private function __construct(
        public int $score,
        public int $dataCompleteness,
        public array $components,
    ) {}

    /**
     * @param  array<string,mixed>|null  $volumeEvidence
     * @param  array<string,mixed>|null  $difficultyEvidence
     */
    public static function calculate(
        ?float $volume,
        ?float $difficulty,
        ?int $businessRelevance,
        bool $hasMapping,
        bool $reviewComplete,
        ?array $volumeEvidence = null,
        ?array $difficultyEvidence = null,
    ): self {
        $demandPoints = 0;
        if ($volume !== null) {
            $normalizedDemand = min(100.0, max(0.0, (log10(1 + max(0.0, $volume)) / 5) * 100));
            $demandPoints = (int) round($normalizedDemand * 0.30);
        }

        $difficultyPoints = 0;
        if ($difficulty !== null) {
            $normalizedDifficulty = min(100.0, max(0.0, $difficulty));
            $difficultyPoints = (int) round((100 - $normalizedDifficulty) * 0.20);
        }

        $businessPoints = 0;
        if ($businessRelevance !== null) {
            $normalizedBusiness = min(5, max(1, $businessRelevance)) * 20;
            $businessPoints = (int) round($normalizedBusiness * 0.30);
        }

        $mappingPoints = $hasMapping ? 10 : 0;
        $reviewPoints = $reviewComplete ? 10 : 0;
        $availableStrategicSignals = (int) ($volume !== null) + (int) ($difficulty !== null) + (int) ($businessRelevance !== null);

        return new self(
            score: $demandPoints + $difficultyPoints + $businessPoints + $mappingPoints + $reviewPoints,
            dataCompleteness: (int) round(($availableStrategicSignals / 3) * 100),
            components: [
                'search_demand' => [
                    'value' => $volume,
                    'points' => $demandPoints,
                    'weight' => 30,
                    'available' => $volume !== null,
                    'evidence' => $volumeEvidence,
                ],
                'difficulty' => [
                    'value' => $difficulty,
                    'points' => $difficultyPoints,
                    'weight' => 20,
                    'available' => $difficulty !== null,
                    'evidence' => $difficultyEvidence,
                ],
                'business_relevance' => [
                    'value' => $businessRelevance,
                    'points' => $businessPoints,
                    'weight' => 30,
                    'available' => $businessRelevance !== null,
                ],
                'mapping' => [
                    'value' => $hasMapping,
                    'points' => $mappingPoints,
                    'weight' => 10,
                    'available' => true,
                ],
                'review' => [
                    'value' => $reviewComplete,
                    'points' => $reviewPoints,
                    'weight' => 10,
                    'available' => true,
                ],
            ],
        );
    }

    /** @return array{score:int,data_completeness:int,components:array<string,array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'data_completeness' => $this->dataCompleteness,
            'components' => $this->components,
        ];
    }
}
