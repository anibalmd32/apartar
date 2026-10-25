import { useCallback } from 'react';

import { ESSENTIAL_EVENTS, type TelemetryEventName, type TrackOptions } from '../events';
import { TelemetryBuffer } from '../lib/buffer';
import { hasAnalyticsConsent } from '../lib/consent';

export const TELEMETRY_ENDPOINT = '/api/telemetry';

let shared: TelemetryBuffer | null = null;

function buffer(): TelemetryBuffer {
    if (shared === null) {
        shared = new TelemetryBuffer({ endpoint: TELEMETRY_ENDPOINT });
        shared.start();
    }

    return shared;
}

export function track(name: TelemetryEventName, options: TrackOptions = {}): void {
    try {
        if (!ESSENTIAL_EVENTS.has(name) && !hasAnalyticsConsent()) {
            return;
        }

        buffer().push({
            name,
            occurredAt: new Date().toISOString(),
            ...(options.catalogSlug === undefined ? {} : { catalogSlug: options.catalogSlug }),
            ...(options.subjectSlug === undefined ? {} : { subjectSlug: options.subjectSlug }),
            ...(options.payload === undefined ? {} : { payload: options.payload }),
        });
    } catch {}
}

export function useTelemetry(): { track: typeof track } {
    const stable = useCallback<typeof track>((name, options) => track(name, options), []);

    return { track: stable };
}

export function resetTelemetryForTests(): void {
    shared = null;
}
