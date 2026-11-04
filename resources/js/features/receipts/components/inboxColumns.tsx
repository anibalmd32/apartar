import { Badge } from '@/components/ui/badge';
import { formatAge, formatDeclaredAmount } from '@/features/receipts/lib/inbox-format';
import { formatDate } from '@/lib/dates';
import { formatAccountIdentifier, formatDocumentNumber } from '@/lib/identity-format';
import { RECEIPT_TONES as STATUS_TONES } from '@/lib/status-tones';
import { type DataTableColumn } from '@/types/data-table';
import { type ReceiptInboxRow } from '@/types/receipt';
import { Link } from '@inertiajs/react';

function Arrival({ row, now }: { row: ReceiptInboxRow; now: number }) {
    const age = formatAge(row.submittedAt, now);

    return row.overdue ? (
        <Badge tone="warning">
            <span>{age}</span>
            <span className="sr-only">Atrasado</span>
        </Badge>
    ) : (
        <span className="text-ink-2 whitespace-nowrap">{age}</span>
    );
}

function CustomerCell({ row }: { row: ReceiptInboxRow }) {
    return (
        <div className="flex min-w-0 items-center gap-3">
            <FileCell row={row} />
            <div className="flex min-w-0 flex-col items-start gap-0.5">
                <span className="font-semibold">{row.customer?.fullName ?? 'Sin cliente'}</span>
                <span className="text-ink-3 flex flex-wrap items-center gap-x-1.5 text-xs">
                    {row.layaway === null ? null : (
                        <Link href={row.layaway.url} className="hit-area font-semibold whitespace-nowrap tabular-nums hover:underline">
                            {row.layaway.code}
                        </Link>
                    )}
                    {row.customer === null ? null : (
                        <>
                            <span aria-hidden="true">·</span>
                            <span className="whitespace-nowrap">{formatDocumentNumber(row.customer.documentNumber)}</span>
                        </>
                    )}
                </span>
                {row.layaway?.cancelled ? (
                    <Badge tone="neutral" outline>
                        Apartado cancelado
                    </Badge>
                ) : null}
            </div>
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
            <span className="text-ink-3 text-xs whitespace-nowrap">{formatAccountIdentifier(account)}</span>
            {account.isActive ? null : (
                <Badge tone="warning" outline>
                    Cuenta desactivada
                </Badge>
            )}
        </div>
    );
}

function Thumbnail({ row }: { row: ReceiptInboxRow }) {
    return row.file.kind === 'pdf' ? (
        <span className="bg-base-300 text-ink-2 flex h-11 w-9 items-center justify-center rounded-[7px] text-[10px] font-bold">PDF</span>
    ) : (
        <img src={row.file.url} alt="" loading="lazy" className="bg-base-300 h-11 w-9 rounded-[7px] object-cover" />
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
        <a href={row.file.url} target="_blank" rel="noopener noreferrer" aria-label={label} className="touch:min-w-11 flex shrink-0 justify-center">
            <img src={row.file.url} alt="" loading="lazy" className="bg-base-300 h-11 w-9 rounded-[7px] object-cover" />
        </a>
    );
}

export function inboxColumns(now: number): DataTableColumn<ReceiptInboxRow>[] {
    return [
        {
            id: 'customer',
            header: 'Cliente y apartado',
            cell: (row) => <CustomerCell row={row} />,
            mobile: {
                media: (row) => <Thumbnail row={row} />,
                title: (row) => row.customer?.fullName ?? 'Sin cliente',
                subtitle: (row) => (row.layaway === null ? null : `${row.layaway.code}${row.layaway.cancelled ? ' (cancelado)' : ''}`),
            },
        },
        { id: 'payment', header: 'Método', cell: (row) => <PaymentCell row={row} />, mobile: { subtitle: (row) => row.methodLabel } },
        { id: 'account', header: 'Cuenta declarada', cell: (row) => <AccountCell row={row} />, mobile: 'hidden' },
        {
            id: 'amount',
            header: 'Monto declarado',
            cell: (row) => <span className="font-semibold whitespace-nowrap">{formatDeclaredAmount(row.declaredAmount, row.declaredCurrency)}</span>,
            mobile: { amount: (row) => formatDeclaredAmount(row.declaredAmount, row.declaredCurrency) },
        },
        {
            id: 'paidAt',
            header: 'Pagado el',
            cell: (row) => <span className="whitespace-nowrap">{formatDate(row.paidAt)}</span>,
            sortKey: 'paid_at',
            mobile: 'hidden',
        },
        { id: 'arrival', header: 'Recibido', cell: (row) => <Arrival row={row} now={now} />, sortKey: 'submitted', mobile: 'meta' },
        {
            id: 'status',
            header: 'Estado',
            cell: (row) => (
                <div className="flex items-center justify-end gap-3">
                    <Badge tone={STATUS_TONES[row.status]}>{row.statusLabel}</Badge>
                    <Link href={row.reviewUrl} className={row.status === 'pending_review' ? 'btn btn-primary btn-sm' : 'btn btn-ghost btn-sm'}>
                        {row.status === 'pending_review' ? 'Revisar' : 'Ver'}
                    </Link>
                </div>
            ),
            mobile: { status: (row) => <Badge tone={STATUS_TONES[row.status]}>{row.statusLabel}</Badge> },
            align: 'end',
        },
    ];
}
