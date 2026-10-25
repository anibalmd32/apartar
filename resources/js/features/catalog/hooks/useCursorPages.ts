import { type CursorPage } from '@/types/catalog-view';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface CursorPages<T> {
    items: readonly T[];
    hasMore: boolean;
    loading: boolean;
    loadMore: () => void;
}

/**
 * @param page
 * @param prop
 */
export function useCursorPages<T>(page: CursorPage<T>, prop: string): CursorPages<T> {
    const [items, setItems] = useState<readonly T[]>(page.data);
    const [loading, setLoading] = useState(false);
    const appending = useRef(false);

    useEffect(() => {
        const append = appending.current;
        appending.current = false;

        setItems((current) => (append ? [...current, ...page.data] : page.data));
    }, [page]);

    const loadMore = useCallback(() => {
        if (page.nextCursor === null || loading) {
            return;
        }

        appending.current = true;

        router.reload({
            only: [prop],
            data: { cursor: page.nextCursor },
            preserveUrl: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
            onError: () => {
                appending.current = false;
            },
            onCancel: () => {
                appending.current = false;
            },
        });
    }, [page.nextCursor, loading, prop]);

    return {
        items,
        hasMore: page.nextCursor !== null,
        loading,
        loadMore,
    };
}
