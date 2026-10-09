import { formatDay } from '@/features/receipts/lib/inbox-format';
import { type CustomerReceiptRow } from '@/types/customers';
import { Link } from '@inertiajs/react';

/**
 * Los comprobantes del cliente (`REQ-BOF-041` RB-4, RB-6, RB-7): con su estado,
 * su cuenta de cobro declarada, el motivo íntegro de un rechazo y quién lo
 * resolvió, y el archivo con su enlace firmado de 15 minutos.
 */
export function CustomerReceipts({ receipts }: { receipts: readonly CustomerReceiptRow[] }) {
    if (receipts.length === 0) {
        return <p className="text-sm opacity-70">Este cliente no ha reportado pagos.</p>;
    }

    return (
        <ul className="divide-base-200 divide-y">
            {receipts.map((receipt) => (
                <li key={receipt.id} className="flex flex-col gap-1 py-3 text-sm">
                    <span className="flex flex-wrap gap-2">
                        <Link href={receipt.url} className="link">
                            {receipt.declared}
                        </Link>
                        <span className="opacity-70">
                            {receipt.submittedAt !== null ? formatDay(receipt.submittedAt) : ''}
                            {receipt.layawayCode !== null ? ` · ${receipt.layawayCode}` : ''}
                        </span>
                        <span className="font-medium">{receipt.statusLabel}</span>
                    </span>
                    {receipt.paidToAccount !== null ? <span className="text-xs opacity-70">A {receipt.paidToAccount}</span> : null}
                    {receipt.rejectionReason !== null ? (
                        <span className="text-xs">
                            Rechazado{receipt.reviewedBy !== null ? ` por ${receipt.reviewedBy}` : ''}: {receipt.rejectionReason}
                        </span>
                    ) : null}
                    <a href={receipt.fileUrl} target="_blank" rel="noreferrer" className="link text-xs">
                        Ver el archivo
                    </a>
                </li>
            ))}
        </ul>
    );
}
