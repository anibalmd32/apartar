import { Badge, type BadgeTone } from '@/components/ui/badge';
import { formatAge, formatDay, formatDeclaredAmount } from '@/features/receipts/lib/inbox-format';
import { formatDocumentNumber } from '@/lib/identity-format';
import { type DataTableColumn } from '@/types/data-table';
import { type ReceiptInboxRow, type ReceiptStatus } from '@/types/receipt';
import { Link } from '@inertiajs/react';

/**
 * Las columnas de la bandeja de conciliación (`REQ-PAY-004` RB-5).
 *
 * Lo que RB-5 enumera, en el orden en que se mira un comprobante: cuánto
 * lleva esperando, de qué apartado y de quién, cómo dice que pagó y a qué
 * cuenta, cuánto, qué día, y el archivo. Ordenan las dos fechas, con las
 * claves de `ReceiptInboxSort`; sin orden pedido, la cola FIFO de RB-4. No
 * ordena el monto: viene en dos monedas sin convertir.
 *
 * La marca de atrasado (RB-7) llega resuelta en `overdue`; aquí solo se pinta.
 * La miniatura de un PDF es un icono, sin renderizar la primera página
 * (SP-5): abrirlo es pulsarlo.
 */

const STATUS_TONES: Readonly<Record<ReceiptStatus, BadgeTone>> = {
    pending_review: 'info',
    approved: 'success',
    rejected: 'neutral',
};

function Arrival({ row, now }: { row: ReceiptInboxRow; now: number }) {
    return (
        <div className="flex flex-col gap-1">
            <span className="text-sm">{formatAge(row.submittedAt, now)}</span>
            {row.overdue ? <Badge tone="warning">Atrasado</Badge> : null}
        </div>
    );
}

function LayawayCell({ row }: { row: ReceiptInboxRow }) {
    if (row.layaway === null) {
        return <span className="opacity-60">—</span>;
    }

    return (
        <div className="flex flex-col gap-1">
            <Link href={row.layaway.url} className="link font-mono text-sm">
                {row.layaway.code}
            </Link>
            {row.layaway.cancelled ? (
                <Badge tone="neutral" outline>
                    Apartado cancelado
                </Badge>
            ) : null}
        </div>
    );
}

function CustomerCell({ row }: { row: ReceiptInboxRow }) {
    if (row.customer === null) {
        return <span className="opacity-60">—</span>;
    }

    return (
        <div className="flex flex-col">
            <span>{row.customer.fullName}</span>
            <span className="text-xs opacity-70">{formatDocumentNumber(row.customer.documentNumber)}</span>
        </div>
    );
}

function PaymentCell({ row }: { row: ReceiptInboxRow }) {
    return (
        <div className="flex flex-col">
            <span>{row.methodLabel}</span>
            {row.bank !== null ? (
                <span className="text-xs opacity-70">
                    {row.bank} · Ref. {row.reference}
                </span>
            ) : null}
        </div>
    );
}

/** La cuenta que el cliente dice haber pagado; la desactivada, marcada (SP-8). */
function AccountCell({ row }: { row: ReceiptInboxRow }) {
    const account = row.paymentAccount;

    if (account === null) {
        return <span className="text-xs opacity-60">Sin indicar</span>;
    }

    return (
        <div className="flex flex-col gap-1">
            <span className="text-sm">
                {account.methodLabel}
                {account.bank !== null ? ` · ${account.bank}` : ''}
            </span>
            <span className="text-xs opacity-70">{account.identifier}</span>
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
            <a href={row.file.url} target="_blank" rel="noopener noreferrer" className="btn btn-ghost btn-sm" aria-label={label}>
                <span aria-hidden="true" className="font-mono text-xs">
                    PDF
                </span>
            </a>
        );
    }

    return (
        <a href={row.file.url} target="_blank" rel="noopener noreferrer" aria-label={label}>
            <img src={row.file.url} alt="" loading="lazy" className="bg-base-200 h-12 w-12 rounded object-cover" />
        </a>
    );
}

/**
 * Las columnas, con el «ahora» con el que se cuenta la antigüedad: el del
 * servidor que marcó los atrasados, uno para toda la tabla.
 */
export function inboxColumns(now: number): DataTableColumn<ReceiptInboxRow>[] {
    return [
        { id: 'arrival', header: 'Llegó', cell: (row) => <Arrival row={row} now={now} />, sortKey: 'submitted', primary: true },
        { id: 'layaway', header: 'Apartado', cell: (row) => <LayawayCell row={row} />, primary: true },
        { id: 'customer', header: 'Cliente', cell: (row) => <CustomerCell row={row} />, primary: true },
        { id: 'payment', header: 'Pago', cell: (row) => <PaymentCell row={row} /> },
        { id: 'account', header: 'Cuenta declarada', cell: (row) => <AccountCell row={row} /> },
        {
            id: 'amount',
            header: 'Monto',
            cell: (row) => <span className="font-medium whitespace-nowrap">{formatDeclaredAmount(row.declaredAmount, row.declaredCurrency)}</span>,
            primary: true,
            align: 'end',
        },
        { id: 'paidAt', header: 'Pagó el', cell: (row) => formatDay(row.paidAt), sortKey: 'paid_at' },
        { id: 'status', header: 'Estado', cell: (row) => <Badge tone={STATUS_TONES[row.status]}>{row.statusLabel}</Badge> },
        { id: 'file', header: 'Archivo', cell: (row) => <FileCell row={row} /> },
    ];
}
