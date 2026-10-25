import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it } from 'vitest';

import { reopenConsent } from '../hooks/useConsent';
import { resetTelemetryForTests, track } from '../hooks/useTelemetry';
import { STORAGE_KEY } from '../lib/buffer';
import { hasAnalyticsConsent } from '../lib/consent';
import { CookieConsent, CookiePreferencesLink } from './CookieConsent';

function clearConsent(): void {
    document.cookie = 'cookie_consent=; max-age=0; path=/';
}

function buffered(): string[] {
    return (JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? '[]') as Array<{ name: string }>).map((event) => event.name);
}

beforeEach(() => {
    clearConsent();
    sessionStorage.clear();
    resetTelemetryForTests();
});

afterEach(clearConsent);

it('pregunta la primera vez con dos botones equivalentes', () => {
    render(<CookieConsent />);

    const essential = screen.getByRole('button', { name: 'Solo lo esencial' });
    const all = screen.getByRole('button', { name: 'Aceptar todo' });

    expect(essential.className).toBe(all.className);
});

it('ignorar el banner es «solo lo esencial»: los esenciales sí, lo demás no', () => {
    render(<CookieConsent />);

    track('catalog_viewed', { catalogSlug: 'tienda' });
    track('layaway_created', { catalogSlug: 'tienda' });

    expect(buffered()).toEqual(['layaway_created']);
});

it('aceptar todo habilita la medición y cierra el banner', () => {
    render(<CookieConsent />);

    fireEvent.click(screen.getByRole('button', { name: 'Aceptar todo' }));

    expect(hasAnalyticsConsent()).toBe(true);
    expect(document.cookie).toContain('cookie_consent=all');
    expect(screen.queryByRole('button', { name: 'Aceptar todo' })).toBeNull();

    track('catalog_viewed', { catalogSlug: 'tienda' });
    expect(buffered()).toEqual(['catalog_viewed']);
});

it('se puede cambiar desde el pie, y pasar a esencial no borra lo ya encolado', () => {
    document.cookie = 'cookie_consent=all; path=/';
    render(
        <>
            <CookieConsent />
            <CookiePreferencesLink />
        </>,
    );
    track('catalog_viewed', { catalogSlug: 'tienda' });
    expect(screen.queryByRole('button', { name: 'Solo lo esencial' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Preferencias de cookies' }));
    fireEvent.click(screen.getByRole('button', { name: 'Solo lo esencial' }));

    expect(hasAnalyticsConsent()).toBe(false);
    track('product_viewed', { catalogSlug: 'tienda' });
    expect(buffered()).toEqual(['catalog_viewed']);
});

it('reabrir desde fuera del componente también lo enseña', () => {
    document.cookie = 'cookie_consent=essential; path=/';
    render(<CookieConsent />);

    act(() => reopenConsent());

    expect(screen.getByRole('button', { name: 'Aceptar todo' })).toBeTruthy();
});
