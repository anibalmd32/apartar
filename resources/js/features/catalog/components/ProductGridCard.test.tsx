import { ProductGridCard } from '@/features/catalog/components/ProductGridCard';
import { centsUsd } from '@/lib/money';
import { type ProductCard } from '@/types/catalog-view';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { currency: { display: 'usd_primary', rate: { rate: null } } } }),
    Link: ({ href, children, className }: { href: string; children: ReactNode; className?: string }) => (
        <a href={href} className={className}>
            {children}
        </a>
    ),
}));

beforeEach(() => {
    vi.stubGlobal('route', () => '/c/temporada/p/vestido');
});

const money = (cents: number, usd: string) => ({ cents: centsUsd(cents), usd, ves: null });

const PRODUCT: ProductCard = {
    slug: 'vestido',
    name: 'Vestido de lino',
    price: money(4_500, '$45,00'),
    compareAtPrice: null,
    downPayment: money(1_000, '$10,00'),
    installmentAmount: money(875, '$8,75'),
    lastInstallmentAmount: null,
    installmentsCount: 4,
    frequencyDays: 7,
    image: null,
    categoryName: 'Vestidos',
    tags: [],
    inStock: true,
    catalogSlug: 'temporada',
};

it('el plan se lee en palabras: la inicial y cuántas cuotas de cuánto', () => {
    render(<ProductGridCard product={PRODUCT} />);

    const plan = screen.getByText((_, element) => element?.getAttribute('aria-hidden') === 'true' && /Inicial/.test(element.textContent ?? ''));

    expect(plan).toHaveTextContent('Inicial $10,00 · 4 cuotas de $8,75');
    expect(plan).not.toHaveTextContent('×');
});

it('sin inicial lo dice en vez de mostrar un cero', () => {
    render(<ProductGridCard product={{ ...PRODUCT, downPayment: money(0, '$0,00') }} />);

    expect(
        screen.getByText((_, element) => element?.getAttribute('aria-hidden') === 'true' && /Sin inicial/.test(element.textContent ?? '')),
    ).toHaveTextContent('Sin inicial · 4 cuotas de $8,75');
});
