import { Badge, type BadgeTone } from '@/components/ui/badge';
import { IconChip } from '@/components/ui/icon-chip';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { type ReceiptRow, type ReceiptStatus } from '@/types/receipt';
import { cilCheckCircle, cilFile, cilXCircle } from '@coreui/icons';

const TONES: Readonly<Record<ReceiptStatus, BadgeTone>> = {
    pending_review: 'warning',
    approved: 'success',
    rejected: 'error',
};

const ICONS: Readonly<Record<ReceiptStatus, string[]>> = {
    pending_review: cilFile,
    approved: cilCheckCircle,
    rejected: cilXCircle,
};

interface ReceiptHistoryProps {
    receipts: readonly ReceiptRow[];
}

export function ReceiptHistory({ receipts }: ReceiptHistoryProps) {
    if (receipts.length === 0) {
        return <p className="text-ink-3 text-sm">Todavía no has reportado pagos de este apartado.</p>;
    }

    const dayOf = new Map(receipts.map((receipt) => [receipt.id, receipt.submittedAt]));

    return (
        <ul className="flex flex-col gap-3" aria-label="Comprobantes">
            {receipts.map((receipt) => {
                const replaced = receipt.replacesReceiptId === null ? undefined : dayOf.get(receipt.replacesReceiptId);

                return (
                    <li key={receipt.id} className="flex items-start gap-3 text-sm">
                        <IconChip icon={ICONS[receipt.status]} tone={TONES[receipt.status]} size={34} />
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-semibold">{receipt.declaredAmount}</span>
                                <Badge tone={TONES[receipt.status]}>{receipt.statusLabel}</Badge>
                            </div>
                            <p className="text-ink-3 text-xs">
                                {formatDay(receipt.submittedAt)} · {receipt.method}
                                {receipt.reference !== null ? ` · Ref. ${receipt.reference}` : ''}
                            </p>
                            {receipt.paidToAccount !== null ? <p className="text-ink-3 text-xs">Pagado a {receipt.paidToAccount}</p> : null}
                            {replaced !== undefined ? (
                                <p className="text-ink-3 text-xs">Reintento del comprobante del {formatDay(replaced)}</p>
                            ) : null}
                            {receipt.rejectionReason !== null ? <p className="text-error text-xs">Motivo: {receipt.rejectionReason}</p> : null}
                            <a
                                href={receipt.fileUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-primary-ink self-start text-xs font-semibold hover:underline"
                            >
                                Ver el comprobante
                            </a>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
