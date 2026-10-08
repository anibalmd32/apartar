import { Badge, type BadgeTone } from '@/components/ui/badge';
import { formatDay } from '@/features/receipts/lib/inbox-format';
import { type ReceiptRow, type ReceiptStatus } from '@/types/receipt';

/**
 * Los comprobantes que reportó el cliente (`REQ-LAY-022` RB-3 a RB-8).
 *
 * Cada uno con su estado; el rechazado, con el motivo **tal como lo escribió
 * el negocio** (RB-3), que es lo que hace falta para corregir. Un reintento
 * dice a qué comprobante sustituye (RB-5), y cada uno se puede volver a abrir
 * con su enlace firmado de pocos minutos (RB-4): si caduca, recargar la
 * página trae otro.
 */

const TONES: Readonly<Record<ReceiptStatus, BadgeTone>> = {
    pending_review: 'info',
    approved: 'success',
    rejected: 'error',
};

interface ReceiptHistoryProps {
    receipts: readonly ReceiptRow[];
}

export function ReceiptHistory({ receipts }: ReceiptHistoryProps) {
    if (receipts.length === 0) {
        return <p className="text-sm opacity-70">Todavía no has reportado pagos de este apartado.</p>;
    }

    const dayOf = new Map(receipts.map((receipt) => [receipt.id, receipt.submittedAt]));

    return (
        <ul className="flex flex-col gap-2" aria-label="Comprobantes">
            {receipts.map((receipt) => {
                const replaced = receipt.replacesReceiptId === null ? undefined : dayOf.get(receipt.replacesReceiptId);

                return (
                    <li key={receipt.id} className="border-base-300 flex flex-col gap-1 border-b pb-2 text-sm last:border-b-0">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="font-medium">{receipt.declaredAmount}</span>
                            <Badge tone={TONES[receipt.status]}>{receipt.statusLabel}</Badge>
                        </div>
                        <p className="opacity-70">
                            {formatDay(receipt.submittedAt)} · {receipt.method}
                            {receipt.reference !== null ? ` · Ref. ${receipt.reference}` : ''}
                        </p>
                        {receipt.paidToAccount !== null ? <p className="text-xs opacity-70">Pagado a {receipt.paidToAccount}</p> : null}
                        {replaced !== undefined ? <p className="text-xs opacity-70">Reintento del comprobante del {formatDay(replaced)}</p> : null}
                        {receipt.rejectionReason !== null ? <p className="text-error text-xs">Motivo: {receipt.rejectionReason}</p> : null}
                        <a href={receipt.fileUrl} target="_blank" rel="noopener noreferrer" className="link text-xs">
                            Ver el comprobante
                        </a>
                    </li>
                );
            })}
        </ul>
    );
}
