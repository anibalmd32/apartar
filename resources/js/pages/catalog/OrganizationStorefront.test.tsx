import CatalogExpired from '@/pages/catalog/CatalogExpired';
import OrganizationStorefront from '@/pages/catalog/OrganizationStorefront';
import { type OrganizationBrand } from '@/types/backoffice';
import { type StorefrontCatalog } from '@/types/catalog-view';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

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

it('sin catálogos publicados enseña el estado vacío', () => {
    render(<OrganizationStorefront business={FREE} showsPlatformBadge catalogs={[]} />);

    expect(screen.getByText('Ferretería Oriente todavía no tiene catálogos publicados.')).toBeInTheDocument();
    expect(screen.queryByRole('list')).toBeNull();
});

it('en Free lleva el sello y no pinta el logo; con la marca, al revés', () => {
    const { container, unmount } = render(<OrganizationStorefront business={FREE} showsPlatformBadge catalogs={[]} />);

    expect(container.textContent).toContain('Hecho con Apartar');
    expect(container.querySelector('img[src="/logo.png"]')).toBeNull();
    unmount();

    const branded = render(<OrganizationStorefront business={{ ...FREE, brandingEnabled: true }} showsPlatformBadge={false} catalogs={[]} />);
    expect(branded.container.textContent).not.toContain('Hecho con Apartar');
    expect(branded.container.querySelector('img[src="/logo.png"]')).not.toBeNull();
});

it('la página de la campaña terminada enlaza la vitrina solo si está activada', () => {
    const { unmount } = render(
        <CatalogExpired catalogName="Ofertas" businessName="Ferretería Oriente" storefrontUrl="http://localhost/ferreteria" />,
    );
    expect(screen.getByRole('link', { name: 'Ver los catálogos de Ferretería Oriente' })).toHaveAttribute('href', 'http://localhost/ferreteria');
    unmount();

    render(<CatalogExpired catalogName="Ofertas" businessName="Ferretería Oriente" storefrontUrl={null} />);
    expect(screen.queryByRole('link', { name: /Ver los catálogos/ })).toBeNull();
});

it('cuenta lo que el negocio dice de sí y cuántos productos tiene cada catálogo', () => {
    render(
        <OrganizationStorefront
            business={{ ...FREE, tagline: 'Bolsos y calzado · Caracas' }}
            showsPlatformBadge
            catalogs={[{ ...CATALOG, productsCount: 24 }]}
            profile={{ description: 'Aparta con una inicial.', address: 'C.C. Sambil, Caracas', phone: '0414-555-1234' }}
        />,
    );

    expect(screen.getByText('Aparta con una inicial.')).toBeInTheDocument();
    expect(screen.getByText('C.C. Sambil, Caracas')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: '0414-555-1234' })).toHaveAttribute('href', 'tel:0414-555-1234');
    expect(screen.getByText('24 productos')).toBeInTheDocument();
});

it('la campaña terminada dice hasta qué día estuvo disponible', () => {
    render(<CatalogExpired catalogName="Ofertas" businessName="Ferretería Oriente" storefrontUrl={null} endedOn="2026-05-10" />);

    expect(screen.getByText(/estuvo disponible hasta el 10\/05/)).toBeInTheDocument();
});
