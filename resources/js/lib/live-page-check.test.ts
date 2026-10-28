import { describe, expect, it } from 'vitest';
import { liveProblemCopy, liveProblemDetail } from './live-page-check';

const keys = [
    'fetch_failed',
    'http_error',
    'redirected',
    'not_html',
    'noindex',
    'canonical_elsewhere',
    'title_missing',
    'title_differs',
    'meta_missing',
    'meta_differs',
    'h1_missing',
    'h1_multiple',
    'h1_differs',
    'thin_content',
];

describe('live page check copy', () => {
    it('explains every problem the server reports in both languages', () => {
        for (const key of keys) {
            expect(liveProblemCopy(key, 'fa')?.fix).toBeTruthy();
            expect(liveProblemCopy(key, 'en')?.fix).toBeTruthy();
        }
    });

    it('shows live values readably', () => {
        expect(liveProblemDetail({ key: 'http_error', value: 404 }, 'fa')).toBe(
            '۴۰۴',
        );
        expect(
            liveProblemDetail({ key: 'thin_content', value: 120 }, 'en'),
        ).toBe('120 words');
        expect(
            liveProblemDetail({ key: 'noindex', value: null }, 'fa'),
        ).toBeNull();
        expect(
            liveProblemDetail(
                { key: 'fetch_failed', value: 'blocked_by_robots' },
                'en',
            ),
        ).toContain('robots.txt');
    });
});
