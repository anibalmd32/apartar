import { Badge } from '@/components/ui/badge';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { type CustomerDirectoryRow } from '@/types/customers';
import { type DataTableColumn } from '@/types/data-table';
import { Link } from '@inertiajs/react';

/**
 * Las columnas del directorio (`REQ-BOF-040` RB-2): quién es y cómo se le
 * escribe, cuánto tiene comprometido, pagado y pendiente, sus cuotas vencidas
 * y el próximo vencimiento. Un cliente en mora se destaca (RB-5).
 *
 * De la cuenta vinculada, solo si la hay (RB-8).
 */
export const directoryColumns: DataTableColumn<CustomerDirectoryRow>[] = [
    {
        id: 'name',
        header: 'Cliente',
        primary: true,
        cell: (row) => (
            <div className="flex flex-col gap-1">
                <Link href={row.url} className="link font-medium">
                    {row.name}
                </Link>
                <span className="text-xs opacity-70">{row.document}</span>
                {row.inArrears ? <Badge tone="error">En mora</Badge> : null}
                {row.hasLinkedAccount ? (
                    <Badge tone="neutral" outline>
                        Cuenta vinculada
                    </Badge>
                ) : null}
            </div>
        ),
    },
    {
        id: 'phone',
        header: 'Teléfono',
        cell: (row) => (
            <a href={`tel:${row.phone}`} className="link text-sm">
                {row.phoneLabel}
            </a>
        ),
    },
    { id: 'active', header: 'Apartados activos', align: 'end', cell: (row) => row.activeLayaways },
    { id: 'committed', header: 'Comprometido', align: 'end', cell: (row) => <MoneyLabel money={row.committed} /> },
    { id: 'paid', header: 'Pagado', align: 'end', cell: (row) => <MoneyLabel money={row.paid} /> },
    { id: 'remaining', header: 'Pendiente', align: 'end', cell: (row) => <MoneyLabel money={row.remaining} /> },
    {
        id: 'overdue',
        header: 'Cuotas vencidas',
        align: 'end',
        cell: (row) =>
            row.overdueInstallments > 0 ? <Badge tone="warning">{row.overdueInstallments}</Badge> : <span className="opacity-60">0</span>,
    },
    {
        id: 'next',
        header: 'Próximo vencimiento',
        cell: (row) => (row.nextDueDate === null ? <span className="opacity-60">—</span> : formatDay(row.nextDueDate)),
    },
];
