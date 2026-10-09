import { Badge } from '@/components/ui/badge';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { type CollectionCustomer, type CollectionInstallmentRow, type CollectionLayawayRow } from '@/types/collections';
import { type DataTableColumn } from '@/types/data-table';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Las columnas de la cartera (`REQ-BOF-042` RB-6): una fila por cuota con el
 * cliente y **su teléfono a la vista** —el canal real de cobro—, el apartado,
 * la cuota, el vencimiento, lo que se debe y los días de atraso, y las
 * acciones rápidas que ya existen: abrir el apartado (registrar el pago desde
 * ahí) y copiar el teléfono. Enviar un aviso es de `T-083` y abrir el chat de
 * `T-080`.
 */

function CustomerCell({ customer }: { customer: CollectionCustomer | null }) {
    const [copied, setCopied] = useState(false);

    if (customer === null) {
        return <span className="opacity-60">—</span>;
    }

    const copy = () => {
        void navigator.clipboard?.writeText(customer.phone).then(() => setCopied(true));
    };

    return (
        <div className="flex flex-col gap-1">
            <span>{customer.name}</span>
            <span className="flex items-center gap-2 text-xs">
                <a href={`tel:${customer.phone}`} className="link opacity-80">
                    {customer.phoneLabel}
                </a>
                <button type="button" className="btn btn-ghost btn-xs" onClick={copy} aria-label={`Copiar el teléfono de ${customer.name}`}>
                    {copied ? 'Copiado' : 'Copiar'}
                </button>
            </span>
        </div>
    );
}

function LayawayLink({ layaway }: { layaway: { code: string; url: string } }) {
    return (
        <Link href={layaway.url} className="link font-mono text-sm">
            {layaway.code}
        </Link>
    );
}

export const installmentColumns: DataTableColumn<CollectionInstallmentRow>[] = [
    { id: 'customer', header: 'Cliente', primary: true, cell: (row) => <CustomerCell customer={row.customer} /> },
    { id: 'layaway', header: 'Apartado', cell: (row) => <LayawayLink layaway={row.layaway} /> },
    { id: 'number', header: 'Cuota', align: 'end', cell: (row) => row.number },
    {
        id: 'due',
        header: 'Vence',
        cell: (row) => (
            <div className="flex flex-col gap-1">
                <span>{formatDay(row.dueDate)}</span>
                {row.daysLate > 0 ? <Badge tone="warning">{row.daysLate === 1 ? '1 día de atraso' : `${row.daysLate} días de atraso`}</Badge> : null}
            </div>
        ),
    },
    { id: 'amount', header: 'Importe', align: 'end', cell: (row) => <MoneyLabel money={row.amount} /> },
    { id: 'paid', header: 'Pagado', align: 'end', cell: (row) => <MoneyLabel money={row.paid} /> },
    {
        id: 'actions',
        header: 'Acciones',
        cell: (row) => (
            <Link href={row.layaway.url} className="btn btn-sm">
                Registrar pago
            </Link>
        ),
    },
];

export const layawayColumns: DataTableColumn<CollectionLayawayRow>[] = [
    { id: 'customer', header: 'Cliente', primary: true, cell: (row) => <CustomerCell customer={row.customer} /> },
    { id: 'layaway', header: 'Apartado', cell: (row) => <LayawayLink layaway={row.layaway} /> },
    { id: 'product', header: 'Producto', cell: (row) => row.productName },
    { id: 'completed', header: 'Pagado el', cell: (row) => (row.completedAt === null ? '—' : formatDay(row.completedAt)) },
    { id: 'total', header: 'Total', align: 'end', cell: (row) => <MoneyLabel money={row.total} /> },
    {
        id: 'actions',
        header: 'Acciones',
        cell: (row) => (
            <Link href={row.layaway.url} className="btn btn-sm">
                Marcar entrega
            </Link>
        ),
    },
];
