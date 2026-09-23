import { type Paginated } from '@/types/catalog-view';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * «Cargar más» sobre una lista paginada por cursor (`REQ-CAT-021` RB-6).
 *
 * Mobile-first y sin números de página: cada pulsación pide el lote siguiente
 * con una recarga parcial de Inertia —solo la prop de la lista, el resto de la
 * página no se vuelve a calcular— y lo **añade** a lo que ya se ve.
 *
 * ## La URL no cambia
 *
 * `preserveUrl`: el cursor viaja en la petición, no en la barra de direcciones.
 * Si quedara en la URL, quien la compartiera mandaría «el tercer lote» en vez
 * del catálogo, y quien recargara vería doce productos donde había treinta y
 * seis. El enlace que se comparte es el del catálogo, siempre.
 *
 * ## Cuándo se añade y cuándo se sustituye
 *
 * Solo se añade el lote que pidió este hook. Cualquier otra llegada de la prop
 * —una navegación, y los filtros de `T-034` cuando existan— **sustituye** la
 * lista: concatenar el resultado de un filtro a la parrilla anterior mezclaría
 * dos consultas distintas en la misma pantalla.
 */

export interface CursorPages<T> {
    items: readonly T[];
    hasMore: boolean;
    loading: boolean;
    loadMore: () => void;
}

/**
 * @param page el lote que llega en las props
 * @param prop el nombre de esa prop, que es lo único que se recarga
 */
export function useCursorPages<T>(page: Paginated<T>, prop: string): CursorPages<T> {
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
            // Si el lote no llega, la próxima prop que llegue no es la suya.
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
