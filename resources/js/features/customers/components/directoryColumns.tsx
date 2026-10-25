import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { type CustomerDirectoryRow } from '@/types/customers';
import { type DataTableColumn } from '@/types/data-table';
import { cilLink } from '@coreui/icons';
import { Link } from '@inertiajs/react';

export const directoryColumns: DataTableColumn<CustomerDirectoryRow>[] = [
    {
        id: 'name',
        header: 'Cliente',
        primary: true,
        cell: (row) => (
            <div className="flex items-center gap-3">
                <Avatar name={row.name} size={32} tone={row.inArrears ? 'neutral' : 'accent'} />
                <div className="flex min-w-0 flex-col items-start gap-0.5">
                    <span className="flex items-center gap-1.5">
                        <Link href={row.url} className="font-semibold hover:underline">
                            {row.name}
                        </Link>
                        {row.hasLinkedAccount ? (
                            <span className="text-ink-3" title="Cliente con cuenta Apartar vinculada">
                                <Icon icon={cilLink} size={12} />
                                <span className="sr-only">Cuenta vinculada</span>
                            </span>
                        ) : null}
                    </span>
                    <span className="text-ink-3 text-xs">{row.document}</span>
                    {row.inArrears ? <Badge tone="error">En mora</Badge> : null}
                </div>
            </div>
        ),
    },
    {
        id: 'phone',
        header: 'Teléfono',
        cell: (row) => (
            <a href={`tel:${row.phone}`} className="text-ink-2 whitespace-nowrap hover:underline">
                {row.phoneLabel}
            </a>
        ),
    },
    { id: 'active', header: 'Activos', align: 'end', cell: (row) => row.activeLayaways },
    { id: 'committed', header: 'Comprometido', align: 'end', cell: (row) => <MoneyLabel money={row.committed} className="text-ink-2" compact /> },
    { id: 'paid', header: 'Pagado', align: 'end', cell: (row) => <MoneyLabel money={row.paid} className="text-success" compact /> },
    { id: 'remaining', header: 'Pendiente', align: 'end', cell: (row) => <MoneyLabel money={row.remaining} className="font-bold" compact /> },
    {
        id: 'overdue',
        header: 'Cuotas vencidas',
        align: 'end',
        cell: (row) =>
            row.overdueInstallments > 0 ? (
                <Badge tone="error">
                    {row.overdueInstallments} {row.overdueInstallments === 1 ? 'vencida' : 'vencidas'}
                </Badge>
            ) : (
                <span className="text-ink-3">—</span>
            ),
    },
    {
        id: 'next',
        header: 'Próximo vencimiento',
        cell: (row) => (row.nextDueDate === null ? <span className="text-ink-3">—</span> : formatDay(row.nextDueDate)),
    },
];
