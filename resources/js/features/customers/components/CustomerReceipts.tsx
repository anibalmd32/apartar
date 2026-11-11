import { Badge } from '@/components/ui/badge';
import { FileLink } from '@/components/ui/file-link';
import { formatDate } from '@/lib/dates';
import { RECEIPT_TONES } from '@/lib/status-tones';
import { type CustomerReceiptRow } from '@/types/customers';
import { Link } from '@inertiajs/react';

export function CustomerReceipts({ receipts }: { receipts: readonly CustomerReceiptRow[] }) {
    if (receipts.length === 0) {
        return <p className="text-ink-2 text-sm">Este cliente no ha reportado pagos.</p>;
    }

    return (
        <ul className="divide-line divide-y">
            {receipts.map((receipt) => (
                <li key={receipt.id} className="flex flex-col gap-1 py-3 text-sm">
                    <span className="flex flex-wrap gap-2">
                        <Link href={receipt.url} className="font-semibold hover:underline">
                            {receipt.declared}
                        </Link>
                        <span className="text-ink-3">
                            {receipt.submittedAt !== null ? formatDate(receipt.submittedAt) : ''}
                            {receipt.layawayCode !== null ? <span className="whitespace-nowrap">{` · ${receipt.layawayCode}`}</span> : null}
                        </span>
                        <Badge tone={RECEIPT_TONES[receipt.status]}>{receipt.statusLabel}</Badge>
                    </span>
                    {receipt.paidToAccount !== null ? <span className="text-ink-3 text-xs">A {receipt.paidToAccount}</span> : null}
                    {receipt.rejectionReason !== null ? (
                        <span className="text-xs">
                            Rechazado{receipt.reviewedBy !== null ? ` por ${receipt.reviewedBy}` : ''}: {receipt.rejectionReason}
                        </span>
                    ) : null}
                    <FileLink
                        url={receipt.fileUrl}
                        title="Comprobante del cliente"
                        className="text-primary-ink self-start text-xs font-semibold hover:underline"
                    >
                        Ver el archivo
                    </FileLink>
                </li>
            ))}
        </ul>
    );
}
