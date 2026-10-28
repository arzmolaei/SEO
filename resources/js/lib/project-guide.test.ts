import { describe, expect, it } from 'vite-plus/test';
import { guideStepCopy } from './project-guide';

const keys = [
    'import',
    'intent',
    'clusters',
    'mapping',
    'conflicts',
    'ready',
    'export',
    'site_check',
];

describe('guideStepCopy', () => {
    it('explains every server step in Persian and English', () => {
        for (const key of keys)
            for (const locale of ['fa', 'en'] as const) {
                const step = guideStepCopy(key, locale);
                expect(step, `${locale}:${key}`).not.toBeNull();
                expect(step!.explain.length).toBeGreaterThan(20);
            }
    });

    it('uses Persian digits for Persian counts', () => {
        expect(guideStepCopy('mapping', 'fa')!.detail(3, 12)).toBe(
            '۳ خوشه از ۱۲ هنوز صفحه ندارد',
        );
    });

    it('returns null for unknown steps', () => {
        expect(guideStepCopy('nope', 'fa')).toBeNull();
    });
});
