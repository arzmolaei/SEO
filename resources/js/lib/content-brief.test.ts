import { describe, expect, it } from 'vitest';
import { briefAsText, intentGuidance } from './content-brief';

describe('content brief helpers', () => {
    it('falls back to the unknown-intent guidance', () => {
        expect(intentGuidance('something-else', 'fa')).toBe(
            intentGuidance('unknown', 'fa'),
        );
        expect(intentGuidance('commercial', 'en')).toContain('comparing');
    });

    it('builds a pasteable Persian brief', () => {
        const text = briefAsText(
            {
                title: 'راهنمای خرید کفش',
                primary_keyword: 'خرید کفش',
                secondary_keywords: ['قیمت کفش', 'کفش ارزان'],
                headings: ['قیمت کفش'],
                notes: null,
                intent: 'informational',
            },
            'fa',
        );

        expect(text.split('\n')[0]).toBe('راهنمای خرید کفش');
        expect(text).toContain('کلمات مرتبط: قیمت کفش، کفش ارزان');
        expect(text).toContain('- قیمت کفش');
        expect(text).not.toContain('یادداشت');
    });
});
