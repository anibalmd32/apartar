import { useSortableProducts } from '@/features/catalogs/hooks/useSortableProducts';
import { centsUsd } from '@/lib/money';
import { type CatalogProductOption } from '@/types/catalog';
import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const patch = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({ router: { patch } }));

function product(id: number, name: string): CatalogProductOption {
    return {
        id,
        name,
        sku: `SKU-${id}`,
        status: 'published',
        price: { cents: centsUsd(25_000), usd: '$250,00', ves: null },
        categoryName: null,
    };
}

function compose(initial: CatalogProductOption[] = []) {
    return renderHook(() => useSortableProducts({ catalogId: 7, initial }));
}

describe('la composición de un catálogo', () => {
    it('empieza con lo que ya está guardado y sin cambios pendientes', () => {
        const { result } = compose([product(1, 'Taladro'), product(2, 'Sierra')]);

        expect(result.current.ids).toEqual([1, 2]);
        expect(result.current.isDirty).toBe(false);
    });

    it('añade un producto al final de la lista', () => {
        const { result } = compose([product(1, 'Taladro')]);

        act(() => result.current.add(product(2, 'Sierra')));

        expect(result.current.ids).toEqual([1, 2]);
        expect(result.current.isDirty).toBe(true);
    });

    it('no añade dos veces el mismo producto', () => {
        const { result } = compose([product(1, 'Taladro')]);

        act(() => result.current.add(product(1, 'Taladro')));

        expect(result.current.ids).toEqual([1]);
        expect(result.current.isDirty).toBe(false);
    });

    it('quita un producto de la lista', () => {
        const { result } = compose([product(1, 'Taladro'), product(2, 'Sierra')]);

        act(() => result.current.remove(1));

        expect(result.current.ids).toEqual([2]);
        expect(result.current.isDirty).toBe(true);
    });

    it('mueve un producto de una posición a otra corriendo a los demás', () => {
        const { result } = compose([product(1, 'Taladro'), product(2, 'Sierra'), product(3, 'Lija')]);

        act(() => result.current.reorder(2, 0));

        expect(result.current.ids).toEqual([3, 1, 2]);
    });

    it('deja de estar sucio si el orden vuelve a ser el guardado', () => {
        const { result } = compose([product(1, 'Taladro'), product(2, 'Sierra')]);

        act(() => result.current.reorder(0, 1));
        expect(result.current.isDirty).toBe(true);

        act(() => result.current.reorder(0, 1));
        expect(result.current.isDirty).toBe(false);
    });

    it('no manda nada al servidor hasta que se pide guardar', () => {
        const { result } = compose([product(1, 'Taladro'), product(2, 'Sierra')]);

        act(() => result.current.reorder(0, 1));
        act(() => result.current.add(product(3, 'Lija')));
        act(() => result.current.remove(1));

        expect(patch).not.toHaveBeenCalled();

        act(() => result.current.save());

        expect(patch).toHaveBeenCalledTimes(1);
        expect(patch).toHaveBeenCalledWith(
            '/backoffice/catalogos/7/productos',
            { products: [2, 3] },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('permite pedir el guardado con la lista vacía', () => {
        const { result } = compose([product(1, 'Taladro')]);

        act(() => result.current.remove(1));
        act(() => result.current.save());

        expect(patch).toHaveBeenCalledWith('/backoffice/catalogos/7/productos', { products: [] }, expect.anything());
    });
});
