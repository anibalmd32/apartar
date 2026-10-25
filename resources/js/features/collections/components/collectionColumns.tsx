import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { type CollectionCustomer, type CollectionInstallmentRow, type CollectionLayawayRow } from '@/types/collections';
import { type DataTableColumn } from '@/types/data-table';
import { cilChatBubble, cilCheckAlt, cilCopy, cilUser } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

function CustomerCell({ customer }: { customer: CollectionCustomer | null }) {
    const [copied, setCopied] = useState(false);

    if (customer === null) {
        return <span className="text-ink-3">—</span>;
    }

    const copy = () => {
        void navigator.clipboard?.writeText(customer.phone).then(() => setCopied(true));
    };

    return (
        <div className="flex items-center gap-3">
            <Avatar name={customer.name} size={32} tone="accent" />
            <div className="flex min-w-0 flex-col">
                <span className="font-semibold">{customer.name}</span>
                <span className="text-ink-3 flex items-center gap-1.5 text-xs">
                    <a href={`tel:${customer.phone}`} className="hover:text-base-content">
                        {customer.phoneLabel}
                    </a>
                    <button
                        type="button"
                        className="hover:text-base-content rounded p-0.5"
                        onClick={copy}
                        aria-label={`Copiar el teléfono de ${customer.name}`}
                        title={copied ? 'Copiado' : 'Copiar'}
                    >
                        <Icon icon={copied ? cilCheckAlt : cilCopy} size={12} />
                    </button>
                </span>
            </div>
        </div>
    );
}

function LayawayLink({ layaway }: { layaway: { code: string; url: string } }) {
    return (
        <Link href={layaway.url} className="text-primary-ink text-[13px] font-bold whitespace-nowrap hover:underline">
            {layaway.code}
        </Link>
    );
}

function DueBadge({ row }: { row: CollectionInstallmentRow }) {
    if (row.daysLate > 0) {
        return <Badge tone="error">{row.daysLate === 1 ? '1 día de atraso' : `${row.daysLate} días de atraso`}</Badge>;
    }

    const today = new Date();
    const start = Date.UTC(today.getFullYear(), today.getMonth(), today.getDate());
    const [year = 0, month = 1, day = 1] = row.dueDate.split('-').map(Number);
    const days = Math.round((Date.UTC(year, month - 1, day) - start) / 86_400_000);

    if (days <= 0) {
        return <Badge tone="warning">Vence hoy</Badge>;
    }

    return days === 1 ? <Badge tone="warning">Mañana</Badge> : <Badge tone="neutral">{`En ${days} días`}</Badge>;
}

function Contact({ customer }: { customer: CollectionCustomer | null }) {
    if (customer === null) {
        return null;
    }

    return (
        <span className="flex justify-end gap-1.5">
            {customer.chatUrl ? (
                <Link href={customer.chatUrl} className="btn btn-sm btn-square" aria-label={`Escribir a ${customer.name}`} title="Abrir el chat">
                    <Icon icon={cilChatBubble} size={14} />
                </Link>
            ) : null}
            {customer.url ? (
                <Link href={customer.url} className="btn btn-sm btn-square" aria-label={`Ficha de ${customer.name}`} title="Ver la ficha">
                    <Icon icon={cilUser} size={14} />
                </Link>
            ) : null}
        </span>
    );
}

export const installmentColumns: DataTableColumn<CollectionInstallmentRow>[] = [
    { id: 'customer', header: 'Cliente', primary: true, cell: (row) => <CustomerCell customer={row.customer} /> },
    { id: 'layaway', header: 'Apartado', cell: (row) => <LayawayLink layaway={row.layaway} /> },
    { id: 'number', header: 'Cuota', cell: (row) => <span className="whitespace-nowrap">{row.of ? `${row.number} de ${row.of}` : row.number}</span> },
    {
        id: 'due',
        header: 'Vence',
        cell: (row) => (
            <div className="flex flex-col gap-1">
                <span>{formatDay(row.dueDate)}</span>
                <DueBadge row={row} />
            </div>
        ),
    },
    { id: 'amount', header: 'Importe', align: 'end', cell: (row) => <MoneyLabel money={row.amount} className="font-semibold" compact /> },
    { id: 'paid', header: 'Pagado', align: 'end', cell: (row) => <MoneyLabel money={row.paid} className="text-ink-2" compact /> },
    {
        id: 'actions',
        header: 'Acciones',
        align: 'end',
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
    { id: 'customer', header: 'Cliente', primary: true, cell: (row) => <CustomerCell customer={row.customer} /> },
    { id: 'layaway', header: 'Apartado', cell: (row) => <LayawayLink layaway={row.layaway} /> },
    { id: 'product', header: 'Producto', cell: (row) => row.productName },
    { id: 'completed', header: 'Pagado el', cell: (row) => (row.completedAt === null ? '—' : formatDay(row.completedAt)) },
    { id: 'total', header: 'Total', align: 'end', cell: (row) => <MoneyLabel money={row.total} className="font-semibold" compact /> },
    {
        id: 'actions',
        header: 'Acciones',
        align: 'end',
        cell: (row) => (
            <Link href={row.layaway.url} className="btn btn-secondary btn-sm">
                Marcar entrega
            </Link>
        ),
    },
];
