import { useEffect } from 'react';

import { track } from './useTelemetry';

export const HEARTBEAT_MS = 15_000;
export const MIN_SECONDS = 5;
export const MAX_SECONDS = 3600;

interface DwellOptions {
    readonly path: string;
    readonly catalogSlug?: string;
    readonly subjectSlug?: string;
}

export function useDwellTime({ path, catalogSlug, subjectSlug }: DwellOptions): void {
    useEffect(() => {
        let total = 0;
        let since: number | null = document.visibilityState === 'hidden' ? null : Date.now();
        let emitted = false;

        const settle = (): void => {
            if (since !== null) {
                total += Date.now() - since;
            }

            since = document.visibilityState === 'hidden' ? null : Date.now();
        };

        const emit = (): void => {
            settle();

            if (emitted) {
                return;
            }

            emitted = true;
            const seconds = Math.min(MAX_SECONDS, Math.floor(total / 1000));

            if (seconds >= MIN_SECONDS) {
                track('dwell_time_recorded', { catalogSlug, subjectSlug, payload: { path, seconds } });
            }
        };

        const heartbeat = setInterval(settle, HEARTBEAT_MS);

        document.addEventListener('visibilitychange', settle);
        window.addEventListener('pagehide', emit);

        return () => {
            clearInterval(heartbeat);
            document.removeEventListener('visibilitychange', settle);
            window.removeEventListener('pagehide', emit);
            emit();
        };
    }, [path, catalogSlug, subjectSlug]);
}
