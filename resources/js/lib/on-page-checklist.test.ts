import { describe, expect, it } from 'vite-plus/test';
import { checklistCopy } from './on-page-checklist';

// Must match App\Domain\Pages\OnPageChecklist item keys.
const keys = [
    'target',
    'single_target',
    'title_present',
    'title_length',
    'title_keyword',
    'title_unique',
    'meta_present',
    'meta_length',
    'meta_keyword',
    'h1_present',
    'h1_keyword',
    'url_present',
    'url_short',
];

describe('checklistCopy', () => {
    it('gives every checklist item a label and a fix in both languages', () => {
        for (const key of keys)
            for (const locale of ['fa', 'en'] as const) {
                const item = checklistCopy(key, locale);
                expect(item, `${locale}:${key}`).not.toBeNull();
                expect(item!.fix.length).toBeGreaterThan(20);
            }
    });
});
