import { describe, expect, it } from 'vite-plus/test';
import {
    importBatchNeedsPolling,
    operationsNeedPolling,
    updateNeedsPolling,
} from './status-polling';

describe('importBatchNeedsPolling', () => {
    it('polls while the worker owns the batch', () => {
        for (const status of ['queued', 'processing'])
            expect(
                importBatchNeedsPolling({ status, commit_requested_at: null }),
            ).toBe(true);
    });

    it('polls a staged batch only after commit was requested', () => {
        expect(
            importBatchNeedsPolling({
                status: 'staged',
                commit_requested_at: null,
            }),
        ).toBe(false);
        expect(
            importBatchNeedsPolling({
                status: 'staged',
                commit_requested_at: '2026-10-28T10:00:00Z',
            }),
        ).toBe(true);
    });

    it('stops for operator-owned and settled states', () => {
        expect(importBatchNeedsPolling(null)).toBe(false);
        for (const status of [
            'awaiting_mapping',
            'committed',
            'discarded',
            'failed',
        ])
            expect(
                importBatchNeedsPolling({
                    status,
                    commit_requested_at: '2026-10-28T10:00:00Z',
                }),
            ).toBe(false);
    });
});

describe('operationsNeedPolling', () => {
    const operations = (scheduler: string, queue: string) => ({
        scheduler: { state: scheduler },
        queue: { state: queue },
    });

    it('polls until both scheduler and queue evidence are observed', () => {
        expect(operationsNeedPolling(operations('warning', 'verified'))).toBe(
            true,
        );
        expect(operationsNeedPolling(operations('verified', 'warning'))).toBe(
            true,
        );
        expect(operationsNeedPolling(operations('verified', 'verified'))).toBe(
            false,
        );
    });

    it('does not poll a blocking failure the operator must fix', () => {
        expect(operationsNeedPolling(operations('fail', 'fail'))).toBe(false);
    });
});

describe('updateNeedsPolling', () => {
    it('polls phases that advance without this tab', () => {
        for (const status of ['quiescing', 'migrating', 'switch_pending'])
            expect(updateNeedsPolling(status, false)).toBe(true);
    });

    it('never polls while this tab has its own request in flight', () => {
        expect(updateNeedsPolling('migrating', true)).toBe(false);
    });

    it('does not poll phases waiting for an operator decision', () => {
        for (const status of [
            'ready',
            'verified',
            'target_active',
            'recovery_required',
            'complete',
        ])
            expect(updateNeedsPolling(status, false)).toBe(false);
    });
});
