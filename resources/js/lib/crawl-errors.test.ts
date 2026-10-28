import { describe, expect, it } from 'vite-plus/test';
import { crawlErrorLabel } from './crawl-errors';

describe('crawlErrorLabel', () => {
    it('explains actionable safety refusals in both languages', () => {
        expect(crawlErrorLabel('proxy_not_supported', 'fa')).toContain(
            'no_proxy',
        );
        expect(crawlErrorLabel('private_network', 'en')).toContain(
            'CRAWLER_ALLOW_PRIVATE_NETWORKS',
        );
    });

    it('describes retried HTTP statuses', () => {
        expect(crawlErrorLabel('http_503', 'en')).toContain('503');
    });

    it('returns null for unknown codes so the raw code is shown alone', () => {
        expect(crawlErrorLabel('something_new', 'fa')).toBeNull();
    });
});
