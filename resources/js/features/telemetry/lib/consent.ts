export type ConsentLevel = 'essential' | 'all';

export const CONSENT_COOKIE = 'cookie_consent';

export const CONSENT_DAYS = 365;

export const CONSENT_EVENT = 'apartar:consent';

export const CONSENT_REOPEN_EVENT = 'apartar:consent-reopen';

export function readConsent(): ConsentLevel | null {
    try {
        const match = document.cookie.match(new RegExp(`(?:^|; )${CONSENT_COOKIE}=([^;]*)`));
        const value = match?.[1];

        return value === 'all' || value === 'essential' ? value : null;
    } catch {
        return null;
    }
}

export function writeConsent(level: ConsentLevel): void {
    try {
        document.cookie = `${CONSENT_COOKIE}=${level}; path=/; max-age=${CONSENT_DAYS * 86400}; SameSite=Lax`;
    } catch {}

    window.dispatchEvent(new CustomEvent(CONSENT_EVENT, { detail: level }));
}

export function hasAnalyticsConsent(): boolean {
    return readConsent() === 'all';
}
