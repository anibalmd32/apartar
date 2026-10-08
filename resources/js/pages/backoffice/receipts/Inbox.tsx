import { Alert } from '@/components/ui/alert';
import { DataTable } from '@/features/data-table/components/DataTable';
import { inboxColumns } from '@/features/receipts/components/inboxColumns';
import { INBOX_FILTER_LABELS, InboxFilters } from '@/features/receipts/components/InboxFilters';
import { useReceiptInbox } from '@/features/receipts/hooks/useReceiptInbox';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type BackofficeSharedData } from '@/types/backoffice';
import { type ReceiptInboxFilters, type ReceiptInboxProps } from '@/types/receipt';
import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

/**
 * La bandeja de conciliación (`REQ-PAY-004`).
 *
 * La cola de trabajo del negocio: lo que han reportado sus clientes, con los
 * pendientes primero y **el más antiguo arriba** (RB-4). Tabla server-driven
 * (`REQ-BOF-045`) con paginación simple, sin total (SP-4): el estado vive en
 * la URL y se recarga, se comparte y vuelve con «atrás».
 *
 * ## Lo que llega mientras está abierta
 *
 * El badge del menú sondea el contador cada 30 s (SP-1) y, cuando aparece un
 * pendiente más nuevo que los que pintó esta tabla, la pantalla lo dice y
 * lleva a lo más reciente. El aviso **en el momento** (RB-3, el toast) es del
 * cliente de Echo, con `T-079`; este aviso es su respaldo y se queda cuando
 * llegue. La tabla se recarga sola antes de que caduquen las URL firmadas de
 * sus miniaturas.
 *
 * ## Lo que no hace todavía
 *
 * Revisar (RB-6) y aprobar son de `T-061`. Hasta entonces cada fila lleva a
 * la vista del apartado, donde están el cronograma y el saldo, y abre el
 * archivo firmado.
 *
 * El contenido va en un hijo del marco y no en el cuerpo de la página: el
 * contador es una consulta del `QueryClient` del marco, y solo lo ve quien
 * está dentro de él.
 */
export default function ReceiptInbox(props: ReceiptInboxProps) {
    return (
        <BackofficeLayout
            title="Comprobantes"
            description="Lo que han reportado tus clientes. Los pendientes van primero, del más antiguo al más reciente."
        >
            <InboxContent {...props} />
        </BackofficeLayout>
    );
}

/**
 * La frase del estado vacío (`REQ-BOF-045` RB-7): lo que significa este
 * vacío, no un «sin datos». Una página más allá de la última —un enlace viejo,
 * una cola que se vació mientras se miraba— no es «nunca has recibido nada».
 */
function emptyMessage(narrowed: boolean, page: number): string {
    if (page > 1) {
        return 'No hay más comprobantes en esta página. Vuelve a la primera para ver la bandeja.';
    }

    return narrowed
        ? 'Ningún comprobante cumple estos filtros.'
        : 'Todavía no has recibido comprobantes. Aparecerán aquí en cuanto un cliente reporte un pago.';
}

/** El orden que pone primero lo último que llegó. */
const NEWEST_FIRST = '-submitted';

function InboxContent({ rows, meta, options, overdueAfterHours, newestPending, now }: ReceiptInboxProps) {
    const { teamMember } = usePage<BackofficeSharedData>().props;

    // La antigüedad se cuenta con el reloj del servidor que marcó los
    // atrasados, el que llega con las filas.
    const columns = useMemo(() => inboxColumns(Date.parse(now)), [now]);
    const { table, arrived } = useReceiptInbox({ rows, meta, columns, shared: teamMember?.pendingReceipts ?? null, newestPending });

    const filters = meta.filters as unknown as ReceiptInboxFilters;
    const narrowed = Object.values(meta.filters).some((value) => value !== null);

    return (
        <>
            {/* La cola es FIFO: lo que llega va al final, a menudo en otra
                página. Por eso el aviso no recarga la misma vista —se
                apagaría sin enseñar nada— sino que lleva a lo más reciente. */}
            {arrived ? (
                <Alert tone="info" className="flex-col items-start gap-2 sm:flex-row sm:items-center">
                    <span>Llegaron comprobantes nuevos.</span>
                    <button type="button" className="btn btn-sm" onClick={() => table.setSort(NEWEST_FIRST)}>
                        Ver los más recientes
                    </button>
                </Alert>
            ) : null}

            <section className="card bg-base-100 shadow-sm">
                <div className="card-body gap-4">
                    <DataTable
                        label="Bandeja de comprobantes"
                        table={table}
                        filterLabels={INBOX_FILTER_LABELS}
                        empty={emptyMessage(narrowed, meta.page)}
                        toolbar={<InboxFilters filters={filters} options={options} onChange={table.setFilter} />}
                    />

                    <p className="text-xs opacity-70">
                        Un comprobante con más de {overdueAfterHours} h en revisión se marca como atrasado. Los de apartados cancelados salen de la
                        cola al cancelar, y se ven marcando «Incluir los de apartados cancelados».
                    </p>
                </div>
            </section>
        </>
    );
}
