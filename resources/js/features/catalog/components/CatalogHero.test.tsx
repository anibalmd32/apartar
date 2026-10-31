import { CatalogHero } from '@/features/catalog/components/CatalogHero';
import { type CatalogView } from '@/types/catalog-view';
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: { rate: null } } } }),
}));

const CATALOG: CatalogView = {
    slug: 'vitrina-irma',
    name: 'Vitrina de Irma',
    description: null,
    cover: null,
    business: { name: 'Inversiones Irma', slug: 'irma', logoUrl: null, brandColor: null, brandingEnabled: false },
    shareUrl: 'http://localhost/c/vitrina-irma',
    expiresAt: null,
    showsPlatformBadge: false,
};

const COVER = { thumb: '/storage/covers/irma.jpg', card: '/storage/covers/irma.jpg', zoom: '/storage/covers/irma.jpg', alt: 'Vitrina de Irma' };

function heroOf(catalog: CatalogView): HTMLElement {
    render(<CatalogHero catalog={catalog} stats={{ products: 4, followers: 0 }} action={null} />);

    const hero = screen.getByRole('heading', { level: 1, name: 'Vitrina de Irma' }).closest('section');
    if (hero === null) throw new Error('Sin sección de cabecera');

    return hero;
}

it('pinta la portada del catálogo de fondo cuando la tiene', () => {
    const hero = heroOf({ ...CATALOG, cover: COVER });

    expect(hero.querySelector('img')).toHaveAttribute('src', '/storage/covers/irma.jpg');
    expect(hero).toHaveTextContent('4 productos');
});

it('sin portada queda con su fondo de color y sin imagen', () => {
    expect(heroOf(CATALOG).querySelector('img')).toBeNull();
});
