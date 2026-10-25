import { useCursorPages } from '@/features/catalog/hooks/useCursorPages';
import { type FeedFiltersView, type FeedItem } from '@/types/account';
import { type CursorPage } from '@/types/catalog-view';
import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';

export function useFeed(items: CursorPage<FeedItem>, filters: FeedFiltersView) {
    const pages = useCursorPages(items, 'items');
    const [query, setQuery] = useState(filters.q ?? '');

    const apply = useCallback((next: FeedFiltersView) => {
        const data: Record<string, string> = {};

        if (next.catalog !== null) {
            data.catalogo = next.catalog;
        }

        if (next.q !== null && next.q.trim() !== '') {
            data.q = next.q.trim();
        }

        router.get(window.location.pathname, data, { only: ['items', 'filters'], preserveState: true, preserveScroll: true, replace: true });
    }, []);

    return {
        pages,
        query,
        setQuery,
        search: () => apply({ ...filters, q: query }),
        setCatalog: (catalog: string | null) => apply({ ...filters, catalog }),
        clear: () => {
            setQuery('');
            apply({ catalog: null, q: null });
        },
        isFiltered: filters.catalog !== null || filters.q !== null,
    };
}
