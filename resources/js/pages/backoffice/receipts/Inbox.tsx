import { EmptyState } from '@/components/ui/empty-state';
import { Icon } from '@/components/ui/icon';
import { DataTable } from '@/features/data-table/components/DataTable';
import { inboxColumns } from '@/features/receipts/components/inboxColumns';
import { INBOX_FILTER_LABELS, InboxFilters } from '@/features/receipts/components/InboxFilters';
import { useReceiptInbox } from '@/features/receipts/hooks/useReceiptInbox';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type BackofficeSharedData } from '@/types/backoffice';
import { type ReceiptInboxFilters, type ReceiptInboxProps } from '@/types/receipt';
import { cilDescription, cilReload } from '@coreui/icons';
import { usePage } from '@inertiajs/react';
import { useMemo, type ReactNode } from 'react';

export default function ReceiptInbox(props: ReceiptInboxProps) {
    return (
        <BackofficeLayout
            title="Comprobantes"
            description="Pagos reportados por tus clientes. Los pendientes van primero, del más antiguo al más reciente."
        >
            <InboxContent {...props} />
        </BackofficeLayout>
    );
}

function emptyMessage(narrowed: boolean, page: number): ReactNode {
    if (page > 1) {
        return 'No hay más comprobantes en esta página. Vuelve a la primera para ver la bandeja.';
    }

    return narrowed ? (
        'Ningún comprobante cumple estos filtros.'
    ) : (
        <EmptyState icon={cilDescription} title="Aún no hay comprobantes">
            Cuando un cliente reporte un pago desde su cuenta, lo verás aquí para revisarlo y aprobarlo. Te avisamos con la campana.
        </EmptyState>
    );
}

const NEWEST_FIRST = '-submitted';

function InboxContent({ rows, meta, options, overdueAfterHours, newestPending, now, statusCounts }: ReceiptInboxProps) {
    const { teamMember } = usePage<BackofficeSharedData>().props;

    const columns = useMemo(() => inboxColumns(Date.parse(now)), [now]);
    const { table, arrived } = useReceiptInbox({
        rows,
        meta,
        columns,
        shared: teamMember?.pendingReceipts ?? null,
        newestPending,
        organizationId: teamMember?.organization?.id ?? null,
    });

    const filters = meta.filters as unknown as ReceiptInboxFilters;
    const narrowed = Object.values(meta.filters).some((value) => value !== null);

    return (
        <>
            {arrived ? (
                <div
                    role="status"
                    className="bg-primary-soft text-primary-ink flex items-center gap-2 self-start rounded-full py-2 pr-2 pl-3.5 text-[13px] font-semibold"
                >
                    <Icon icon={cilReload} size={14} />
                    <span>Llegaron comprobantes nuevos.</span>
                    <button
                        type="button"
                        className="hover:bg-base-100/60 rounded-full px-2 py-0.5 underline-offset-2 hover:underline"
                        onClick={() => table.setSort(NEWEST_FIRST)}
                    >
                        Ver los más recientes
                    </button>
                </div>
            ) : null}

            <DataTable
                label="Bandeja de comprobantes"
                table={table}
                filterLabels={INBOX_FILTER_LABELS}
                empty={emptyMessage(narrowed, meta.page)}
                toolbar={
                    <InboxFilters
                        filters={filters}
                        options={options}
                        onChange={table.setFilter}
                        pending={teamMember?.pendingReceipts ?? null}
                        counts={statusCounts}
                    />
                }
            />

            <p className="text-ink-3 text-xs">
                Un comprobante con más de {overdueAfterHours} h en revisión se marca como atrasado. Los de apartados cancelados salen de la cola al
                cancelar, y se ven marcando «Incluir los de apartados cancelados».
            </p>
        </>
    );
}
