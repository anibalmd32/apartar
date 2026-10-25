import { type CursorPages, useCursorPages } from '@/features/catalog/hooks/useCursorPages';
import { type CatalogFilters, type CatalogSort, type CursorPage } from '@/types/catalog-view';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

export const SEARCH_DEBOUNCE_MS = 300;

export const FILTER_PROPS = ['products', 'filters'] as const;

interface Selection {
    readonly q: string;
    readonly category: string | null;
    readonly tags: readonly string[];
    readonly sort: CatalogSort | null;
}

export interface CatalogFilterControls<T> {
    readonly pages: CursorPages<T>;
    readonly applied: CatalogFilters;
    readonly query: string;
    readonly isNarrowed: boolean;
    setQuery: (text: string) => void;
    setCategory: (slug: string | null) => void;
    toggleTag: (slug: string) => void;
    setSort: (sort: CatalogSort | null) => void;
    clear: () => void;
    showCategory: (slug: string) => void;
}

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
 * @param products
 * @param filters
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
