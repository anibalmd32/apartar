import { type CatalogFilters } from '@/types/catalog-view';
import { useEffect, useRef } from 'react';

import { track } from './useTelemetry';

export function useCatalogTelemetry(catalogSlug: string, filters: CatalogFilters, results: number): void {
    const viewed = useRef<string | null>(null);
    const lastSearch = useRef<string | null>(null);
    const lastFilter = useRef<string | null>(null);

    useEffect(() => {
        if (viewed.current !== catalogSlug) {
            viewed.current = catalogSlug;
            track('catalog_viewed', { catalogSlug, payload: { source: document.referrer === '' ? 'direct' : 'link' } });
        }

        if (filters.q !== null && filters.q !== lastSearch.current) {
            track('search_performed', { catalogSlug, payload: { term: filters.q.slice(0, 200), results } });
        }

        lastSearch.current = filters.q;

        const filterKey = JSON.stringify([filters.category, filters.tags, filters.sort]);
        const filtering = filters.category !== null || filters.tags.length > 0 || filters.sort !== null;

        if (filtering && filterKey !== lastFilter.current) {
            track('product_list_filtered', {
                catalogSlug,
                payload: {
                    ...(filters.category === null ? {} : { category: filters.category }),
                    tags: filters.tags,
                    ...(filters.sort === null ? {} : { sort: filters.sort }),
                    results,
                },
            });
        }

        lastFilter.current = filterKey;
    }, [catalogSlug, filters, results]);
}
