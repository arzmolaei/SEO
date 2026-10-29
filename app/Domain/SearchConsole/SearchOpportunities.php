<?php

namespace App\Domain\SearchConsole;

/**
 * Plain, explainable opportunities from imported Search Console data:
 *  - near the top: queries at average position 4–20 with real impressions,
 *    where a little work can reach the top three or page one;
 *  - low CTR: queries already on page one that get far fewer clicks than
 *    their position usually earns (title/description worth rewriting);
 *  - declining pages: pages whose clicks per day fell clearly compared
 *    with the previous import of pages.
 * Pure; thresholds are fixed beginner-friendly defaults.
 */
final class SearchOpportunities
{
    public const NEAR_TOP_MIN_POSITION = 4.0;

    public const NEAR_TOP_MAX_POSITION = 20.0;

    public const NEAR_TOP_MIN_IMPRESSIONS = 20;

    public const LOW_CTR_MAX_POSITION = 10.0;

    public const LOW_CTR_MIN_IMPRESSIONS = 100;

    public const DECLINE_MIN_PREVIOUS_CLICKS_PER_DAY = 1.0;

    public const DECLINE_MIN_DROP = 0.3;

    public const LIMIT = 50;

    /** Typical click-through rate for an average position (conservative). */
    public static function expectedCtr(float $position): float
    {
        return match (true) {
            $position < 1.5 => 0.25,
            $position < 2.5 => 0.15,
            $position < 3.5 => 0.10,
            $position < 5.5 => 0.06,
            default => 0.03,
        };
    }

    /**
     * @param  list<array{key: string, clicks: int, impressions: int, position: float}>  $queries
     * @return list<array{key: string, clicks: int, impressions: int, position: float}>
     */
    public static function nearTop(array $queries): array
    {
        $found = array_values(array_filter($queries, static fn (array $row): bool => $row['position'] >= self::NEAR_TOP_MIN_POSITION
            && $row['position'] <= self::NEAR_TOP_MAX_POSITION
            && $row['impressions'] >= self::NEAR_TOP_MIN_IMPRESSIONS));
        usort($found, static fn (array $a, array $b): int => [$b['impressions'], $a['position'], $a['key']] <=> [$a['impressions'], $b['position'], $b['key']]);

        return array_slice($found, 0, self::LIMIT);
    }

    /**
     * @param  list<array{key: string, clicks: int, impressions: int, position: float}>  $queries
     * @return list<array{key: string, clicks: int, impressions: int, position: float, ctr: float, expected_ctr: float, missed_clicks: int}>
     */
    public static function lowCtr(array $queries): array
    {
        $found = [];
        foreach ($queries as $row) {
            if ($row['position'] > self::LOW_CTR_MAX_POSITION || $row['impressions'] < self::LOW_CTR_MIN_IMPRESSIONS) {
                continue;
            }
            $ctr = $row['clicks'] / $row['impressions'];
            $expected = self::expectedCtr($row['position']);
            if ($ctr < $expected / 2) {
                $found[] = $row + [
                    'ctr' => round($ctr, 4),
                    'expected_ctr' => $expected,
                    'missed_clicks' => (int) round($row['impressions'] * $expected - $row['clicks']),
                ];
            }
        }
        usort($found, static fn (array $a, array $b): int => [$b['missed_clicks'], $a['key']] <=> [$a['missed_clicks'], $b['key']]);

        return array_slice($found, 0, self::LIMIT);
    }

    /**
     * Compares clicks per day, so imports covering different periods are fair.
     *
     * @param  list<array{key: string, clicks: int, impressions: int, position: float}>  $current
     * @param  array<string, int>  $previousClicks  page key => clicks in the previous import
     * @return list<array{key: string, clicks: int, previous_clicks: int, change: float}>
     */
    public static function declining(array $current, int $currentDays, array $previousClicks, int $previousDays): array
    {
        if ($currentDays < 1 || $previousDays < 1) {
            return [];
        }
        $byKey = [];
        foreach ($current as $row) {
            $byKey[$row['key']] = $row['clicks'];
        }
        $found = [];
        foreach ($previousClicks as $key => $before) {
            $beforePerDay = $before / $previousDays;
            if ($beforePerDay < self::DECLINE_MIN_PREVIOUS_CLICKS_PER_DAY) {
                continue;
            }
            $now = $byKey[$key] ?? 0;
            $change = ($now / $currentDays - $beforePerDay) / $beforePerDay;
            if ($change <= -self::DECLINE_MIN_DROP) {
                $found[] = ['key' => (string) $key, 'clicks' => $now, 'previous_clicks' => $before, 'change' => round($change, 4)];
            }
        }
        usort($found, static fn (array $a, array $b): int => [$a['change'], $a['key']] <=> [$b['change'], $b['key']]);

        return array_slice($found, 0, self::LIMIT);
    }
}
