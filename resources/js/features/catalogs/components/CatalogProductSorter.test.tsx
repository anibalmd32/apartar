import { CatalogProductSorter } from '@/features/catalogs/components/CatalogProductSorter';
import { centsUsd } from '@/lib/money';
import { type CatalogProductOption } from '@/types/catalog';
import { type ProductStatus } from '@/types/product';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const { patch, usePage } = vi.hoisted(() => ({ patch: vi.fn(), usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: { patch }, usePage }));

usePage.mockReturnValue({
    props: {
        currency: {
            display: 'usd_primary',
            timezone: 'America/Caracas',
            rate: { rate: 42.5, source: 'bcv', sourceLabel: 'BCV', publishedAt: '2026-09-19T09:00:00-04:00', isStale: false },
        },
    },
});

function product(id: number, name: string, status: ProductStatus = 'published'): CatalogProductOption {
    return {
        id,
        name,
        sku: `SKU-${id}`,
        status,
        price: { cents: centsUsd(25_000), usd: '$250,00', ves: null },
        categoryName: null,
    };
}

function renderSorter(overrides: Partial<Parameters<typeof CatalogProductSorter>[0]> = {}) {
    return render(
        <CatalogProductSorter
            catalogId={7}
            products={[product(1, 'Taladro')]}
            available={[product(1, 'Taladro'), product(2, 'Sierra circular'), product(3, 'Lija')]}
            isPublished={false}
            {...overrides}
        />,
    );
}

describe('la composición de un catálogo', () => {
    it('pinta los productos que ya están dentro', () => {
        renderSorter();

        expect(screen.getByText('Taladro')).toBeInTheDocument();
    });

    it('busca por nombre y por sku', () => {
        renderSorter();

        fireEvent.change(screen.getByLabelText(/Añadir productos/), { target: { value: 'sierra' } });
        expect(screen.getByText('Sierra circular')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText(/Añadir productos/), { target: { value: 'SKU-3' } });
        expect(screen.getByText('Lija')).toBeInTheDocument();
        expect(screen.queryByText('Sierra circular')).not.toBeInTheDocument();
    });

    it('no ofrece los productos que ya están en el catálogo', () => {
        renderSorter();

        fireEvent.change(screen.getByLabelText(/Añadir productos/), { target: { value: 'taladro' } });

        expect(screen.getByText(/No queda ningún producto sin añadir/)).toBeInTheDocument();
    });

    it('guarda solo al pulsar, y el botón está apagado mientras no haya cambios', () => {
        renderSorter();

        const save = screen.getByRole('button', { name: 'Guardar orden' });

        expect(save).toBeDisabled();
        expect(screen.getByText(/Todo guardado/)).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText(/Añadir productos/), { target: { value: 'sierra' } });
        fireEvent.click(screen.getByRole('button', { name: /Sierra circular/ }));

        expect(patch).not.toHaveBeenCalled();
        expect(save).toBeEnabled();
        expect(screen.getByText(/cambios sin guardar/)).toBeInTheDocument();

        fireEvent.click(save);

        expect(patch).toHaveBeenCalledWith('/backoffice/catalogos/7/productos', { products: [1, 2] }, expect.anything());
    });

    it('marca las filas que el catálogo público no enseñará', () => {
        renderSorter({ products: [product(1, 'Taladro'), product(2, 'Sierra circular', 'draft')] });

        expect(screen.getAllByText('No se muestra')).toHaveLength(1);
    });

    it('avisa de que un catálogo publicado no se puede quedar vacío', () => {
        renderSorter({ products: [], isPublished: true });

        expect(screen.getByRole('alert')).toHaveTextContent(/no podrás guardarlo sin productos/);
    });

    it('no avisa de lo mismo cuando el catálogo está en borrador', () => {
        renderSorter({ products: [], isPublished: false });

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
});
