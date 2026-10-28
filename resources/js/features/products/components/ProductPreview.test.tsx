import { ProductPreview } from '@/features/products/components/ProductPreview';
import { usePendingImages } from '@/features/products/hooks/usePendingImages';
import { useSpecificationRows } from '@/features/products/hooks/useSpecificationRows';
import { type PreviewSource } from '@/features/products/lib/preview';
import { centsUsd } from '@/lib/money';
import { type FinancingPlanView } from '@/types/product';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { useEffect, useRef } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const { usePage } = vi.hoisted(() => ({ usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ usePage, router: { reload: vi.fn() } }));

function money(usd: string) {
    return { cents: centsUsd(100), usd, ves: null };
}

const PLAN: FinancingPlanView = {
    downPayment: money('$15,00'),
    installmentsCount: 3,
    frequencyDays: 15,
    installmentAmount: money('$20,00'),
    lastInstallmentAmount: money('$20,00'),
    hasUnevenLastInstallment: false,
    financedAmount: money('$60,00'),
    total: money('$75,00'),
    compareAtPrice: money('$100,00'),
    savingsPercent: 25,
};

const photo = new File(['x'], 'silla.png', { type: 'image/png' });

type Overrides = Partial<Omit<PreviewSource, 'images' | 'specifications'>> & { withPhoto?: boolean };

function Harness({ withPhoto = true, ...overrides }: Overrides) {
    const photos = usePendingImages(10);
    const sheet = useSpecificationRows(null);
    const seeded = useRef(false);

    useEffect(() => {
        if (seeded.current) {
            return;
        }

        seeded.current = true;

        if (withPhoto) {
            photos.add([photo]);
        }

        sheet.append(['Madera de pino', 'Plegable']);
    });

    const source = (): PreviewSource => ({
        name: 'Silla de playa',
        sku: 'SIL-001',
        description: '<p>Ligera y <strong>resistente</strong></p><script>alert(1)</script>',
        stock: 3,
        categoryName: 'Hogar',
        specifications: sheet.payload(),
        images: photos.images.map((image) => ({ thumb: image.src, card: image.src, zoom: image.src, alt: 'Silla de playa' })),
        plan: PLAN,
        ...overrides,
    });

    return <ProductPreview source={source} />;
}

function openPreview() {
    fireEvent.click(screen.getByRole('button', { name: 'Vista previa' }));

    return screen.getByRole('dialog', { name: 'Así lo verán tus clientes' });
}

beforeEach(() => {
    usePage.mockReturnValue({ props: { currency: { display: 'usd_primary', timezone: 'America/Caracas', rate: null } } });
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:silla'), revokeObjectURL: vi.fn() }));
    document.cookie = 'currency_display=; max-age=0; path=/';
    window.localStorage.clear();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

it('abre la vista previa con lo escrito: nombre, precio, descripción, características y la foto pendiente', () => {
    render(<Harness />);

    const dialog = openPreview();

    expect(within(dialog).getByRole('heading', { level: 1, name: 'Silla de playa' })).toBeInTheDocument();
    expect(dialog.textContent).toContain('Hogar · SKU SIL-001');
    expect(dialog.textContent).toContain('$75,00');
    expect(dialog.textContent).toContain('Ahorras 25%');
    expect(dialog.textContent).toContain('3 cuotas de $20,00 cada 15 días');
    expect(within(dialog).getByText('resistente').tagName).toBe('STRONG');
    expect(dialog.querySelector('script')).toBeNull();
    expect(
        within(dialog)
            .getAllByRole('listitem')
            .map((item) => item.textContent),
    ).toEqual(expect.arrayContaining(['Madera de pino', 'Plegable']));
    expect(dialog.querySelector('img[src="blob:silla"]')).not.toBeNull();
    expect(dialog.textContent).toContain('quedan 3 unidades');
    expect(within(dialog).getByRole('button', { name: 'Apartar ahora' })).toBeDisabled();
    expect(within(dialog).queryByRole('alert')).toBeNull();
});

it('cambia el ancho entre celular y computadora', () => {
    render(<Harness />);

    const dialog = openPreview();
    const frame = within(dialog).getByTestId('product-preview-frame');

    expect(frame).toHaveClass('max-w-[390px]');
    expect(within(dialog).getByRole('button', { name: 'Celular' })).toHaveAttribute('aria-pressed', 'true');

    act(() => {
        fireEvent.click(within(dialog).getByRole('button', { name: 'Computadora' }));
    });

    expect(frame).not.toHaveClass('max-w-[390px]');
    expect(frame).toHaveAttribute('data-device', 'desktop');
    expect(within(dialog).getByRole('button', { name: 'Computadora' })).toHaveAttribute('aria-pressed', 'true');
});

it('avisa de lo que falta para publicar sin impedir la vista previa', () => {
    render(<Harness withPhoto={false} description="<p></p>" categoryName="" plan={null} />);

    const dialog = openPreview();

    expect(dialog.textContent).toContain('Para publicarlo todavía falta al menos una foto, una categoría, la descripción y las condiciones de pago.');
    expect(within(dialog).queryByRole('heading', { name: 'Descripción' })).toBeNull();
    expect(within(dialog).queryByText(/cuotas de/)).toBeNull();
    expect(within(dialog).getByRole('img', { name: 'Silla de playa' }).textContent).toBe('S');
});
