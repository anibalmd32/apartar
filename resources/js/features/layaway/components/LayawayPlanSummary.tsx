import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type LayawayPlanView } from '@/types/layaway';

function frequencyLabel(days: number): string {
    return days === 1 ? 'cada día' : `cada ${days} días`;
}

interface LayawayPlanSummaryProps {
    plan: LayawayPlanView;
    stale?: boolean;
}

export function LayawayPlanSummary({ plan, stale = false }: LayawayPlanSummaryProps) {
    return (
        <section
            aria-labelledby="plan-heading"
            aria-busy={stale}
            className={`bg-base-100 rounded-box flex flex-col gap-3 p-4 ${stale ? 'opacity-60' : ''}`}
        >
            <h2 id="plan-heading" className="font-semibold">
                Tu plan de pago
            </h2>

            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt className="opacity-70">Total</dt>
                <dd>
                    <MoneyLabel money={plan.total} className="font-semibold" />
                    {plan.quantity > 1 ? <span className="ml-1 opacity-70">({plan.quantity} unidades)</span> : null}
                </dd>

                <dt className="opacity-70">Inicial</dt>
                <dd>
                    <MoneyLabel money={plan.downPayment} className="font-medium" />
                </dd>

                {plan.installmentAmount !== null ? (
                    <>
                        <dt className="opacity-70">Cuotas</dt>
                        <dd>
                            {plan.installmentsCount} {plan.installmentsCount === 1 ? 'cuota' : 'cuotas'} de{' '}
                            <MoneyLabel money={plan.installmentAmount} className="font-medium" /> {frequencyLabel(plan.frequencyDays)}
                        </dd>
                    </>
                ) : null}

                {plan.lastInstallmentAmount !== null ? (
                    <>
                        <dt className="opacity-70">Última cuota</dt>
                        <dd>
                            <MoneyLabel money={plan.lastInstallmentAmount} className="font-medium" />
                        </dd>
                    </>
                ) : null}
            </dl>

            {plan.installments.length > 0 ? (
                <details className="text-sm">
                    <summary className="cursor-pointer">Fechas estimadas de las cuotas</summary>
                    <p className="mt-2 text-xs opacity-70">Cuentan desde que el negocio apruebe tu inicial; si la pagas otro día, se corren.</p>
                    <ol className="mt-2 flex flex-col gap-1">
                        {plan.installments.map((installment) => (
                            <li key={installment.number} className="flex justify-between gap-4">
                                <span>
                                    Cuota {installment.number} · {installment.estimatedDueDate}
                                </span>
                                <MoneyLabel money={installment.amount} />
                            </li>
                        ))}
                    </ol>
                </details>
            ) : null}
        </section>
    );
}
