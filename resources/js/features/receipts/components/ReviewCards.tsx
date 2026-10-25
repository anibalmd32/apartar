import { Avatar } from '@/components/shell/avatar';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { formatDocumentNumber, formatPhone } from '@/lib/identity-format';
import { type ReceiptReviewProps } from '@/types/receipt';
import { cilCommentSquare } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

export { ReceiptViewer } from '@/features/receipts/components/ReceiptViewer';

function Datum({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-0.5 py-2">
            <dt className="text-ink-3 text-xs">{label}</dt>
            <dd className="text-sm font-semibold">{children}</dd>
        </div>
    );
}

export function DeclaredCard({ receipt }: Pick<ReceiptReviewProps, 'receipt'>) {
    const account = receipt.paymentAccount;

    return (
        <SectionCard title="Lo que declaró el cliente" action={<span className="text-ink-3 font-normal">Llegó {receipt.submittedAt}</span>}>
            <dl className="grid grid-cols-2 gap-x-4 sm:grid-cols-3">
                <Datum label="Método">{receipt.method}</Datum>
                <Datum label="Banco y referencia">{receipt.bank !== null ? `${receipt.bank} · Ref. ${receipt.reference}` : '—'}</Datum>
                <Datum label="Pagado el">{formatDay(receipt.paidAt)}</Datum>
                <Datum label="Monto">{receipt.declared}</Datum>
                <div className="col-span-2 flex flex-col gap-0.5 py-2">
                    <dt className="text-ink-3 text-xs">Cuenta destino</dt>
                    <dd className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                        {account === null
                            ? 'Sin indicar'
                            : `${account.methodLabel}${account.bank !== null ? ` · ${account.bank}` : ''} · ${account.identifier}`}
                        {account !== null && !account.isActive ? (
                            <Badge tone="warning" outline>
                                Cuenta desactivada
                            </Badge>
                        ) : null}
                    </dd>
                </div>
            </dl>
            {receipt.note !== null ? (
                <p className="bg-surface-2 text-ink-2 flex items-start gap-2.5 rounded-[10px] p-3 text-[13px]">
                    <Icon icon={cilCommentSquare} className="mt-0.5" />
                    <span>«{receipt.note}»</span>
                </p>
            ) : null}
        </SectionCard>
    );
}

export function ReceiptChain({ receipt }: Pick<ReceiptReviewProps, 'receipt'>) {
    return (
        <>
            {receipt.replacedBy !== null ? (
                <Alert tone="info">
                    Lo sustituyó el{' '}
                    <Link href={receipt.replacedBy.url} className="link">
                        comprobante del {receipt.replacedBy.submittedAt}
                    </Link>
                    .
                </Alert>
            ) : null}
            {receipt.replaces !== null ? (
                <Alert tone="info" title="Reintento de un comprobante rechazado">
                    <Link href={receipt.replaces.url} className="link">
                        Comprobante del {receipt.replaces.submittedAt}
                    </Link>
                    {receipt.replaces.rejectionReason !== null ? ` · Motivo: ${receipt.replaces.rejectionReason}` : ''}
                </Alert>
            ) : null}
        </>
    );
}

export function CustomerCard({ customer, layaway }: Pick<ReceiptReviewProps, 'customer' | 'layaway'>) {
    const total = Number(layaway.total.cents);
    const paid = Number(layaway.paid.cents);
    const percent = total > 0 ? Math.min(100, (paid / total) * 100) : 0;

    return (
        <section className="bg-base-100 border-line shadow-card flex flex-col gap-3.5 rounded-2xl border p-5" aria-label="Cliente y apartado">
            {customer !== null ? (
                <div className="flex items-center gap-3">
                    <Avatar name={customer.fullName} size={44} tone="accent" />
                    <div className="min-w-0">
                        <p className="truncate text-[15px] font-bold">{customer.fullName}</p>
                        <p className="text-ink-3 truncate text-xs">
                            {formatDocumentNumber(customer.documentNumber)} · {formatPhone(customer.phone)}
                        </p>
                    </div>
                </div>
            ) : null}
            <div className="bg-surface-2 flex flex-col gap-2.5 rounded-xl p-3.5">
                <div className="flex items-center justify-between gap-2">
                    <Link href={layaway.url} className="font-mono text-[13px] font-bold hover:underline">
                        {layaway.code}
                    </Link>
                    <Badge tone="neutral">{layaway.statusLabel}</Badge>
                </div>
                <p className="text-ink-2 text-[13px]">{layaway.productName}</p>
                <span className="bg-base-300 block h-1.5 overflow-hidden rounded-full" aria-hidden="true">
                    <span className="bg-success block h-full rounded-full" style={{ width: `${percent}%` }} />
                </span>
                <p className="text-ink-3 flex flex-wrap justify-between gap-2 text-xs">
                    <span>
                        Pagado <MoneyLabel money={layaway.paid} /> de <MoneyLabel money={layaway.total} />
                    </span>
                    <span className="text-base-content font-bold">
                        Resta <MoneyLabel money={layaway.remaining} />
                    </span>
                </p>
            </div>
        </section>
    );
}
