import { describe, expect, it } from 'vitest';
import { activityLabel } from './report';

describe('report activity labels', () => {
    it('names recorded work in plain language and keeps unknown keys', () => {
        expect(activityLabel('task.created', 'fa')).toBe('ساخت کار');
        expect(activityLabel('page.on_page_updated', 'en')).toBe(
            'Page SEO updated',
        );
        expect(activityLabel('something.new', 'fa')).toBe('something.new');
    });
});
