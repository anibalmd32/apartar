import { Alert } from '@/components/ui/alert';
import { Badge, type BadgeTone } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { ApprovalForm } from '@/features/receipts/components/ApprovalForm';
import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import { RejectForm } from '@/features/receipts/components/RejectForm';
import { CustomerCard, DeclaredCard, ReceiptChain, ReceiptViewer } from '@/features/receipts/components/ReviewCards';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { cn } from '@/lib/utils';
import { type ReceiptReviewProps, type ReceiptStatus } from '@/types/receipt';
import { cilChevronLeft, cilChevronRight } from '@coreui/icons';
import { Link } from '@inertiajs/react';
import { useState } from 'react';

export default function ReceiptReview(props: ReceiptReviewProps) {
    const { receipt, customer, layaway } = props;

    return (
        <BackofficeLayout
            title={`Comprobante de ${customer?.fullName ?? layaway.code}`}
            description={`${layaway.code} · ${receipt.method} · ${receipt.declared}`}
            back={{ href: props.inboxUrl, label: 'Comprobantes' }}
            badge={<Badge tone={STATUS_TONES[receipt.status]}>{receipt.statusLabel}</Badge>}
            actions={props.queue ? <QueueNav queue={props.queue} /> : null}
        >
            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_420px] lg:gap-6">
                <div className="flex min-w-0 flex-col gap-4">
                    <ReceiptChain receipt={receipt} />
                    <ReceiptViewer receipt={receipt} />
                    <DeclaredCard receipt={receipt} />
                    <Schedule {...props} />
                    <SectionCard title="Pagos previos">
                        <PaymentHistory payments={props.payments} empty="Este apartado todavía no tiene pagos." />
                    </SectionCard>
                </div>

                <div className="flex flex-col gap-4 lg:sticky lg:top-24">
                    <CustomerCard customer={customer} layaway={layaway} />
                    <Decision {...props} />
                </div>
            </div>
        </BackofficeLayout>
    );
}

function QueueNav({ queue }: { queue: NonNullable<ReceiptReviewProps['queue']> }) {
    const arrow = (url: string | null, icon: string[], label: string) =>
        url !== null ? (
            <Link href={url} className="btn btn-sm btn-square" aria-label={label}>
                <Icon icon={icon} size={14} />
            </Link>
        ) : (
            <span className="btn btn-sm btn-square btn-disabled" aria-hidden="true">
                <Icon icon={icon} size={14} />
            </span>
        );

    return (
        <nav aria-label="Cola de comprobantes" className="flex items-center gap-2">
            <span className="text-ink-3 text-[13px]">
                {queue.position} de {queue.total}
            </span>
            {arrow(queue.previousUrl, cilChevronLeft, 'Comprobante anterior')}
            {arrow(queue.nextUrl, cilChevronRight, 'Comprobante siguiente')}
        </nav>
    );
}

const STATUS_TONES: Readonly<Record<ReceiptStatus, BadgeTone>> = {
    pending_review: 'warning',
    approved: 'success',
    rejected: 'error',
};

function Decision({ receipt, layaway, rate, preview, previewError, approval, rejection }: ReceiptReviewProps) {
    const [mode, setMode] = useState<'approve' | 'reject'>('approve');

    const status =
        approval !== null ? null : receipt.status === 'pending_review' ? (
            <Alert tone="warning">El apartado está «{layaway.statusLabel}» y ya no admite pagos: este comprobante no se puede aprobar.</Alert>
        ) : (
            <Alert tone={receipt.status === 'approved' ? 'success' : 'info'}>
                {receipt.reviewedBy !== null ? `${receipt.statusLabel} por ${receipt.reviewedBy}.` : `${receipt.statusLabel}.`}
                {receipt.rejectionReason !== null ? ` Motivo: ${receipt.rejectionReason}` : ''}
            </Alert>
        );

    if (approval === null) {
        return (
            <>
                {status}
                {rejection !== null ? (
                    <SectionCard title="Rechazar">
                        <RejectForm rejection={rejection} />
                    </SectionCard>
                ) : null}
            </>
        );
    }

    const tab = (value: 'approve' | 'reject', label: string, enabled: boolean) => (
        <button
            type="button"
            aria-pressed={mode === value}
            disabled={!enabled}
            onClick={() => setMode(value)}
            className={cn(
                'pointer-coarse:h-11 h-[34px] flex-1 rounded-lg text-sm transition-colors disabled:opacity-40',
                mode === value ? 'bg-base-100 dark:bg-base-300 shadow-card font-bold' : 'text-ink-3 hover:text-base-content font-medium',
            )}
        >
            {label}
        </button>
    );

    return (
        <section aria-label="Decisión" className="bg-base-100 border-primary/50 shadow-card flex flex-col gap-3.5 rounded-2xl border-[1.5px] p-5">
            <div role="group" aria-label="Qué hacer con el comprobante" className="bg-surface-2 flex gap-0.5 rounded-[10px] p-[3px]">
                {tab('approve', 'Aprobar', true)}
                {tab('reject', 'Rechazar', rejection !== null)}
            </div>

            {mode === 'approve' ? (
                <ApprovalForm approval={approval} receipt={receipt} rate={rate} initialPreview={preview} initialError={previewError} />
            ) : null}
            {mode === 'reject' && rejection !== null ? <RejectForm rejection={rejection} /> : null}
        </section>
    );
}

function Schedule({ layaway, installments }: ReceiptReviewProps) {
    return (
        <SectionCard title="Cronograma" flush>
            {installments.length === 0 ? (
                <p className="text-ink-2 px-5 pb-5 text-sm">
                    Espera la inicial de <MoneyLabel money={layaway.downPayment} />: el cronograma nace al aprobarla.
                </p>
            ) : (
                <div className="border-line overflow-x-auto border-t">
                    <table className="table">
                        <caption className="sr-only">Cronograma</caption>
                        <thead>
                            <tr>
                                <th scope="col">Cuota</th>
                                <th scope="col">Vence</th>
                                <th scope="col">Pagado</th>
                                <th scope="col">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            {installments.map((installment) => (
                                <tr key={installment.number}>
                                    <td className="font-semibold">{installment.number}</td>
                                    <td>{installment.dueDateLabel}</td>
                                    <td>
                                        <MoneyLabel money={installment.paid} /> de <MoneyLabel money={installment.amount} />
                                    </td>
                                    <td>{installment.statusLabel}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </SectionCard>
    );
}
