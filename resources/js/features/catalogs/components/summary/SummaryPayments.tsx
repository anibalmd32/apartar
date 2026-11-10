import { Badge } from '@/components/ui/badge';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type SummaryPaymentRow } from '@/types/catalog-summary';
import { Link } from '@inertiajs/react';

interface SummaryPaymentsProps {
    payments: readonly SummaryPaymentRow[];
    truncated: boolean;
    withParallel: boolean;
}

export function SummaryPayments({ payments, truncated, withParallel }: SummaryPaymentsProps) {
    if (payments.length === 0) {
        return <p className="text-ink-2 text-sm">No hubo pagos en este periodo.</p>;
    }

    return (
        <div className="flex flex-col gap-2">
            <ul className="divide-line flex flex-col divide-y" aria-label="Pagos del periodo">
                {payments.map((payment) => (
                    <li key={payment.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2.5 text-sm first:pt-0 last:pb-0">
                        <span className="flex min-w-0 flex-1 basis-56 flex-col">
                            <span className="truncate font-semibold">{payment.customer ?? 'Cliente sin nombre'}</span>
                            <span className="text-ink-3 truncate text-xs">
                                {payment.date} ·{' '}
                                <Link href={payment.layawayUrl} className="hit-area hover:underline">
                                    {payment.layawayCode}
                                </Link>{' '}
                                · {payment.product}
                            </span>
                        </span>
                        <span className="flex flex-col items-end">
                            <MoneyLabel money={payment.amount} className="font-semibold" compact />
                            {payment.declared !== payment.amount.usd ? <span className="text-ink-3 text-xs">{payment.declared}</span> : null}
                        </span>
                        {withParallel ? (
                            <span className="flex basis-40 flex-col items-end gap-0.5">
                                <span className="text-xs">{payment.parallel === null ? 'Sin tasa paralela' : `${payment.parallel} en paralelo`}</span>
                                {payment.safeguard !== null ? (
                                    <Badge tone="success" outline>
                                        {payment.safeguard}
                                    </Badge>
                                ) : (
                                    <Badge tone="warning" outline>
                                        Sin registrar
                                    </Badge>
                                )}
                            </span>
                        ) : null}
                    </li>
                ))}
            </ul>
            {truncated ? <p className="text-ink-3 text-xs">Se muestran los 100 pagos más recientes del periodo.</p> : null}
        </div>
    );
}
