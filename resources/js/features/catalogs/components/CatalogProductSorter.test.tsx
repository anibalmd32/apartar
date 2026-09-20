import { CatalogProductSorter } from '@/features/catalogs/components/CatalogProductSorter';
import { centsUsd } from '@/lib/money';
import { type CatalogProductOption } from '@/types/catalog';
import { type ProductStatus } from '@/types/product';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const { patch, usePage } = vi.hoisted(() => ({ patch: vi.fn(), usePage: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: { patch }, usePage }));

// `MoneyLabel` lee la preferencia de moneda de las props compartidas
// (`REQ-FX-004`). Aquí no se prueba el dinero: se le da lo mínimo para que
// pinte y no tape lo que sí se está probando.
usePage.mockReturnValue({
    props: {
        currency: {
            display: 'usd_primary',
            timezone: 'America/Caracas',
            rate: { rate: 42.5, source: 'bcv', sourceLabel: 'BCV', publishedAt: '2026-09-19T09:00:00-04:00', isStale: false },
        },
    },
});

/**
 * La pantalla de composición de un catálogo (`REQ-CAT-023`).
 *
 * Tres cosas que esta pantalla decide y que no se ven desde el servidor: que
 * el buscador solo ofrece lo que **no** está ya dentro, que el botón de
 * guardar está apagado mientras no haya nada que guardar (RB-8), y que las
 * filas que el catálogo público no enseñará lo dicen con esas palabras
 * (RB-5, `REQ-BOF-024` SP-7).
 *
 * El arrastre en sí no se ejercita aquí: el gesto lo pone `@dnd-kit` y
 * simularlo en jsdom probaría la librería. Lo que el arrastre llama es
 * `reorder`, y eso tiene su prueba en `useSortableProducts.test.ts`.
 */

function product(id: number, name: string, status: ProductStatus = 'published'): CatalogProductOption {
    return {
        id,
        name,
        sku: `SKU-${id}`,
        status,
        price: { cents: centsUsd(25_000), usd: '$250.00', ves: null },
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

    /**
     * El buscador filtra por nombre y por SKU, que es como el negocio busca lo
     * que ya conoce. No hay endpoint detrás: el inventario viaja entero en las
     * props, igual que en el listado de productos.
     */
    it('busca por nombre y por sku', () => {
        renderSorter();

        fireEvent.change(screen.getByLabelText(/Añadir productos/), { target: { value: 'sierra' } });
        expect(screen.getByText('Sierra circular')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText(/Añadir productos/), { target: { value: 'SKU-3' } });
        expect(screen.getByText('Lija')).toBeInTheDocument();
        expect(screen.queryByText('Sierra circular')).not.toBeInTheDocument();
    });

    /**
     * RB-4 · un producto aparece una sola vez por catálogo, así que el
     * buscador **no ofrece** lo que ya está dentro. Ofrecerlo y deduplicar
     * después dejaría a quien lo pulsa sin saber si funcionó.
     */
    it('no ofrece los productos que ya están en el catálogo', () => {
        renderSorter();

        fireEvent.change(screen.getByLabelText(/Añadir productos/), { target: { value: 'taladro' } });

        expect(screen.getByText(/No queda ningún producto sin añadir/)).toBeInTheDocument();
    });

    /**
     * **RB-8 · el guardado es explícito**, y el botón dice cuándo hace falta:
     * lo peor de un guardado explícito es no saber que está pendiente.
     */
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

    /**
     * RB-5 y `REQ-BOF-024` SP-7 · un `draft` puede estar dentro —preparar la
     * campaña antes de lanzarla es el caso de uso— y un archivado que ya
     * estaba sigue dentro. Las dos se marcan: sin eso, el negocio cuenta doce
     * productos aquí, ve nueve en su enlace y no sabe cuáles faltan.
     */
    it('marca las filas que el catálogo público no enseñará', () => {
        renderSorter({ products: [product(1, 'Taladro'), product(2, 'Sierra circular', 'draft')] });

        expect(screen.getAllByText('No se muestra')).toHaveLength(1);
    });

    /**
     * SP-7 · vaciar un catálogo publicado será `422`, y se avisa **antes** de
     * pulsar. El botón sigue activo: quien manda es el servidor, y apagarlo
     * aquí sería prometer una regla que la pantalla solo conoce a medias.
     */
    it('avisa de que un catálogo publicado no se puede quedar vacío', () => {
        renderSorter({ products: [], isPublished: true });

        expect(screen.getByRole('alert')).toHaveTextContent(/no podrás guardarlo sin productos/);
    });

    it('no avisa de lo mismo cuando el catálogo está en borrador', () => {
        renderSorter({ products: [], isPublished: false });

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
});
