import { type CursorPages, useCursorPages } from '@/features/catalog/hooks/useCursorPages';
import { type CatalogFilters, type CatalogSort, type CursorPage } from '@/types/catalog-view';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Búsqueda, filtros y orden del catálogo, en la query string
 * (`REQ-CAT-006` RB-6, RB-7; `REQ-CAT-002` RB-6).
 *
 * ## La URL es el estado
 *
 * Cada cambio es una visita `GET` a la misma página con los filtros en la
 * query string —`?q=…&category=…&tags[]=…&sort=…`—, así que la URL de la
 * barra de direcciones **es** lo que se ve, y quien la recibe por WhatsApp ve
 * el mismo conjunto (RB-6). Lo vacío no se escribe: el catálogo sin filtros es
 * `/c/{slug}`, sin interrogación.
 *
 * ## Recarga parcial, y sin cursor
 *
 * La visita pide solo `products` y `filters` (RB-7) y conserva el *scroll* y el
 * estado de los componentes, así que el panel no se cierra ni la página salta
 * al principio. Y **nunca lleva el cursor**: se construye desde la ruta, no
 * desde la URL actual, de modo que cambiar un filtro con el tercer lote ya
 * cargado vuelve al primero. Un cursor que sobreviviera al cambio pertenecería
 * a otra consulta y abriría huecos que nadie sabría explicar.
 *
 * Los lotes los lleva `useCursorPages`, que **sustituye** la lista en toda
 * llegada de `products` que no haya pedido él: la de un filtro es una de esas,
 * y por eso el resultado de un filtro no se concatena al anterior.
 *
 * ## El término espera 300 ms
 *
 * RB-6: la búsqueda no dispara una petición por tecla. El texto se guarda al
 * momento —el campo responde— y la visita sale cuando se deja de escribir; si
 * entretanto se toca otro filtro, sale ya con el texto que haya. Y la visita
 * del término **sustituye** la entrada del historial en vez de añadir otra,
 * para que «atrás» no deshaga la búsqueda letra a letra.
 */

export const SEARCH_DEBOUNCE_MS = 300;

/** Las props que recarga un cambio de filtro (RB-7). */
export const FILTER_PROPS = ['products', 'filters'] as const;

interface Selection {
    readonly q: string;
    readonly category: string | null;
    readonly tags: readonly string[];
    readonly sort: CatalogSort | null;
}

export interface CatalogFilterControls<T> {
    readonly pages: CursorPages<T>;
    /** Lo que el servidor aplicó. */
    readonly applied: CatalogFilters;
    /** El texto del buscador, que puede ir por delante de lo aplicado. */
    readonly query: string;
    /** Si hay algo que acote la parrilla: término, categoría o etiquetas. */
    readonly isNarrowed: boolean;
    setQuery: (text: string) => void;
    setCategory: (slug: string | null) => void;
    toggleTag: (slug: string) => void;
    setSort: (sort: CatalogSort | null) => void;
    /** «Ver todo el catálogo» (`REQ-CAT-006` SP-2): fuera todo menos el orden. */
    clear: () => void;
    /** Desde el estado vacío (`REQ-CAT-002` SP-2): solo esa categoría, sin término ni etiquetas. */
    showCategory: (slug: string) => void;
}

/** La query string de una selección, sin claves vacías. */
function queryOf(selection: Selection): Record<string, string | string[]> {
    const data: Record<string, string | string[]> = {};
    const q = selection.q.trim();

    if (q !== '') data.q = q;
    if (selection.category !== null) data.category = selection.category;
    if (selection.tags.length > 0) data.tags = [...selection.tags];
    if (selection.sort !== null) data.sort = selection.sort;

    return data;
}

/**
 * @param products el lote de la parrilla que llega en las props
 * @param filters los filtros aplicados que llegan en las props
 */
export function useCatalogFilters<T>(products: CursorPage<T>, filters: CatalogFilters): CatalogFilterControls<T> {
    const pages = useCursorPages(products, 'products');
    const [query, setQueryText] = useState(filters.q ?? '');
    const typed = useRef(filters.q ?? '');
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const cancelPending = useCallback(() => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
            timer.current = null;
        }
    }, []);

    useEffect(() => cancelPending, [cancelPending]);

    const visit = useCallback(
        (changes: Partial<Selection>, replace = false) => {
            cancelPending();

            const selection: Selection = {
                q: typed.current,
                category: filters.category,
                tags: filters.tags,
                sort: filters.sort,
                ...changes,
            };

            router.get(window.location.pathname, queryOf(selection), {
                only: [...FILTER_PROPS],
                preserveScroll: true,
                preserveState: true,
                replace,
            });
        },
        [cancelPending, filters.category, filters.tags, filters.sort],
    );

    const setQuery = useCallback(
        (text: string) => {
            setQueryText(text);
            typed.current = text;
            cancelPending();
            timer.current = setTimeout(() => visit({ q: text }, true), SEARCH_DEBOUNCE_MS);
        },
        [cancelPending, visit],
    );

    const toggleTag = useCallback(
        (slug: string) => visit({ tags: filters.tags.includes(slug) ? filters.tags.filter((tag) => tag !== slug) : [...filters.tags, slug] }),
        [visit, filters.tags],
    );

    // Vuelta a empezar, quedándose como mucho con una categoría y con el orden.
    const restart = useCallback(
        (category: string | null) => {
            setQueryText('');
            typed.current = '';
            visit({ q: '', category, tags: [] });
        },
        [visit],
    );

    return {
        pages,
        applied: filters,
        query,
        isNarrowed: filters.q !== null || filters.category !== null || filters.tags.length > 0,
        setQuery,
        setCategory: useCallback((category: string | null) => visit({ category }), [visit]),
        toggleTag,
        setSort: useCallback((sort: CatalogSort | null) => visit({ sort }), [visit]),
        clear: useCallback(() => restart(null), [restart]),
        showCategory: restart,
    };
}
