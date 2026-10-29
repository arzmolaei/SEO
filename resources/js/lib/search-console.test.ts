import { describe, expect, it } from 'vitest';
import { formatPercent, perDayChange, taskTitle } from './search-console';

describe('search console helpers', () => {
    it('compares clicks per day across periods of different length', () => {
        const previous = {
            clicks: 300,
            impressions: 0,
            ctr: 0,
            position: null,
            days: 30,
        };
        const current = {
            clicks: 70,
            impressions: 10,
            ctr: 0,
            position: null,
            days: 14,
        };

        expect(perDayChange(current, previous, 'clicks')).toBeCloseTo(-0.5);
        expect(perDayChange(current, previous, 'impressions')).toBeNull();
        expect(perDayChange(current, null, 'clicks')).toBeNull();
    });

    it('formats Persian percentages and builds task titles', () => {
        expect(formatPercent(0.125, 'fa')).toContain('۱۲٫۵');
        expect(taskTitle('low_ctr', 'خرید کفش', 'fa')).toContain('«خرید کفش»');
        expect(taskTitle('declining', 'x'.repeat(400), 'en')).toHaveLength(255);
    });
});
