import { useServerTable, type ServerTable } from '@/features/data-table/hooks/useServerTable';
import { echo } from '@/lib/echo';
import { type DataTableColumn, type TableMeta } from '@/types/data-table';
import { type ReceiptInboxRow } from '@/types/receipt';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';

export const PENDING_RECEIPTS_POLL_MS = 30_000;

export const SIGNED_URL_REFRESH_MS = 14 * 60_000;

export const PENDING_RECEIPTS_ENDPOINT = '/backoffice/comprobantes/pendientes';

export const PENDING_RECEIPTS_KEY = ['receipts', 'pending'] as const;

const INBOX_PROPS: readonly string[] = ['rows', 'meta', 'now', 'teamMember', 'newestPending'];

export interface PendingReceipts {
    readonly pending: number;
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

function usePendingQuery(shared: number | null, organizationId: number | null = null): PendingReceipts | null {
    const client = useQueryClient();
    const live = useLiveReceipts(shared === null ? null : organizationId);

    const { data } = useQuery({
        queryKey: PENDING_RECEIPTS_KEY,
        queryFn: fetchPending,
        initialData: { pending: shared ?? 0, newest: null },
        enabled: shared !== null,
        staleTime: PENDING_RECEIPTS_POLL_MS,
        refetchInterval: live ? false : PENDING_RECEIPTS_POLL_MS,
        refetchOnWindowFocus: false,
        retry: false,
    });

    useEffect(() => {
        if (shared === null) {
            return;
        }

        void client.cancelQueries({ queryKey: PENDING_RECEIPTS_KEY });
        client.setQueryData<PendingReceipts>(PENDING_RECEIPTS_KEY, (previous) => ({ pending: shared, newest: previous?.newest ?? null }));
    }, [client, shared]);

    return shared === null ? null : data;
}

export function usePendingReceipts(shared: number | null, organizationId: number | null = null): number | null {
    return usePendingQuery(shared, organizationId)?.pending ?? null;
}

function useLiveReceipts(organizationId: number | null): boolean {
    const client = useQueryClient();
    const [connected, setConnected] = useState(false);
    const seen = useRef(new Set<number>());

    useEffect(() => {
        const socket = organizationId === null ? null : echo();

        if (socket === null || organizationId === null) {
            return;
        }

        const channel = `organization.${organizationId}.receipts`;

        socket.private(channel).listen('ReceiptSubmitted', (event: { id: number }) => {
            if (seen.current.has(event.id)) {
                return;
            }

            seen.current.add(event.id);
            client.setQueryData<PendingReceipts>(PENDING_RECEIPTS_KEY, (previous) => ({
                pending: (previous?.pending ?? 0) + 1,
                newest: Math.max(previous?.newest ?? 0, event.id),
            }));
        });

        const connection = socket.connector.pusher.connection;
        const onState = ({ current }: { current: string }) => setConnected(current === 'connected');
        connection.bind('state_change', onState);
        setConnected(connection.state === 'connected');

        return () => {
            connection.unbind('state_change', onState);
            socket.leave(channel);
        };
    }, [client, organizationId]);

    return connected;
}

export interface ReceiptInboxOptions {
    rows: readonly ReceiptInboxRow[];
    meta: TableMeta;
    columns: readonly DataTableColumn<ReceiptInboxRow>[];
    shared: number | null;
    newestPending: number | null;
    organizationId?: number | null;
}

export interface ReceiptInbox {
    table: ServerTable<ReceiptInboxRow>;
    pending: number | null;
    arrived: boolean;
}

export function useReceiptInbox({ rows, meta, columns, shared, newestPending, organizationId = null }: ReceiptInboxOptions): ReceiptInbox {
    const table = useServerTable({ rows, meta, columns, only: INBOX_PROPS });
    const latest = usePendingQuery(shared, organizationId);
    const page = meta.page;

    const reload = useRef(table.setPage);
    useEffect(() => {
        reload.current = table.setPage;
    });

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
