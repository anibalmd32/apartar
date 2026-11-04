import { ShopHeader } from '@/features/catalog/components/ShopHeader';
import { type OrganizationBrand } from '@/types/backoffice';
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({
    rate: { rate: 893.2227, source: 'bcv', sourceLabel: 'BCV', publishedAt: new Date().toISOString(), isStale: false } as unknown,
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: page.rate } } }),
}));

const BUSINESS: OrganizationBrand = { name: 'Moda Valentina', slug: 'moda-valentina', logoUrl: null, brandColor: null, brandingEnabled: false };

it('sin entrar a ninguna cuenta, la cabecera de la tienda dice a cuánto está el dólar hoy', () => {
    render(<ShopHeader business={BUSINESS} />);

    expect(screen.getAllByText('Dólar BCV').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Bs. 893,22').length).toBeGreaterThan(0);
});

it('la cabecera de la tienda deja cambiar entre modo claro y oscuro', () => {
    render(<ShopHeader business={BUSINESS} />);

    expect(screen.getByRole('button', { name: /Cambiar a modo (oscuro|claro)/ })).toBeInTheDocument();
});

it('si todavía no hay tasa, no inventa un precio del dólar', () => {
    page.rate = { rate: null, source: 'bcv', sourceLabel: 'BCV', publishedAt: null, isStale: false };
    render(<ShopHeader business={BUSINESS} />);

    expect(screen.queryByText(/Dólar BCV/)).toBeNull();
});
