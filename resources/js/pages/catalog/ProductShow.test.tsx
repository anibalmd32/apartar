import { centsUsd } from '@/lib/money';
import ProductShow from '@/pages/catalog/ProductShow';
import { fakeRoute } from '@/test/fakes';
import { type CatalogView, type ProductCard, type ProductDetail } from '@/types/catalog-view';
import { type RateView } from '@/types/exchange';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

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

function detail(overrides: Partial<ProductDetail> = {}): ProductDetail {
    return {
        slug: 'taladro',
        name: 'Taladro percutor',
        price: money('$250,00', 'Bs. 10.000,00'),
        compareAtPrice: null,
        downPayment: money('$50,00', 'Bs. 2.000,00'),
        installmentAmount: money('$66,66', 'Bs. 2.666,40'),
        lastInstallmentAmount: money('$66,68', 'Bs. 2.667,20'),
        installmentsCount: 3,
        frequencyDays: 15,
        categoryName: 'Herramientas',
        tags: [],
        inStock: true,
        catalogSlug: 'ofertas',
        description: 'Percutor de 800 W.',
        sku: 'TAL-500',
        images: [],
        specifications: [],
        maxQuantity: 5,
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

it('pinta precio, inicial, cuotas y la última cuota en las dos monedas', () => {
    const { container } = renderPage();

    expect(container.textContent).toContain('$250,00Bs. 10.000,00');
    expect(container.textContent).toContain('3 cuotas de $66,66 (Bs. 2.666,40) cada 15 días');
    expect(container.textContent).toContain('Última cuota$66,68 (Bs. 2.667,20)');
});

it('no dice «última cuota» cuando todas son iguales', () => {
    renderPage(detail({ lastInstallmentAmount: null }));

    expect(screen.queryByText('Última cuota')).toBeNull();
});

it('cambia la moneda de todos los importes sin pedir nada', () => {
    const { container } = renderPage();

    fireEvent.click(screen.getByRole('button', { name: 'Bs.' }));

    expect(container.textContent).toContain('Bs. 10.000,00$250,00');
    expect(container.textContent).toContain('3 cuotas de Bs. 2.666,40 ($66,66)');
    expect(fetch).not.toHaveBeenCalled();
    expect(get).not.toHaveBeenCalled();
    expect(reload).not.toHaveBeenCalled();
});

it('no enseña las cuentas de cobro: solo salen donde se paga', () => {
    renderPage();

    expect(screen.queryByRole('region', { name: 'Formas de pago' })).toBeNull();
    expect(screen.queryByText(/Cómo pagarle/)).toBeNull();
});

it('deshabilita «Apartar» con el motivo cuando no se puede', () => {
    renderPage(detail({ canBeReserved: false, unavailableReason: 'Agotado: ahora mismo no quedan unidades para apartar.', inStock: false }));

    const button = screen.getByRole('button', { name: 'Apartar ahora' });
    expect(button).toBeDisabled();
    expect(button).toHaveAccessibleDescription('Agotado: ahora mismo no quedan unidades para apartar.');
});

it('con el producto apartable, «Apartar» lleva al formulario y sin motivo', () => {
    renderPage();

    expect(screen.getByRole('link', { name: 'Apartar ahora' })).toHaveAttribute('href', '/c/ofertas/apartar/taladro');
    expect(screen.queryByText(/no quedan unidades/)).toBeNull();
});

it('sin plan de pago enseña el precio y no una cuota', () => {
    const { container } = renderPage(detail({ installmentAmount: null, lastInstallmentAmount: null, canBeReserved: false, unavailableReason: 'x' }));

    expect(container.textContent).toContain('$250,00');
    expect(container.textContent).not.toContain('cuotas de');
});

it('pinta la descripción con su formato', () => {
    renderPage(detail({ description: '<p>Percutor de <strong>800 W</strong></p><ul><li><p>Maletín</p></li><li><p>Brocas</p></li></ul>' }));

    const section = screen.getByRole('heading', { name: 'Descripción' }).closest('section') as HTMLElement;

    expect(within(section).getByText('800 W').tagName).toBe('STRONG');
    expect(
        within(section)
            .getAllByRole('listitem')
            .map((item) => item.textContent),
    ).toEqual(['Maletín', 'Brocas']);
});

it('sin descripción omite su sección', () => {
    renderPage(detail({ description: '', specifications: ['Potencia de 800 W'] }));

    expect(screen.queryByRole('heading', { name: 'Descripción' })).toBeNull();
});

it('omite entera la lista de características si no hay', () => {
    renderPage();
    expect(screen.queryByRole('heading', { name: 'Características' })).toBeNull();
});

it('muestra las características como una lista en su orden', () => {
    renderPage(detail({ description: '', specifications: ['Potencia de 800 W', 'Cuerpo de acero'] }));

    const section = screen.getByRole('heading', { name: 'Características' }).closest('section');
    const items = within(section as HTMLElement).getAllByRole('listitem');

    expect(items.map((item) => item.textContent)).toEqual(['Potencia de 800 W', 'Cuerpo de acero']);
});

it('pinta las características como texto, sin interpretar HTML', () => {
    const { container } = renderPage(detail({ specifications: ['<img src=x onerror=alert(1)>'] }));

    expect(screen.getByText('<img src=x onerror=alert(1)>')).toBeInTheDocument();
    expect(container.querySelector('img[src="x"]')).toBeNull();
});

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
    const card: ProductCard = { ...detail(), slug: 'broca', name: 'Broca', image: null, installmentAmount: money('$10,00') };
    renderPage(detail(), [card]);

    expect(screen.getByRole('link', { name: /Ofertas de septiembre/ })).toHaveAttribute('href', '/c/ofertas');
    expect(screen.getByRole('link', { name: /Broca/ })).toHaveAttribute('href', '/c/ofertas/p/broca');
});

it('no emite nada al pulsar «Apartar»', () => {
    renderPage();

    fireEvent.click(screen.getByRole('link', { name: 'Apartar ahora' }));

    expect(fetch).not.toHaveBeenCalled();
    expect(get).not.toHaveBeenCalled();
});

it('dice cuánto se ahorra y cuántas quedan', () => {
    renderPage(detail({ savingsPercent: 17, stockLeft: 6 }));

    expect(screen.getByText('Ahorras 17%')).toBeInTheDocument();
    expect(screen.getByText(/quedan 6 unidades/)).toBeInTheDocument();
});
