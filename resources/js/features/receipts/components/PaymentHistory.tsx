import { Badge } from '@/components/ui/badge';
import { IconChip } from '@/components/ui/icon-chip';
import { formatRate } from '@/features/receipts/lib/inbox-format';
import { type PaymentRow } from '@/types/receipt';
import { cilCash } from '@coreui/icons';

interface PaymentHistoryProps {
    payments: readonly PaymentRow[];
    empty: string;
}

function appliedTo(payment: PaymentRow): string | null {
    const parts: string[] = [];
    const numbers = payment.appliedTo;

    if (payment.downPayment !== null) {
        parts.push(`inicial de ${payment.downPayment}`);
    }

    if (numbers.length === 1) {
        parts.push(`cuota ${numbers[0]}`);
    } else if (numbers.length > 1) {
        parts.push(`cuotas ${numbers.slice(0, -1).join(', ')} y ${numbers[numbers.length - 1]}`);
    }

    if (payment.unallocated !== null) {
        parts.push(`${payment.unallocated} sin asignar`);
    }

    return parts.length === 0 ? null : parts.join(', ');
}

export function PaymentHistory({ payments, empty }: PaymentHistoryProps) {
    if (payments.length === 0) {
        return <p className="text-ink-2 text-sm">{empty}</p>;
    }

    return (
        <ul className="divide-line flex flex-col divide-y" aria-label="Pagos">
            {payments.map((payment, index) => (
                <li key={`${payment.settledAt}-${index}`} className="flex items-start gap-3 py-3 text-[13px] first:pt-0 last:pb-0">
                    <IconChip icon={cilCash} tone="success" />
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                            <span className="text-sm font-bold">{payment.amountUsd.usd}</span>
                            <span className="text-ink-3 text-xs">{payment.settledAt}</span>
                        </div>
                        <p className="text-ink-2">
                            {payment.declaredAmount}
                            {payment.rateApplied !== null
                                ? ` · tasa ${formatRate(payment.rateApplied)} (${payment.rateSourceLabel}), la del día del pago`
                                : ''}
                            {appliedTo(payment) !== null ? ` · ${appliedTo(payment)}` : ''}
                        </p>
                        <div className="text-ink-3 flex flex-wrap items-center gap-2 text-xs">
                            <span>{payment.method}</span>
                            {payment.bankReference !== null ? <span>{payment.bankReference}</span> : null}
                            {payment.attachmentUrl !== null ? (
                                <a href={payment.attachmentUrl} target="_blank" rel="noopener noreferrer" className="link link-primary">
                                    Ver comprobante
                                </a>
                            ) : null}
                            {payment.registeredByBusiness ? (
                                <Badge tone="info" outline>
                                    Registrado por el negocio
                                </Badge>
                            ) : null}
                        </div>
                        {payment.note !== null ? <p className="text-ink-2 text-xs">{payment.note}</p> : null}
                    </div>
                </li>
            ))}
        </ul>
    );
}
