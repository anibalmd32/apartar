import { Badge } from '@/components/ui/badge';
import { formatRate } from '@/features/receipts/lib/inbox-format';
import { type PaymentRow } from '@/types/receipt';

/**
 * Los pagos de un apartado (`REQ-LAY-022` RB-1, RB-2, RB-7), los mismos para
 * el negocio y para el cliente.
 *
 * Cada pago con **la tasa del día en que se concilió** y su fuente: nunca se
 * reconvierte con la de hoy (RB-2), y lo dice. Un pago registrado por el
 * negocio va marcado y con su nota (RB-7).
 */

interface PaymentHistoryProps {
    payments: readonly PaymentRow[];
    empty: string;
}

/** En qué se fue el pago: la inicial, las cuotas y lo que sobró. */
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
        return <p className="text-sm opacity-70">{empty}</p>;
    }

    return (
        <ul className="flex flex-col gap-2" aria-label="Pagos">
            {payments.map((payment, index) => (
                <li key={`${payment.settledAt}-${index}`} className="border-base-300 flex flex-col gap-1 border-b pb-2 text-sm last:border-b-0">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <span className="font-medium">{payment.amountUsd.usd}</span>
                        <span className="opacity-70">{payment.settledAt}</span>
                    </div>
                    <p>
                        {payment.declaredAmount}
                        {payment.rateApplied !== null
                            ? ` · tasa ${formatRate(payment.rateApplied)} (${payment.rateSourceLabel}), la del día del pago`
                            : ''}
                        {appliedTo(payment) !== null ? ` · ${appliedTo(payment)}` : ''}
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-xs opacity-70">{payment.method}</span>
                        {payment.registeredByBusiness ? (
                            <Badge tone="info" outline>
                                Registrado por el negocio
                            </Badge>
                        ) : null}
                    </div>
                    {payment.note !== null ? <p className="text-xs opacity-70">{payment.note}</p> : null}
                </li>
            ))}
        </ul>
    );
}
