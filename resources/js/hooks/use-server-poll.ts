import { usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { STATUS_POLL_INTERVAL_MS } from '@/lib/status-polling';

export type ServerPollState = {
    lastCheckedAt: Date | null;
    failed: boolean;
};

/**
 * Re-reads only the named Inertia props while `active` is true. Requests
 * never overlap, pause in background tabs and stop once the server reports
 * a settled state. An Inertia asset-version change still forces a full page
 * load, so code from a newly switched release always boots.
 */
export function useServerPoll(
    only: string[],
    active: boolean,
    intervalMs: number = STATUS_POLL_INTERVAL_MS,
): ServerPollState {
    const [state, setState] = useState<ServerPollState>({
        lastCheckedAt: null,
        failed: false,
    });
    const fail = () => {
        setState((current) => ({ ...current, failed: true }));

        return false;
    };
    const { start, stop } = usePoll(
        intervalMs,
        () => ({
            only,
            onSuccess: () =>
                setState({ lastCheckedAt: new Date(), failed: false }),
            // Keep polling quietly through transient 503/network failures
            // instead of opening Inertia's error dialog.
            onHttpException: fail,
            onNetworkError: fail,
        }),
        { autoStart: false, mode: 'rest', background: 'pause' },
    );

    useEffect(() => {
        if (active) start();
        else stop();

        return stop;
    }, [active, start, stop]);

    return state;
}
