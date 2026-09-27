import { centsUsd } from '@/lib/money';
import ProductShow from '@/pages/catalog/ProductShow';
import { fakeRoute } from '@/test/fakes';
import { type CatalogView, type ProductCard, type ProductDetail } from '@/types/catalog-view';
import { type RateView } from '@/types/exchange';
import { type PaymentAccountView } from '@/types/payment';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

/**
 * La ficha pública del producto (`REQ-CAT-003`).
 */

const { usePage, reload, get } = vi.hoisted(() => ({ usePage: vi.fn(), reload: vi.fn(), get: vi.fn() }));

vi.mock('@inertiajs/react', async () => ({ usePage, router: { reload, get }, Head: () => null, Link: (await import('@/test/fakes')).FakeLink }));

const BCV: RateView = { rate: 40, source: 'bcv', sourceLabel: 'BCV', publishedAt: '2026-09-18T09:00:00-04:00', isStale: false };

function money(usd: string, ves: string | null = null) {
    return { cents: centsUsd(100), usd, ves };
}

const CATALOG: CatalogView = {
    slug: 'ofertas',
    name: 'Ofertas de septiembre',
    description: null,
    cover: null,
    business: { name: 'Ferretería Oriente', slug: 'ferreteria', logoUrl: null, brandColor: null, brandingEnabled: false },
    shareUrl: 'http://localhost/c/ofertas',
    expiresAt: null,
    showsPlatformBadge: true,
};

const ACCOUNT: PaymentAccountView = {
    id: 1,
    method: 'pago_movil',
    methodLabel: 'Pago móvil',
    identifierLabel: 'Teléfono',
    bank: 'Banesco',
    holderName: 'Ana Pérez',
    holderDocument: 'V-12.345.678',
    identifier: '0414-1234567',
    instructions: null,
    isActive: true,
    position: 1,
};

function detail(overrides: Partial<ProductDetail> = {}): ProductDetail {
    return {
        slug: 'taladro',
        name: 'Taladro percutor',
        price: money('$250.00', 'Bs. 10.000,00'),
        compareAtPrice: null,
        downPayment: money('$50.00', 'Bs. 2.000,00'),
        installmentAmount: money('$66.66', 'Bs. 2.666,40'),
        lastInstallmentAmount: money('$66.68', 'Bs. 2.667,20'),
        installmentsCount: 3,
        frequencyDays: 15,
        categoryName: 'Herramientas',
        tags: [],
        inStock: true,
        catalogSlug: 'ofertas',
        description: 'Percutor de 800 W.',
        sku: 'TAL-500',
        images: [],
        specificationGroups: [],
        maxQuantity: 5,
        paymentAccounts: [ACCOUNT],
        canBeReserved: true,
        unavailableReason: null,
        ...overrides,
    };
}

function renderPage(product: ProductDetail = detail(), related: ProductCard[] = []) {
    return render(<ProductShow catalog={CATALOG} product={product} related={related} />);
}

beforeEach(() => {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: BCV } } });
    vi.stubGlobal('route', fakeRoute);
    vi.stubGlobal('fetch', vi.fn());
    document.cookie = 'currency_display=; max-age=0; path=/';
    window.localStorage.clear();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

/** RB-2 y RB-3 · el desglose del servidor, con la última cuota cuando difiere. */
it('pinta precio, inicial, cuotas y la última cuota en las dos monedas', () => {
    const { container } = renderPage();

    expect(container.textContent).toContain('$250.00 (Bs. 10.000,00)');
    expect(container.textContent).toContain('3 cuotas de $66.66 (Bs. 2.666,40) cada 15 días');
    expect(container.textContent).toContain('Última cuota$66.68 (Bs. 2.667,20)');
});

it('no dice «última cuota» cuando todas son iguales', () => {
    renderPage(detail({ lastInstallmentAmount: null }));

    expect(screen.queryByText('Última cuota')).toBeNull();
});

/** El toggle reproyecta sin petición: las dos monedas ya estaban. */
it('cambia la moneda de todos los importes sin pedir nada', () => {
    const { container } = renderPage();

    fireEvent.click(screen.getByRole('button', { name: /cambiar la moneda/i }));

    expect(container.textContent).toContain('Bs. 10.000,00 ($250.00)');
    expect(container.textContent).toContain('3 cuotas de Bs. 2.666,40 ($66.66)');
    expect(fetch).not.toHaveBeenCalled();
    expect(get).not.toHaveBeenCalled();
    expect(reload).not.toHaveBeenCalled();
});

/** RB-5 · dónde se paga, antes de comprometerse. */
it('enseña las cuentas de cobro que llegan', () => {
    renderPage();

    const payments = screen.getByRole('region', { name: 'Formas de pago' });
    expect(payments.textContent).toContain('Pago móvil · Banesco');
    expect(payments.textContent).toContain('0414-1234567');
    expect(payments.textContent).toContain('Ana Pérez · V-12.345.678');
});

/** SP-6 · sin cuentas activas, a consultar al negocio; sin un enlace que no lleve a nada. */
it('sin cuentas de cobro manda a consultar al negocio', () => {
    renderPage(detail({ paymentAccounts: [] }));

    const payments = screen.getByRole('region', { name: 'Formas de pago' });
    expect(payments.textContent).toContain('Consulta a Ferretería Oriente por el chat');
    expect(within(payments).queryByRole('link')).toBeNull();
});

/** RB-7 · «Apartar» deshabilitado con su motivo escrito. */
it('deshabilita «Apartar» con el motivo cuando no se puede', () => {
    renderPage(detail({ canBeReserved: false, unavailableReason: 'Agotado: ahora mismo no quedan unidades para apartar.', inStock: false }));

    const button = screen.getByRole('button', { name: 'Apartar' });
    expect(button).toBeDisabled();
    expect(button).toHaveAccessibleDescription('Agotado: ahora mismo no quedan unidades para apartar.');
});

/** RB-7 · con el producto apartable, «Apartar» lleva al formulario de `T-045b`. */
it('con el producto apartable, «Apartar» lleva al formulario y sin motivo', () => {
    renderPage();

    expect(screen.getByRole('link', { name: 'Apartar' })).toHaveAttribute('href', '/c/ofertas/apartar/taladro');
    expect(screen.queryByText(/no quedan unidades/)).toBeNull();
});

/** SP-7 · sin plan no hay cuota que enseñar, y tampoco se inventa. */
it('sin plan de pago enseña el precio y no una cuota', () => {
    const { container } = renderPage(detail({ installmentAmount: null, lastInstallmentAmount: null, canBeReserved: false, unavailableReason: 'x' }));

    expect(container.textContent).toContain('$250.00');
    expect(container.textContent).not.toContain('cuotas de');
});

/** SP-5 · sin ficha técnica no hay sección, ni su encabezado. */
it('omite entera la ficha técnica si no hay', () => {
    renderPage();
    expect(screen.queryByRole('heading', { name: 'Ficha técnica' })).toBeNull();

    renderPage(detail({ specificationGroups: [{ group: null, items: [{ label: 'Potencia', value: '800 W' }] }] }));
    expect(screen.getByRole('heading', { name: 'Ficha técnica' })).toBeInTheDocument();
    expect(screen.getByText('800 W')).toBeInTheDocument();
});

/** SP-4 · sin fotos, la inicial; nunca una imagen rota. */
it('sin fotos pinta la inicial del nombre y ninguna imagen', () => {
    const { container } = renderPage();

    expect(screen.getByRole('img', { name: 'Taladro percutor' }).textContent).toBe('T');
    expect(container.querySelector('img')).toBeNull();
});

it('con varias fotos deja elegir cuál ver en grande', () => {
    const images = [1, 2].map((n) => ({ thumb: `/t${n}.webp`, card: `/c${n}.webp`, zoom: `/z${n}.webp`, alt: `Foto ${n}` }));
    renderPage(detail({ images }));

    expect(screen.getByRole('img', { name: 'Foto 1' })).toHaveAttribute('src', '/z1.webp');

    fireEvent.click(screen.getByRole('button', { name: 'Ver la foto 2' }));
    expect(screen.getByRole('img', { name: 'Foto 2' })).toHaveAttribute('src', '/z2.webp');
});

it('vuelve al catálogo y enlaza los relacionados dentro de él', () => {
    const card: ProductCard = { ...detail(), slug: 'broca', name: 'Broca', image: null, installmentAmount: money('$10.00') };
    renderPage(detail(), [card]);

    expect(screen.getByRole('link', { name: /Ofertas de septiembre/ })).toHaveAttribute('href', '/c/ofertas');
    expect(screen.getByRole('link', { name: /Broca/ })).toHaveAttribute('href', '/c/ofertas/p/broca');
});

/** RB-8 · sin telemetría: pulsar «Apartar» es navegar, no emitir nada (`T-084`). */
it('no emite nada al pulsar «Apartar»', () => {
    renderPage();

    fireEvent.click(screen.getByRole('link', { name: 'Apartar' }));

    expect(fetch).not.toHaveBeenCalled();
    expect(get).not.toHaveBeenCalled();
});
