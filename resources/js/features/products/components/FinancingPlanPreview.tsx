import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type FinancingPlanView } from '@/types/product';

interface FinancingPlanPreviewProps {
    plan: FinancingPlanView | null;
}

function days(count: number): string {
    return count === 1 ? 'día' : 'días';
}

export function FinancingPlanPreview({ plan }: FinancingPlanPreviewProps) {
    if (plan === null) {
        return (
            <div className="bg-surface-2 text-ink-2 rounded-xl p-4 text-sm">
                Completa el precio, la cuota inicial y el número de cuotas para ver el plan de pago. La inicial tiene que ser menor que el precio.
            </div>
        );
    }

    const installments = Array.from({ length: plan.installmentsCount }, (_, index) => index + 1);
    const requiresDownPayment = Number(plan.downPayment.cents) > 0;

    return (
        <div className="bg-primary-soft flex flex-col gap-3 rounded-xl p-4">
            <p className="text-eyebrow text-primary-ink">Vista previa del plan</p>

            <p className="text-ink-2 text-sm">
                {requiresDownPayment ? (
                    <>
                        <MoneyLabel money={plan.downPayment} compact className="text-base-content text-[22px] font-extrabold" /> de inicial +{' '}
                    </>
                ) : (
                    <>Sin inicial: </>
                )}
                <span className="text-base-content text-[22px] font-extrabold">{plan.installmentsCount} ×</span>{' '}
                <MoneyLabel money={plan.installmentAmount} compact className="text-base-content text-[22px] font-extrabold" /> cada{' '}
                {plan.frequencyDays} {days(plan.frequencyDays)}
            </p>

            <ol aria-hidden="true" className="grid grid-cols-[repeat(auto-fill,minmax(76px,1fr))] gap-1.5">
                {requiresDownPayment ? (
                    <li className="bg-base-100 flex flex-col items-center rounded-lg px-1.5 py-1.5 text-center">
                        <span className="text-xs font-bold">Inicial</span>
                        <span className="text-ink-3 text-[11px]">Hoy</span>
                    </li>
                ) : null}
                {installments.map((n) => (
                    <li key={n} className="bg-base-100 flex flex-col items-center rounded-lg px-1.5 py-1.5 text-center">
                        <span className="text-xs font-bold">{n}</span>
                        <span className="text-ink-3 text-[11px]">+{n * plan.frequencyDays} d</span>
                    </li>
                ))}
            </ol>

            {plan.hasUnevenLastInstallment ? (
                <p className="text-ink-2 text-xs">
                    Última cuota: <MoneyLabel money={plan.lastInstallmentAmount} className="font-semibold" />. El reparto no es exacto y el resto va a
                    la última, para que la suma cuadre al céntimo.
                </p>
            ) : null}

            <p className="text-ink-2 text-xs">
                Se financian <MoneyLabel money={plan.financedAmount} /> de un total de <MoneyLabel money={plan.total} />.
            </p>
        </div>
    );
}
