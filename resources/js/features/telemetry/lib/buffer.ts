import type { TelemetryEvent } from '../events';
import { sessionId, visitorId } from './visitor';

export const FLUSH_AT = 10;
export const FLUSH_EVERY_MS = 15_000;
export const MAX_BATCH = 50;
export const MAX_BUFFERED = 500;
export const RATE_LIMIT_PAUSE_MS = 60_000;
export const STORAGE_KEY = 'apartar_telemetry';

export type Transport = (url: string, body: string, keepalive: boolean) => Promise<number>;

export type Beacon = (url: string, body: string) => boolean;

export interface BufferOptions {
    readonly endpoint: string;
    readonly transport?: Transport;
    readonly beacon?: Beacon | null;
    readonly now?: () => number;
}

const fetchTransport: Transport = async (url, body, keepalive) => {
    const response = await fetch(url, {
        method: 'POST',
        body,
        keepalive,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    });

    return response.status;
};

function defaultBeacon(): Beacon | null {
    if (typeof navigator === 'undefined' || typeof navigator.sendBeacon !== 'function') {
        return null;
    }

    return (url, body) => navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }));
}

export class TelemetryBuffer {
    private events: TelemetryEvent[];
    private pausedUntil = 0;
    private readonly transport: Transport;
    private readonly beacon: Beacon | null;
    private readonly now: () => number;

    constructor(private readonly options: BufferOptions) {
        this.transport = options.transport ?? fetchTransport;
        this.beacon = options.beacon === undefined ? defaultBeacon() : options.beacon;
        this.now = options.now ?? Date.now;
        this.events = this.restore();
    }

    get size(): number {
        return this.events.length;
    }

    push(event: TelemetryEvent): void {
        this.events = [...this.events, event].slice(-MAX_BUFFERED);
        this.persist();

        if (this.events.length >= FLUSH_AT) {
            void this.flush();
        }
    }

    start(): () => void {
        const timer = setInterval(() => void this.flush(), FLUSH_EVERY_MS);
        const leave = (): void => this.flushOnLeave();
        const hidden = (): void => {
            if (document.visibilityState === 'hidden') {
                this.flushOnLeave();
            }
        };

        window.addEventListener('pagehide', leave);
        document.addEventListener('visibilitychange', hidden);

        return () => {
            clearInterval(timer);
            window.removeEventListener('pagehide', leave);
            document.removeEventListener('visibilitychange', hidden);
        };
    }

    async flush(): Promise<void> {
        for (const batch of this.take()) {
            const status = await this.post(batch);

            if (status === 429) {
                this.pausedUntil = this.now() + RATE_LIMIT_PAUSE_MS;
                this.events = [...batch, ...this.events].slice(-MAX_BUFFERED);
                this.persist();

                return;
            }
        }
    }

    flushOnLeave(): void {
        for (const batch of this.take()) {
            const body = this.body(batch);

            try {
                if (this.beacon === null || !this.beacon(this.options.endpoint, body)) {
                    void this.transport(this.options.endpoint, body, true).catch(() => undefined);
                }
            } catch {}
        }
    }

    private take(): TelemetryEvent[][] {
        if (this.events.length === 0 || this.now() < this.pausedUntil) {
            return [];
        }

        const taken = this.events;
        const batches: TelemetryEvent[][] = [];

        this.events = [];
        this.persist();

        for (let index = 0; index < taken.length; index += MAX_BATCH) {
            batches.push(taken.slice(index, index + MAX_BATCH));
        }

        return batches;
    }

    private async post(batch: TelemetryEvent[]): Promise<number> {
        const body = this.body(batch);

        for (let attempt = 0; attempt < 2; attempt++) {
            try {
                return await this.transport(this.options.endpoint, body, false);
            } catch {}
        }

        return 0;
    }

    private body(events: TelemetryEvent[]): string {
        return JSON.stringify({ visitorId: visitorId(), sessionId: sessionId(this.now()), events });
    }

    private persist(): void {
        try {
            if (this.events.length === 0) {
                sessionStorage.removeItem(STORAGE_KEY);
            } else {
                sessionStorage.setItem(STORAGE_KEY, JSON.stringify(this.events));
            }
        } catch {}
    }

    private restore(): TelemetryEvent[] {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY);
            const parsed: unknown = raw === null ? [] : JSON.parse(raw);

            return Array.isArray(parsed) ? (parsed as TelemetryEvent[]).slice(-MAX_BUFFERED) : [];
        } catch {
            return [];
        }
    }
}
