import { useSortableProducts } from '@/features/catalogs/hooks/useSortableProducts';
import { centsUsd } from '@/lib/money';
import { type CatalogProductOption } from '@/types/catalog';
import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const patch = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({ router: { patch } }));

/**
 * La composición de un catálogo mientras se edita (`REQ-CAT-023`).
 *
 * Lo que se prueba aquí es **lo que decide el hook** —qué entra, en qué orden,
 * y cuándo eso sale hacia el servidor—, no el arrastre: el gesto lo pone
 * `@dnd-kit` y ejercitarlo en jsdom probaría la librería, no esta pantalla. La
 * pieza que el arrastre llama es `reorder`, y esa sí se prueba directamente.
 */

function product(id: number, name: string): CatalogProductOption {
    return {
        id,
        name,
        sku: `SKU-${id}`,
        status: 'published',
        price: { cents: centsUsd(25_000), usd: '$250.00', ves: null },
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

    /**
     * RB-4 · un producto aparece **una sola vez** por catálogo. Añadir dos
     * veces es un doble clic, no una intención: el servidor lo deduplicaría
     * igual (SP-2), pero entonces la pantalla habría enseñado una lista que no
     * era la que se iba a guardar.
     */
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

    /**
     * **El arrastre saca y vuelve a meter**, no intercambia vecinos: llevar el
     * último al principio es un movimiento y deja a los demás corridos, que es
     * lo que se ve al soltarlo. Con un intercambio, el primero acabaría al
     * final — y nadie arrastró nada hasta allí.
     */
    it('mueve un producto de una posición a otra corriendo a los demás', () => {
        const { result } = compose([product(1, 'Taladro'), product(2, 'Sierra'), product(3, 'Lija')]);

        act(() => result.current.reorder(2, 0));

        expect(result.current.ids).toEqual([3, 1, 2]);
    });

    /**
     * Volver al orden guardado deja de contar como cambio: el botón se apaga
     * solo, sin pedir un guardado que no cambiaría nada.
     */
    it('deja de estar sucio si el orden vuelve a ser el guardado', () => {
        const { result } = compose([product(1, 'Taladro'), product(2, 'Sierra')]);

        act(() => result.current.reorder(0, 1));
        expect(result.current.isDirty).toBe(true);

        act(() => result.current.reorder(0, 1));
        expect(result.current.isDirty).toBe(false);
    });

    /**
     * **RB-8 · arrastrar no guarda.** Componer un escaparate son veinte gestos
     * seguidos, y un autoguardado por arrastre mandaría veinte peticiones para
     * una sola decisión — dejando el catálogo publicado pasando por diecinueve
     * órdenes que el negocio no eligió.
     */
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

    /**
     * SP-7 · vaciar se puede **pedir**: el catálogo en borrador lo admite y
     * quien decide es el servidor. La pantalla no se inventa el rechazo.
     */
    it('permite pedir el guardado con la lista vacía', () => {
        const { result } = compose([product(1, 'Taladro')]);

        act(() => result.current.remove(1));
        act(() => result.current.save());

        expect(patch).toHaveBeenCalledWith('/backoffice/catalogos/7/productos', { products: [] }, expect.anything());
    });
});
