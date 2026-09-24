import { RATE_REFRESH_MS } from '@/features/catalog/hooks/useLiveRate';
import { centsUsd } from '@/lib/money';
import CatalogShow from '@/pages/catalog/CatalogShow';
import { type CatalogFacets, type CatalogFilters, type CatalogView, type ProductCard } from '@/types/catalog-view';
import { type RateView } from '@/types/exchange';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * La vista pública del catálogo (`REQ-CAT-021`), con la bimoneda de `T-022`
 * montada y el refresco de cinco minutos de la tasa.
 */

const { usePage, reload, get } = vi.hoisted(() => ({ usePage: vi.fn(), reload: vi.fn(), get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    usePage,
    router: { reload, get },
    Head: () => null,
}));

const BCV: RateView = { rate: 40, source: 'bcv', sourceLabel: 'BCV', publishedAt: '2026-09-18T09:00:00-04:00', isStale: false };
const NO_RATE: RateView = { rate: null, source: 'bcv', sourceLabel: 'BCV', publishedAt: null, isStale: false };

function money(usd: string, ves: string | null) {
    return { cents: centsUsd(100), usd, ves };
}

function card(overrides: Partial<ProductCard> = {}): ProductCard {
    return {
        slug: 'taladro',
        name: 'Taladro percutor',
        price: money('$250.00', 'Bs. 10.000,00'),
        compareAtPrice: null,
        downPayment: money('$50.00', 'Bs. 2.000,00'),
        installmentAmount: money('$50.00', 'Bs. 2.000,00'),
        lastInstallmentAmount: null,
        installmentsCount: 4,
        frequencyDays: 15,
        image: null,
        categoryName: 'Herramientas',
        tags: [],
        inStock: true,
        catalogSlug: 'ofertas',
        ...overrides,
    };
}

function catalog(overrides: Partial<CatalogView> = {}): CatalogView {
    return {
        slug: 'ofertas',
        name: 'Ofertas de septiembre',
        description: null,
        cover: null,
        business: { name: 'Ferretería Oriente', slug: 'ferreteria', logoUrl: null, brandColor: null, brandingEnabled: false },
        shareUrl: 'http://localhost/c/ofertas',
        expiresAt: null,
        showsPlatformBadge: true,
        ...overrides,
    };
}

const NO_FILTERS: CatalogFilters = { q: null, category: null, tags: [], sort: null, ignored: false };

/** Las facetas de un catálogo con esos productos: sin productos, sin facetas. */
function facetsFor(products: ProductCard[]): CatalogFacets {
    return { categories: products.length > 0 ? [{ slug: 'herramientas', name: 'Herramientas', count: products.length }] : [], tags: [] };
}

function renderPage(rate: RateView, products: ProductCard[] = [card()], nextCursor: string | null = null, view = catalog()) {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate } } });

    return render(<CatalogShow catalog={view} products={{ data: products, nextCursor }} filters={NO_FILTERS} facets={facetsFor(products)} />);
}

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('pinta precio, inicial y cuota en las dos monedas, con el sello', () => {
    const { container } = renderPage(BCV);

    expect(container.textContent).toContain('$250.00 (Bs. 10.000,00)');
    expect(container.textContent).toContain('Inicial $50.00 (Bs. 2.000,00) y 4 cuotas de $50.00 (Bs. 2.000,00) cada 15 días');
    expect(container.textContent).toContain('Tasa BCV del 18/09 09:00');
});

/**
 * `REQ-FX-005` RB-4 · sin tasa, solo dólares: ni `Bs. 0,00` ni guiones, sin
 * sello y con el toggle deshabilitado.
 */
it('sin tasa enseña solo dólares y deshabilita el toggle', () => {
    const noVes = money('$250.00', null);
    const { container } = renderPage(NO_RATE, [card({ price: noVes, downPayment: money('$50.00', null), installmentAmount: money('$50.00', null) })]);

    expect(container.textContent).toContain('$250.00');
    expect(container.textContent).not.toContain('Bs.');
    expect(container.textContent).not.toContain('Tasa ');
    expect(screen.getByRole('button', { name: /cambiar la moneda/i })).toBeDisabled();
});

describe('el refresco de cinco minutos', () => {
    it('actualiza el sello y no los importes', async () => {
        vi.mocked(fetch).mockResolvedValue(
            new Response(JSON.stringify({ ...BCV, rate: 42, publishedAt: '2026-09-19T09:00:00-04:00' }), { status: 200 }),
        );

        const { container } = renderPage(BCV);

        expect(fetch).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(RATE_REFRESH_MS);

        expect(await screen.findByText(/Tasa BCV del 19\/09 09:00/)).toBeInTheDocument();
        expect(vi.mocked(fetch).mock.calls[0]?.[0]).toBe('/api/tasa?source=bcv');

        // Los importes son los que calculó el servidor, y la página lo dice.
        expect(container.textContent).toContain('$250.00 (Bs. 10.000,00)');
        expect(screen.getByRole('status').textContent).toContain('Los precios se calcularon con la tasa anterior');
    });

    it('si la tasa no cambió, no avisa de nada', async () => {
        vi.mocked(fetch).mockResolvedValue(new Response(JSON.stringify(BCV), { status: 200 }));

        renderPage(BCV);
        await vi.advanceTimersByTimeAsync(RATE_REFRESH_MS);

        expect(fetch).toHaveBeenCalled();
        expect(screen.queryByRole('status')).toBeNull();
    });

    it('si el refresco falla, se queda el sello que había', async () => {
        vi.mocked(fetch).mockResolvedValue(new Response('', { status: 500 }));

        const { container } = renderPage(BCV);
        await vi.advanceTimersByTimeAsync(RATE_REFRESH_MS);

        expect(container.textContent).toContain('Tasa BCV del 18/09 09:00');
    });

    /**
     * El endpoint responde `422` a `manual` (`REQ-FX-002` RB-6): una tasa
     * manual es de un negocio y la trae la siguiente navegación.
     */
    it('no pregunta por una tasa manual', async () => {
        renderPage({ ...BCV, source: 'manual', sourceLabel: 'Manual' });
        await vi.advanceTimersByTimeAsync(RATE_REFRESH_MS * 2);

        expect(fetch).not.toHaveBeenCalled();
    });
});

it('ofrece cargar más solo si queda', () => {
    const { rerender } = renderPage(BCV, [card()], 'abc');
    expect(screen.getByRole('button', { name: 'Cargar más' })).toBeInTheDocument();

    rerender(<CatalogShow catalog={catalog()} products={{ data: [card()], nextCursor: null }} filters={NO_FILTERS} facets={facetsFor([card()])} />);
    expect(screen.queryByRole('button', { name: 'Cargar más' })).toBeNull();
});

/** SP-4 · el catálogo sin productos visibles es una página, no un error. */
it('sin productos enseña el estado vacío que invita a escribir al negocio', () => {
    const { container } = renderPage(BCV, []);

    expect(container.textContent).toContain('no tiene productos disponibles');
    expect(container.textContent).toContain('escríbele a Ferretería Oriente');
    // Sin nada que buscar, ni buscador ni panel.
    expect(screen.queryByRole('searchbox')).toBeNull();
});

it('marca el producto agotado', () => {
    renderPage(BCV, [card({ inStock: false })]);

    expect(screen.getByText('Agotado')).toBeInTheDocument();
});

describe('la marca y el sello de plataforma (RB-9)', () => {
    it('en Free lleva el sello y no aplica el logo', () => {
        const { container } = renderPage(BCV, [card()], null, catalog({ business: { ...catalog().business, logoUrl: '/logo.png' } }));

        expect(container.textContent).toContain('Hecho con Apartar');
        expect(container.querySelector('img[src="/logo.png"]')).toBeNull();
    });

    it('con branding aplica el logo y no lleva el sello', () => {
        const branded = catalog({ showsPlatformBadge: false, business: { ...catalog().business, logoUrl: '/logo.png', brandingEnabled: true } });
        const { container } = renderPage(BCV, [card()], null, branded);

        expect(container.textContent).not.toContain('Hecho con Apartar');
        expect(container.querySelector('img[src="/logo.png"]')).not.toBeNull();
    });
});

/** RB-8 · el CTA de seguir llega con las cuentas de H6; no se pinta antes. */
it('no pinta ningún botón de seguir', () => {
    renderPage(BCV);

    expect(screen.queryByText(/seguir/i)).toBeNull();
});
