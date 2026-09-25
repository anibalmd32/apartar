import CatalogExpired from '@/pages/catalog/CatalogExpired';
import OrganizationStorefront from '@/pages/catalog/OrganizationStorefront';
import { type OrganizationBrand } from '@/types/backoffice';
import { type StorefrontCatalog } from '@/types/catalog-view';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

/**
 * La vitrina del negocio (`REQ-ORG-006` RB-5, RB-6, SP-4) y el enlace que le
 * hace la página del `410` (`REQ-CAT-021` SP-3).
 */

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ usePage, Head: () => null }));

const FREE: OrganizationBrand = {
    name: 'Ferretería Oriente',
    slug: 'ferreteria',
    logoUrl: '/logo.png',
    brandColor: '#123456',
    brandingEnabled: false,
};

const CATALOG: StorefrontCatalog = {
    slug: 'ofertas',
    name: 'Ofertas de septiembre',
    description: 'Taladros y sierras.',
    cover: null,
    url: 'http://localhost/c/ofertas',
    expiresAt: null,
};

beforeEach(() => {
    const rate = { rate: null, source: 'bcv', sourceLabel: 'BCV', publishedAt: null, isStale: false };
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate } } });
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    vi.unstubAllGlobals();
});

it('lista los catálogos que llegan, cada uno con su enlace completo', () => {
    render(<OrganizationStorefront business={FREE} showsPlatformBadge catalogs={[CATALOG]} />);

    expect(screen.getByRole('link', { name: /Ofertas de septiembre/ })).toHaveAttribute('href', 'http://localhost/c/ofertas');
    expect(screen.getByText('Taladros y sierras.')).toBeInTheDocument();
});

/** SP-4 · la vitrina encendida y vacía es una página, no un error. */
it('sin catálogos publicados enseña el estado vacío', () => {
    render(<OrganizationStorefront business={FREE} showsPlatformBadge catalogs={[]} />);

    expect(screen.getByText('Ferretería Oriente todavía no tiene catálogos publicados.')).toBeInTheDocument();
    // Ni una lista vacía: el único enlace que queda es el sello de la plataforma.
    expect(screen.queryByRole('list')).toBeNull();
});

/** RB-5 · en Free, la marca se guarda pero no se aplica, y va el sello. */
it('en Free lleva el sello y no pinta el logo; con la marca, al revés', () => {
    const { container, unmount } = render(<OrganizationStorefront business={FREE} showsPlatformBadge catalogs={[]} />);

    expect(container.textContent).toContain('Hecho con Apartar');
    expect(container.querySelector('img[src="/logo.png"]')).toBeNull();
    unmount();

    const branded = render(<OrganizationStorefront business={{ ...FREE, brandingEnabled: true }} showsPlatformBadge={false} catalogs={[]} />);
    expect(branded.container.textContent).not.toContain('Hecho con Apartar');
    expect(branded.container.querySelector('img[src="/logo.png"]')).not.toBeNull();
});

/** `REQ-CAT-021` SP-3 · el `410` enlaza la vitrina si la hay, y si no, nada. */
it('la página de la campaña terminada enlaza la vitrina solo si está activada', () => {
    const { unmount } = render(
        <CatalogExpired catalogName="Ofertas" businessName="Ferretería Oriente" storefrontUrl="http://localhost/ferreteria" />,
    );
    expect(screen.getByRole('link', { name: 'Ver los catálogos de Ferretería Oriente' })).toHaveAttribute('href', 'http://localhost/ferreteria');
    unmount();

    render(<CatalogExpired catalogName="Ofertas" businessName="Ferretería Oriente" storefrontUrl={null} />);
    expect(screen.queryByRole('link')).toBeNull();
});
