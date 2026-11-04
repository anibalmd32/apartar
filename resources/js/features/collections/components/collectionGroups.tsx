import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { AmountProgress } from '@/features/collections/components/AmountProgress';
import { Contact, CustomerCell, LayawayLink } from '@/features/collections/components/collectionCells';
import { DueBadge, customerMobile } from '@/features/collections/components/collectionMobile';
import { DeliveryCell } from '@/features/collections/components/DeliveryCell';
import { WhatsAppReminder } from '@/features/collections/components/WhatsAppReminder';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { formatDate } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { type CollectionCustomerRow, type CollectionInstallmentRow } from '@/types/collections';
import { type DataTableColumn } from '@/types/data-table';
import { cilChevronBottom } from '@coreui/icons';

function Toggle({ row, open, onToggle }: { row: CollectionCustomerRow; open: boolean; onToggle: (id: number) => void }) {
    return (
        <button
            type="button"
            className="btn btn-ghost btn-sm btn-square"
            aria-expanded={open}
            aria-label={`${open ? 'Ocultar' : 'Ver'} las cuotas de ${row.customer?.name ?? 'este cliente'}`}
            onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onToggle(row.id);
            }}
        >
            <Icon icon={cilChevronBottom} size={14} className={cn('transition-transform', open && 'rotate-180')} />
        </button>
    );
}

function lateness(row: CollectionCustomerRow) {
    return row.daysLate > 0 ? (
        <Badge tone="error">{`${row.daysLate} ${row.daysLate === 1 ? 'día' : 'días'} de atraso`}</Badge>
    ) : (
        <Badge tone="neutral">Al día</Badge>
    );
}

function countLabel(row: CollectionCustomerRow): string {
    const installments = `${row.count} ${row.count === 1 ? 'cuota' : 'cuotas'}`;

    return row.layaways > 1 ? `${installments} en ${row.layaways} apartados` : installments;
}

export function customerColumns(isOpen: (id: number) => boolean, onToggle: (id: number) => void): DataTableColumn<CollectionCustomerRow>[] {
    return [
        {
            id: 'customer',
            header: 'Cliente',
            mobile: customerMobile(),
            cell: (row) => (
                <span className="flex items-center gap-2">
                    <Toggle row={row} open={isOpen(row.id)} onToggle={onToggle} />
                    <CustomerCell customer={row.customer} />
                </span>
            ),
        },
        { id: 'count', header: 'Por cobrar', mobile: { subtitle: countLabel }, cell: countLabel },
        {
            id: 'progress',
            header: 'Avance',
            mobile: { meta: (row) => <AmountProgress paid={row.paid} total={row.total} progress={row.progress} /> },
            cell: (row) => <AmountProgress paid={row.paid} total={row.total} progress={row.progress} />,
        },
        { id: 'late', header: 'Atraso', mobile: { status: lateness }, cell: lateness },
        {
            id: 'pending',
            header: 'Pendiente',
            align: 'end',
            mobile: 'amount',
            cell: (row) => <MoneyLabel money={row.pending} className="font-semibold" compact />,
        },
        {
            id: 'actions',
            header: 'Acciones',
            align: 'end',
            mobile: { action: (row) => <Toggle row={row} open={isOpen(row.id)} onToggle={onToggle} /> },
            cell: (row) => (
                <span className="flex items-center justify-end gap-1.5">
                    {row.daysLate > 0 ? (
                        <WhatsAppReminder customer={row.customer} dueCents={Number(row.pending.cents)} daysLate={row.daysLate} />
                    ) : null}
                    <Contact customer={row.customer} />
                </span>
            ),
        },
    ];
}

export function CustomerInstallments({ rows }: { rows: readonly CollectionInstallmentRow[] }) {
    return (
        <ul className="divide-line flex flex-col divide-y px-3 py-1 md:pl-14" aria-label="Cuotas del cliente">
            {rows.map((row) => (
                <li key={row.id} className="flex flex-wrap items-center gap-x-4 gap-y-1.5 py-2.5 text-[13px]">
                    <LayawayLink layaway={row.layaway} />
                    <span className="text-ink-2 min-w-0 flex-1 truncate">{row.productName}</span>
                    <span className="text-ink-3 whitespace-nowrap">{row.of ? `Cuota ${row.number} de ${row.of}` : `Cuota ${row.number}`}</span>
                    <span className="flex items-center gap-2 whitespace-nowrap">
                        {formatDate(row.dueDate)}
                        <DueBadge row={row} />
                    </span>
                    <MoneyLabel money={row.pending} className="font-semibold" compact />
                    <DeliveryCell delivery={row.delivery} code={row.layaway.code} />
                </li>
            ))}
        </ul>
    );
}
