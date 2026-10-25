export const VISITOR_COOKIE = 'apartar_vid';

export const SESSION_IDLE_MS = 30 * 60 * 1000;

const SESSION_KEY = 'apartar_tsid';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

let fallbackVisitor: string | null = null;

let memorySession: { id: string; seenAt: number } | null = null;

function randomId(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/x/g, () => Math.floor(Math.random() * 16).toString(16));
}

export function visitorId(): string {
    try {
        const match = document.cookie.match(new RegExp(`(?:^|; )${VISITOR_COOKIE}=([^;]*)`));
        const value = match?.[1] === undefined ? '' : decodeURIComponent(match[1]);

        if (UUID.test(value)) {
            return value.toLowerCase();
        }
    } catch {}

    fallbackVisitor ??= randomId();

    return fallbackVisitor;
}

function readSession(): { id: string; seenAt: number } | null {
    try {
        const raw = sessionStorage.getItem(SESSION_KEY);

        return raw === null ? memorySession : (JSON.parse(raw) as { id: string; seenAt: number });
    } catch {
        return memorySession;
    }
}

function writeSession(session: { id: string; seenAt: number }): void {
    memorySession = session;

    try {
        sessionStorage.setItem(SESSION_KEY, JSON.stringify(session));
    } catch {}
}

export function sessionId(now: number = Date.now()): string {
    const current = readSession();
    const id = current !== null && typeof current.id === 'string' && now - current.seenAt < SESSION_IDLE_MS ? current.id : randomId();

    writeSession({ id, seenAt: now });

    return id;
}

export function resetVisitorForTests(): void {
    fallbackVisitor = null;
    memorySession = null;
}
