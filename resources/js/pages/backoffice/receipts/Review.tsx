import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { DecisionBar, DecisionPanel, DecisionStatus } from '@/features/receipts/components/DecisionPanel';
import { PaymentHistory } from '@/features/receipts/components/PaymentHistory';
import { CustomerCard, DeclaredCard, ReceiptChain, ReceiptViewer } from '@/features/receipts/components/ReviewCards';
import { ReviewSchedule } from '@/features/receipts/components/ReviewSchedule';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { RECEIPT_TONES as STATUS_TONES } from '@/lib/status-tones';
import { type ReceiptReviewProps } from '@/types/receipt';
import { cilChevronLeft, cilChevronRight } from '@coreui/icons';
import { Link } from '@inertiajs/react';

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
            <ReviewBody key={receipt.id} {...props} />
        </BackofficeLayout>
    );
}

function ReviewBody(props: ReceiptReviewProps) {
    const { receipt, customer, layaway } = props;

    return (
        <>
            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_420px] lg:gap-6">
                <div className="flex min-w-0 flex-col gap-4">
                    <DecisionStatus receipt={receipt} layaway={layaway} approval={props.approval} />
                    <ReceiptChain receipt={receipt} />
                    <ReceiptViewer receipt={receipt} />
                    <DeclaredCard receipt={receipt} />
                    <ReviewSchedule layaway={props.layaway} installments={props.installments} />
                    <SectionCard title="Pagos previos">
                        <PaymentHistory payments={props.payments} empty="Este apartado todavía no tiene pagos." />
                    </SectionCard>
                </div>

                <div className="flex flex-col gap-4 lg:sticky lg:top-24">
                    <CustomerCard customer={customer} layaway={layaway} />
                    <DecisionPanel {...props} />
                </div>
            </div>
            <DecisionBar {...props} />
        </>
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
