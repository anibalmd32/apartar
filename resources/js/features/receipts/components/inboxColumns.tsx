import { Badge, type BadgeTone } from '@/components/ui/badge';
import { formatAge, formatDay, formatDeclaredAmount } from '@/features/receipts/lib/inbox-format';
import { formatDocumentNumber } from '@/lib/identity-format';
import { type DataTableColumn } from '@/types/data-table';
import { type ReceiptInboxRow, type ReceiptStatus } from '@/types/receipt';
import { Link } from '@inertiajs/react';

const STATUS_TONES: Readonly<Record<ReceiptStatus, BadgeTone>> = {
    pending_review: 'info',
    approved: 'success',
    rejected: 'neutral',
};

function Arrival({ row, now }: { row: ReceiptInboxRow; now: number }) {
    const age = formatAge(row.submittedAt, now);

    return row.overdue ? (
        <Badge tone="error">
            <span>{age}</span>
            <span className="sr-only">Atrasado</span>
        </Badge>
    ) : (
        <span className="text-ink-2 whitespace-nowrap">{age}</span>
    );
}

function LayawayCell({ row }: { row: ReceiptInboxRow }) {
    return (
        <div className="flex items-center gap-3">
            <FileCell row={row} />
            {row.layaway === null ? (
                <span className="text-ink-3">—</span>
            ) : (
                <div className="flex flex-col items-start gap-1">
                    <Link href={row.layaway.url} className="font-mono text-[13px] font-semibold whitespace-nowrap hover:underline">
                        {row.layaway.code}
                    </Link>
                    {row.layaway.cancelled ? (
                        <Badge tone="neutral" outline>
                            Apartado cancelado
                        </Badge>
                    ) : null}
                </div>
            )}
        </div>
    );
}

function CustomerCell({ row }: { row: ReceiptInboxRow }) {
    if (row.customer === null) {
        return <span className="text-ink-3">—</span>;
    }

    return (
        <div className="flex flex-col gap-0.5">
            <span className="font-medium">{row.customer.fullName}</span>
            <span className="text-ink-3 text-xs">{formatDocumentNumber(row.customer.documentNumber)}</span>
        </div>
    );
}

function PaymentCell({ row }: { row: ReceiptInboxRow }) {
    return (
        <div className="flex flex-col gap-0.5">
            <span className="font-medium">{row.methodLabel}</span>
            {row.bank !== null ? (
                <span className="text-ink-3 text-xs">
                    {row.bank} · Ref. {row.reference}
                </span>
            ) : null}
        </div>
    );
}

function AccountCell({ row }: { row: ReceiptInboxRow }) {
    const account = row.paymentAccount;

    if (account === null) {
        return <span className="text-ink-3 text-xs">Sin indicar</span>;
    }

    return (
        <div className="flex flex-col items-start gap-0.5">
            <span>
                {account.methodLabel}
                {account.bank !== null ? ` · ${account.bank}` : ''}
            </span>
            <span className="text-ink-3 text-xs">{account.identifier}</span>
            {account.isActive ? null : (
                <Badge tone="warning" outline>
                    Cuenta desactivada
                </Badge>
            )}
        </div>
    );
}

function FileCell({ row }: { row: ReceiptInboxRow }) {
    const label = `Abrir el comprobante de ${row.layaway?.code ?? 'este apartado'}`;

    if (row.file.kind === 'pdf') {
        return (
            <a
                href={row.file.url}
                target="_blank"
                rel="noopener noreferrer"
                className="bg-base-300 text-ink-2 hover:text-base-content flex h-11 w-9 shrink-0 items-center justify-center rounded-[7px]"
                aria-label={label}
            >
                <span aria-hidden="true" className="text-[10px] font-bold">
                    PDF
                </span>
            </a>
        );
    }

    return (
        <a href={row.file.url} target="_blank" rel="noopener noreferrer" aria-label={label} className="shrink-0">
            <img src={row.file.url} alt="" loading="lazy" className="bg-base-300 h-11 w-9 rounded-[7px] object-cover" />
        </a>
    );
}

export function inboxColumns(now: number): DataTableColumn<ReceiptInboxRow>[] {
    return [
        { id: 'layaway', header: 'Comprobante', cell: (row) => <LayawayCell row={row} />, primary: true },
        { id: 'customer', header: 'Cliente', cell: (row) => <CustomerCell row={row} />, primary: true },
        { id: 'payment', header: 'Método', cell: (row) => <PaymentCell row={row} /> },
        { id: 'account', header: 'Cuenta declarada', cell: (row) => <AccountCell row={row} /> },
        {
            id: 'amount',
            header: 'Monto declarado',
            cell: (row) => <span className="font-semibold whitespace-nowrap">{formatDeclaredAmount(row.declaredAmount, row.declaredCurrency)}</span>,
            primary: true,
        },
        { id: 'paidAt', header: 'Pagado el', cell: (row) => <span className="whitespace-nowrap">{formatDay(row.paidAt)}</span>, sortKey: 'paid_at' },
        { id: 'arrival', header: 'Recibido', cell: (row) => <Arrival row={row} now={now} />, sortKey: 'submitted', primary: true },
        {
            id: 'status',
            header: 'Estado',
            cell: (row) => (
                <div className="flex items-center justify-end gap-3">
                    <Badge tone={STATUS_TONES[row.status]}>{row.statusLabel}</Badge>
                    <Link href={row.reviewUrl} className={row.status === 'pending_review' ? 'btn btn-secondary btn-sm' : 'btn btn-ghost btn-sm'}>
                        {row.status === 'pending_review' ? 'Revisar' : 'Ver'}
                    </Link>
                </div>
            ),
            primary: true,
            align: 'end',
        },
    ];
}
