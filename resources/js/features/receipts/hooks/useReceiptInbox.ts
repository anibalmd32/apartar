import { useServerTable, type ServerTable } from '@/features/data-table/hooks/useServerTable';
import { type DataTableColumn, type TableMeta } from '@/types/data-table';
import { type ReceiptInboxRow } from '@/types/receipt';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';

/**
 * La bandeja de conciliación y su badge (`REQ-PAY-004` RB-3, SP-1).
 *
 * ## El contador, por dos caminos y sin tiempo real todavía
 *
 * RB-3 pide que la llegada de un comprobante sume en el badge **sin
 * sondeo**: eso es Reverb, y el cliente de Echo llega con `T-079`. Hasta
 * entonces vale el respaldo de SP-1, que es lo que este archivo hace:
 *
 *  1. la cifra viaja en cada navegación de Inertia
 *     (`teamMember.pendingReceipts`), y
 *  2. se pregunta a `GET /backoffice/comprobantes/pendientes` cada 30 s —el
 *     contador y el `id` del pendiente más nuevo, nunca la tabla—.
 *
 * Cuando llegue Echo, `ReceiptSubmitted` actualizará esta misma consulta
 * (`setQueryData`: `pending + 1` y `newest` = el `id` del evento) y el sondeo
 * quedará para cuando el socket no conecte: el badge y la bandeja no tienen
 * que saber de dónde salió la cifra.
 *
 * ## Qué es «llegó algo»
 *
 * **Un pendiente más nuevo que el más nuevo que pintó la tabla**, y no que la
 * cifra suba: si en la misma media hora sale uno de la cola —se aprueba, se
 * cancela su apartado— y entra otro, la cifra no se mueve y el nuevo pasaría
 * sin aviso. Los `id` crecen, así que comparar el mayor basta.
 *
 * ## Una sola cifra para todas las copias
 *
 * El menú y la bandeja leen el mismo contador, y tienen que decir lo mismo.
 * Por eso es **una** consulta de react-query con una clave fija, compartida
 * por todas las copias del hook bajo el mismo `QueryClient` (el del marco del
 * backoffice): una petición cada 30 s, no una por copia.
 *
 * Si el sondeo falla, se queda la última cifra buena, sin aviso: un badge no
 * puede tumbar la pantalla, y la siguiente navegación la corrige.
 */

/** SP-1: el respaldo pregunta cada 30 segundos. */
export const PENDING_RECEIPTS_POLL_MS = 30_000;

/**
 * Las URL de las miniaturas caducan a los 15 minutos (`REQ-PAY-002` RB-3).
 * La bandeja se recarga antes, para que una fila que se mira tarde —una
 * imagen perezosa más abajo, el enlace al archivo— no responda `403`.
 */
export const SIGNED_URL_REFRESH_MS = 14 * 60_000;

/** El contador de la bandeja (`ReceiptInboxController::pending`). */
export const PENDING_RECEIPTS_ENDPOINT = '/backoffice/comprobantes/pendientes';

/** La clave del contador en react-query, la misma para el menú y la bandeja. */
export const PENDING_RECEIPTS_KEY = ['receipts', 'pending'] as const;

/** Lo que recarga cada visita de la tabla: lo del contrato, su reloj, la cifra del badge y el más nuevo. */
const INBOX_PROPS: readonly string[] = ['rows', 'meta', 'now', 'teamMember', 'newestPending'];

export interface PendingReceipts {
    readonly pending: number;
    /** El `id` del pendiente más nuevo; `null` si no se sabe todavía o no hay. */
    readonly newest: number | null;
}

async function fetchPending(): Promise<PendingReceipts> {
    const response = await fetch(PENDING_RECEIPTS_ENDPOINT, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`El contador de comprobantes no respondió (${response.status}).`);
    }

    return (await response.json()) as PendingReceipts;
}

/**
 * El contador al día, con el pendiente más nuevo.
 *
 * `shared` es la cifra que trajo la última respuesta de Inertia. `null` es
 * «no hay cifra que enseñar» —sin la capacidad, o fuera del negocio— y
 * entonces no se sondea nada.
 */
function usePendingQuery(shared: number | null): PendingReceipts | null {
    const client = useQueryClient();

    const { data } = useQuery({
        queryKey: PENDING_RECEIPTS_KEY,
        queryFn: fetchPending,
        initialData: { pending: shared ?? 0, newest: null },
        enabled: shared !== null,
        staleTime: PENDING_RECEIPTS_POLL_MS,
        refetchInterval: PENDING_RECEIPTS_POLL_MS,
        refetchOnWindowFocus: false,
        retry: false,
    });

    // Una respuesta de Inertia más nueva trae la cifra del servidor: manda
    // sobre lo que el sondeo dijera antes. Un sondeo que estuviera en vuelo
    // se cancela —si no, al volver pisaría la cifra nueva con la vieja—.
    useEffect(() => {
        if (shared === null) {
            return;
        }

        void client.cancelQueries({ queryKey: PENDING_RECEIPTS_KEY });
        client.setQueryData<PendingReceipts>(PENDING_RECEIPTS_KEY, (previous) => ({ pending: shared, newest: previous?.newest ?? null }));
    }, [client, shared]);

    return shared === null ? null : data;
}

/** Cuántos comprobantes esperan al equipo, al día: la cifra del badge. */
export function usePendingReceipts(shared: number | null): number | null {
    return usePendingQuery(shared)?.pending ?? null;
}

export interface ReceiptInboxOptions {
    rows: readonly ReceiptInboxRow[];
    meta: TableMeta;
    columns: readonly DataTableColumn<ReceiptInboxRow>[];
    /** `teamMember.pendingReceipts` de la respuesta que pintó la tabla. */
    shared: number | null;
    /** El pendiente más nuevo cuando se pintó la tabla. */
    newestPending: number | null;
}

export interface ReceiptInbox {
    table: ServerTable<ReceiptInboxRow>;
    /** Lo que dice el badge ahora. */
    pending: number | null;
    /** Si llegó algún comprobante después de pintar la tabla. */
    arrived: boolean;
}

/**
 * La tabla de la bandeja, más lo que llegó desde que se pintó.
 *
 * Cada visita de la tabla pide también `teamMember` y `newestPending`, para
 * que la comparación sea con **esa** respuesta: si no, la bandeja recién
 * recargada seguiría diciendo que han llegado comprobantes que ya enseña.
 */
export function useReceiptInbox({ rows, meta, columns, shared, newestPending }: ReceiptInboxOptions): ReceiptInbox {
    const table = useServerTable({ rows, meta, columns, only: INBOX_PROPS });
    const latest = usePendingQuery(shared);
    const page = meta.page;

    // `setPage` es una función nueva en cada render; por una referencia, para
    // que un render cualquiera —el del sondeo— no vuelva a empezar el reloj.
    const reload = useRef(table.setPage);
    useEffect(() => {
        reload.current = table.setPage;
    });

    // Las URL firmadas de las filas caducan: se renuevan recargando la misma
    // vista antes de que pase, y el reloj vuelve a empezar con cada recarga.
    useEffect(() => {
        const timer = window.setTimeout(() => reload.current(page), SIGNED_URL_REFRESH_MS);

        return () => window.clearTimeout(timer);
    }, [rows, page]);

    return {
        table,
        pending: latest?.pending ?? null,
        arrived: latest !== null && latest.newest !== null && latest.newest > (newestPending ?? 0),
    };
}
