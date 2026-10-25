import { track } from '@/features/telemetry/hooks/useTelemetry';
import { type CurrencyPageProps } from '@/types/exchange';
import { type CurrencyDisplay } from '@/types/money';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

const DISPLAYS: readonly CurrencyDisplay[] = ['usd_primary', 'ves_primary'];

export const CURRENCY_COOKIE = 'currency_display';

export const CURRENCY_COOKIE_DAYS = 365;

export const CURRENCY_STORAGE_KEY = CURRENCY_COOKIE;

function isCurrencyDisplay(value: unknown): value is CurrencyDisplay {
    return typeof value === 'string' && DISPLAYS.includes(value as CurrencyDisplay);
}

export const CURRENCY_EVENT = 'apartar:currency-display';

function rememberedCookie(): CurrencyDisplay | null {
    try {
        const match = document.cookie.match(new RegExp(`(?:^|; )${CURRENCY_COOKIE}=([^;]*)`));
        const value = match?.[1];

        return isCurrencyDisplay(value) ? value : null;
    } catch {
        return null;
    }
}

function remember(display: CurrencyDisplay): void {
    try {
        window.localStorage.setItem(CURRENCY_STORAGE_KEY, display);
    } catch {}

    try {
        const maxAge = CURRENCY_COOKIE_DAYS * 24 * 60 * 60;

        document.cookie = `${CURRENCY_COOKIE}=${display}; path=/; max-age=${maxAge}; SameSite=Lax`;
    } catch {}
}

export interface CurrencyDisplayState {
    display: CurrencyDisplay;
    isVesPrimary: boolean;
    toggle: () => void;
    setDisplay: (next: CurrencyDisplay) => void;
}

export function useCurrencyDisplay(): CurrencyDisplayState {
    const { currency, catalog } = usePage<{ currency: CurrencyPageProps; catalog?: { slug?: string } }>().props;

    const [display, setLocalDisplay] = useState<CurrencyDisplay>(
        () => rememberedCookie() ?? (isCurrencyDisplay(currency?.display) ? currency.display : 'usd_primary'),
    );

    useEffect(() => {
        const follow = (event: Event) => {
            const next: unknown = (event as CustomEvent<unknown>).detail;

            if (isCurrencyDisplay(next)) {
                setLocalDisplay(next);
            }
        };

        window.addEventListener(CURRENCY_EVENT, follow);

        return () => window.removeEventListener(CURRENCY_EVENT, follow);
    }, []);

    const setDisplay = useCallback((next: CurrencyDisplay) => {
        setLocalDisplay(next);
        remember(next);
        window.dispatchEvent(new CustomEvent(CURRENCY_EVENT, { detail: next }));
    }, []);

    const toggle = useCallback(() => {
        const next = display === 'usd_primary' ? 'ves_primary' : 'usd_primary';

        setDisplay(next);
        track('currency_toggled', { ...(catalog?.slug === undefined ? {} : { catalogSlug: catalog.slug }), payload: { to: next } });
    }, [display, setDisplay, catalog?.slug]);

    return {
        display,
        isVesPrimary: display === 'ves_primary',
        toggle,
        setDisplay,
    };
}
