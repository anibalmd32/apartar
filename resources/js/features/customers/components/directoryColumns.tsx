import { Avatar } from '@/components/shell/avatar';
import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { NO_DOCUMENT, NO_PHONE } from '@/lib/identity-format';
import { type CustomerDirectoryRow } from '@/types/customers';
import { type DataTableColumn } from '@/types/data-table';
import { cilLink } from '@coreui/icons';
import { Link } from '@inertiajs/react';

export const directoryColumns: DataTableColumn<CustomerDirectoryRow>[] = [
    {
        id: 'name',
        header: 'Cliente',
        mobile: {
            media: (row) => <Avatar name={row.name} size={40} tone={row.inArrears ? 'neutral' : 'accent'} />,
            title: (row) => row.name,
            subtitle: (row) => row.document ?? NO_DOCUMENT,
            status: (row) => (row.inArrears && row.overdueInstallments === 0 ? <Badge tone="error">En mora</Badge> : null),
        },
        cell: (row) => (
            <div className="flex items-center gap-3">
                <Avatar name={row.name} size={32} tone={row.inArrears ? 'neutral' : 'accent'} />
                <div className="flex min-w-0 flex-col items-start gap-0.5">
                    <span className="flex items-center gap-1.5">
                        <Link href={row.url} className="hit-area font-semibold hover:underline">
                            {row.name}
                        </Link>
                        {row.hasLinkedAccount ? (
                            <span className="text-ink-3" title="Cliente con cuenta Apartar vinculada">
                                <Icon icon={cilLink} size={12} />
                                <span className="sr-only">Cuenta vinculada</span>
                            </span>
                        ) : null}
                    </span>
                    <span className="text-ink-3 text-xs">{row.document ?? NO_DOCUMENT}</span>
                    {row.inArrears ? <Badge tone="error">En mora</Badge> : null}
                </div>
            </div>
        ),
    },
    {
        id: 'phone',
        header: 'Teléfono',
        mobile: 'hidden',
        cell: (row) =>
            row.phone === null ? (
                <span className="text-ink-3 whitespace-nowrap">{NO_PHONE}</span>
            ) : (
                <a href={`tel:${row.phone}`} className="hit-area text-ink-2 whitespace-nowrap hover:underline">
                    {row.phoneLabel}
                </a>
            ),
    },
    {
        id: 'active',
        header: 'Activos',
        align: 'end',
        mobile: 'hidden',
        cell: (row) => row.activeLayaways,
    },
    {
        id: 'committed',
        header: 'Comprometido',
        align: 'end',
        mobile: 'hidden',
        cell: (row) => <MoneyLabel money={row.committed} className="text-ink-2" compact />,
    },
    { id: 'paid', header: 'Pagado', align: 'end', mobile: 'hidden', cell: (row) => <MoneyLabel money={row.paid} className="text-success" compact /> },
    {
        id: 'remaining',
        header: 'Pendiente',
        align: 'end',
        mobile: { amount: (row) => (Number(row.remaining.cents) === 0 ? null : <MoneyLabel money={row.remaining} compact />) },
        cell: (row) => <MoneyLabel money={row.remaining} className="font-bold" compact />,
    },
    {
        id: 'overdue',
        header: 'Cuotas vencidas',
        align: 'end',
        mobile: {
            status: (row) =>
                row.overdueInstallments > 0 ? (
                    <Badge tone="error">{row.overdueInstallments === 1 ? '1 cuota vencida' : `${row.overdueInstallments} cuotas vencidas`}</Badge>
                ) : null,
        },
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
        mobile: { meta: (row) => (row.nextDueDate === null ? null : `Vence el ${formatDay(row.nextDueDate)}`) },
        cell: (row) => (row.nextDueDate === null ? <span className="text-ink-3">—</span> : formatDay(row.nextDueDate)),
    },
];
