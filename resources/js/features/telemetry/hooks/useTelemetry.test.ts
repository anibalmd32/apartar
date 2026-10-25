import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { STORAGE_KEY } from '../lib/buffer';
import { resetTelemetryForTests, track } from './useTelemetry';

function buffered(): Array<{ name: string; catalogSlug?: string }> {
    return JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? '[]') as Array<{ name: string; catalogSlug?: string }>;
}

beforeEach(() => {
    sessionStorage.clear();
    resetTelemetryForTests();
    document.cookie = 'cookie_consent=; max-age=0; path=/';
});

afterEach(() => {
    document.cookie = 'cookie_consent=; max-age=0; path=/';
});

it('sin consentimiento solo encola los esenciales', () => {
    track('catalog_viewed', { catalogSlug: 'tienda' });
    track('catalog_subscribed', { catalogSlug: 'tienda' });

    expect(buffered().map((event) => event.name)).toEqual(['catalog_subscribed']);
});

it('con consentimiento encola todo, con su slug', () => {
    document.cookie = 'cookie_consent=all; path=/';

    track('catalog_viewed', { catalogSlug: 'tienda' });

    expect(buffered()).toEqual([expect.objectContaining({ name: 'catalog_viewed', catalogSlug: 'tienda' })]);
});

it('un fallo dentro de track no propaga', () => {
    document.cookie = 'cookie_consent=all; path=/';
    vi.spyOn(Date.prototype, 'toISOString').mockImplementation(() => {
        throw new Error('roto');
    });

    expect(() => track('catalog_viewed', { catalogSlug: 'tienda' })).not.toThrow();

    vi.restoreAllMocks();
});
