import { centsUsd } from '@/lib/money';
import CatalogShow from '@/pages/catalog/CatalogShow';
import { type CatalogFacets, type CatalogFilters, type CatalogView, type ProductCard } from '@/types/catalog-view';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

/**
 * El panel de filtros, el buscador y los estados vacíos de la vista pública
 * (`REQ-CAT-006` RB-3, RB-4, SP-1, SP-2, SP-6; `REQ-CAT-002` SP-2).
 */

const { usePage, reload, get } = vi.hoisted(() => ({ usePage: vi.fn(), reload: vi.fn(), get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ usePage, router: { reload, get }, Head: () => null }));

const NONE: CatalogFilters = { q: null, category: null, tags: [], sort: null, ignored: false };

const FACETS: CatalogFacets = {
    categories: [
        { slug: 'taladros', name: 'Taladros', count: 4 },
        { slug: 'sierras', name: 'Sierras', count: 2 },
    ],
    tags: [{ slug: 'oferta', name: 'Oferta', count: 3 }],
};

const money = { cents: centsUsd(100), usd: '$1.00', ves: null };

const CARD: ProductCard = {
    slug: 'taladro',
    name: 'Taladro percutor',
    price: money,
    compareAtPrice: null,
    downPayment: money,
    installmentAmount: money,
    lastInstallmentAmount: null,
    installmentsCount: 4,
    frequencyDays: 15,
    image: null,
    categoryName: 'Taladros',
    tags: [],
    inStock: true,
    catalogSlug: 'ofertas',
};

const CATALOG: CatalogView = {
    slug: 'ofertas',
    name: 'Ofertas',
    description: null,
    cover: null,
    business: { name: 'Ferretería Oriente', slug: 'ferreteria', logoUrl: null, brandColor: null, brandingEnabled: false },
    shareUrl: 'http://localhost/c/ofertas',
    expiresAt: null,
    showsPlatformBadge: true,
};

function renderPage(filters: CatalogFilters = NONE, facets: CatalogFacets = FACETS, products: ProductCard[] = [CARD]) {
    return render(<CatalogShow catalog={CATALOG} products={{ data: products, nextCursor: null }} filters={filters} facets={facets} />);
}

beforeEach(() => {
    const rate = { rate: null, source: 'bcv', sourceLabel: 'BCV', publishedAt: null, isStale: false };
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate } } });
    window.history.replaceState(null, '', '/c/ofertas');
    get.mockClear();
});

it('ofrece las categorías y etiquetas presentes con su conteo, y marca lo aplicado', () => {
    renderPage({ ...NONE, category: 'taladros', tags: ['oferta'] });

    const categories = screen.getByRole('group', { name: 'Categoría' });
    expect(within(categories).getByRole('button', { name: 'Taladros (4)' })).toHaveAttribute('aria-pressed', 'true');
    expect(within(categories).getByRole('button', { name: 'Sierras (2)' })).toHaveAttribute('aria-pressed', 'false');
    expect(within(screen.getByRole('group', { name: 'Etiquetas' })).getByRole('button', { name: 'Oferta (3)' })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
});

it('pulsar la categoría marcada la quita, y «Todas» también', () => {
    renderPage({ ...NONE, category: 'taladros' });

    fireEvent.click(screen.getByRole('button', { name: 'Taladros (4)' }));
    expect(get.mock.lastCall?.[1]).toEqual({});

    fireEvent.click(screen.getByRole('button', { name: 'Sierras (2)' }));
    expect(get.mock.lastCall?.[1]).toEqual({ category: 'sierras' });
});

/** SP-6 · una sola categoría no filtra nada: el grupo no se pinta. */
it('oculta la categoría si todo el catálogo es de una sola, y las etiquetas si no hay', () => {
    renderPage(NONE, { categories: [{ slug: 'taladros', name: 'Taladros', count: 4 }], tags: [] });

    expect(screen.queryByRole('group', { name: 'Categoría' })).toBeNull();
    expect(screen.queryByRole('group', { name: 'Etiquetas' })).toBeNull();
    expect(screen.getByRole('searchbox', { name: 'Buscar en este catálogo' })).toBeInTheDocument();
});

/** `REQ-CAT-002` RB-4 · con término, el defecto es la relevancia y el curado se elige. */
it('con término ofrece la relevancia como defecto y el orden del negocio como elección', () => {
    const { unmount } = renderPage();
    const plain = screen.getByRole('combobox', { name: 'Ordenar por' });
    expect(
        within(plain)
            .getAllByRole('option')
            .map((option) => option.textContent)[0],
    ).toBe('Recomendados');
    unmount();

    renderPage({ ...NONE, q: 'taladro' });
    const options = within(screen.getByRole('combobox', { name: 'Ordenar por' })).getAllByRole('option');
    expect(options.map((option) => option.textContent).slice(0, 2)).toEqual(['Más relevantes', 'Recomendados']);

    fireEvent.change(screen.getByRole('combobox', { name: 'Ordenar por' }), { target: { value: 'curated' } });
    expect(get.mock.lastCall?.[1]).toEqual({ q: 'taladro', sort: 'curated' });
});

it('arranca el buscador con el término del enlace', () => {
    renderPage({ ...NONE, q: 'taladro' });

    expect(screen.getByRole('searchbox')).toHaveValue('taladro');
});

/** SP-1 · el filtro del enlace que ya no existe se quita con un aviso, sin `404`. */
it('avisa de que quitó un filtro del enlace', () => {
    renderPage({ ...NONE, ignored: true });

    expect(screen.getByRole('alert').textContent).toContain('ya no está en este catálogo');
});

/** `REQ-CAT-002` SP-2 y `REQ-CAT-006` SP-2 · sin resultados, volver a lo que sí hay. */
it('sin resultados ofrece todo el catálogo y sus categorías, no el estado del catálogo vacío', () => {
    renderPage({ ...NONE, q: 'xyz' }, FACETS, []);

    expect(screen.getByText('No hay productos que coincidan con lo que buscas.')).toBeInTheDocument();
    expect(screen.queryByText(/no tiene productos disponibles/)).toBeNull();

    // La categoría del estado vacío empieza de cero: sin el término que no dio nada.
    fireEvent.click(within(screen.getByRole('list', { name: 'Categorías de este catálogo' })).getByRole('button', { name: 'Sierras (2)' }));
    expect(get.mock.lastCall?.[1]).toEqual({ category: 'sierras' });

    fireEvent.click(screen.getByRole('button', { name: 'Ver todo el catálogo' }));
    expect(get.mock.lastCall?.[1]).toEqual({});
});
