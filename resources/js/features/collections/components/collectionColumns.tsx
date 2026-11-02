import { Badge } from '@/components/ui/badge';
import { Contact, CustomerCell, LayawayLink } from '@/features/collections/components/collectionCells';
import { customerMobile, DueBadge, dueDateMeta, dueMeta, expiryBadge } from '@/features/collections/components/collectionMobile';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { formatDate } from '@/lib/dates';
import { type CollectionDownPaymentRow, type CollectionInstallmentRow, type CollectionLayawayRow } from '@/types/collections';
import { type DataTableColumn } from '@/types/data-table';
import { Link } from '@inertiajs/react';

export const installmentColumns: DataTableColumn<CollectionInstallmentRow>[] = [
    { id: 'customer', header: 'Cliente', mobile: customerMobile(), cell: (row) => <CustomerCell customer={row.customer} /> },
    { id: 'layaway', header: 'Apartado', mobile: { subtitle: (row) => row.layaway.code }, cell: (row) => <LayawayLink layaway={row.layaway} /> },
    {
        id: 'number',
        header: 'Cuota',
        mobile: { subtitle: (row) => (row.of ? `Cuota ${row.number} de ${row.of}` : `Cuota ${row.number}`) },
        cell: (row) => <span className="whitespace-nowrap">{row.of ? `${row.number} de ${row.of}` : row.number}</span>,
    },
    {
        id: 'due',
        header: 'Vence',
        mobile: { status: (row) => <DueBadge row={row} />, meta: (row) => formatDate(row.dueDate) },
        cell: (row) => (
            <div className="flex flex-col gap-1">
                <span>{formatDate(row.dueDate)}</span>
                <DueBadge row={row} />
            </div>
        ),
    },
    {
        id: 'amount',
        header: 'Importe',
        align: 'end',
        mobile: 'amount',
        cell: (row) => <MoneyLabel money={row.amount} className="font-semibold" compact />,
    },
    {
        id: 'paid',
        header: 'Pagado',
        align: 'end',
        mobile: {
            meta: (row) =>
                Number(row.paid.cents) === 0 ? null : (
                    <span>
                        Abonado <MoneyLabel money={row.paid} compact />
                    </span>
                ),
        },
        cell: (row) => <MoneyLabel money={row.paid} className="text-ink-2" compact />,
    },
    {
        id: 'actions',
        header: 'Acciones',
        align: 'end',
        mobile: 'hidden',
        cell: (row) => (
            <span className="flex items-center justify-end gap-1.5">
                <Contact customer={row.customer} />
                <Link href={row.layaway.url} className="btn btn-secondary btn-sm">
                    Registrar pago
                </Link>
            </span>
        ),
    },
];

export const layawayColumns: DataTableColumn<CollectionLayawayRow>[] = [
    { id: 'customer', header: 'Cliente', mobile: customerMobile(), cell: (row) => <CustomerCell customer={row.customer} /> },
    { id: 'layaway', header: 'Apartado', mobile: { subtitle: (row) => row.layaway.code }, cell: (row) => <LayawayLink layaway={row.layaway} /> },
    { id: 'product', header: 'Producto', mobile: 'subtitle', cell: (row) => row.productName },
    {
        id: 'completed',
        header: 'Pagado el',
        mobile: { meta: (row) => dueMeta(row.completedAt, 'Pagado el') },
        cell: (row) => (row.completedAt === null ? '—' : formatDate(row.completedAt)),
    },
    {
        id: 'total',
        header: 'Total',
        align: 'end',
        mobile: 'amount',
        cell: (row) => <MoneyLabel money={row.total} className="font-semibold" compact />,
    },
    {
        id: 'actions',
        header: 'Acciones',
        align: 'end',
        mobile: 'hidden',
        cell: (row) => (
            <Link href={row.layaway.url} className="btn btn-secondary btn-sm">
                Marcar entrega
            </Link>
        ),
    },
];

function ExpiryCell({ expiresAt }: { expiresAt: string | null }) {
    if (expiresAt === null) {
        return <Badge tone="neutral">Sin vencimiento</Badge>;
    }

    return (
        <div className="flex flex-col gap-1">
            <span>{formatDate(expiresAt.slice(0, 10))}</span>
            {expiryBadge(expiresAt)}
        </div>
    );
}

export const downPaymentColumns: DataTableColumn<CollectionDownPaymentRow>[] = [
    { id: 'customer', header: 'Cliente', mobile: customerMobile(), cell: (row) => <CustomerCell customer={row.customer} /> },
    { id: 'layaway', header: 'Apartado', mobile: { subtitle: (row) => row.layaway.code }, cell: (row) => <LayawayLink layaway={row.layaway} /> },
    { id: 'product', header: 'Producto', mobile: 'subtitle', cell: (row) => row.productName },
    {
        id: 'expires',
        header: 'Vence',
        mobile: {
            status: (row) => expiryBadge(row.expiresAt),
            meta: (row) => dueDateMeta(row.expiresAt),
        },
        cell: (row) => <ExpiryCell expiresAt={row.expiresAt} />,
    },
    {
        id: 'downPayment',
        header: 'Inicial',
        align: 'end',
        mobile: 'hidden',
        cell: (row) => <MoneyLabel money={row.downPayment} className="text-ink-2" compact />,
    },
    {
        id: 'missing',
        header: 'Falta',
        align: 'end',
        mobile: 'amount',
        cell: (row) => <MoneyLabel money={row.missing} className="font-semibold" compact />,
    },
    {
        id: 'actions',
        header: 'Acciones',
        align: 'end',
        mobile: 'hidden',
        cell: (row) => (
            <span className="flex items-center justify-end gap-1.5">
                <Contact customer={row.customer} />
                <Link href={row.layaway.url} className="btn btn-secondary btn-sm">
                    Registrar pago
                </Link>
            </span>
        ),
    },
];
