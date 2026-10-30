import { SectionCard } from '@/components/ui/section-card';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type BackofficeLayawayCreateProps } from '@/types/layaway-backoffice';

function frequencyLabel(days: number): string {
    return days === 1 ? 'cada día' : `cada ${days} días`;
}

export function BackofficePlanCard({ plan }: { plan: BackofficeLayawayCreateProps['plan'] }) {
    return (
        <SectionCard title="Plan del apartado" aria-label="Plan del apartado">
            {plan === null ? (
                <p className="text-ink-3 text-[13px]">Elige el producto para ver el total, la inicial y las cuotas.</p>
            ) : (
                <dl className="grid grid-cols-2 gap-x-3 gap-y-2.5 text-[13px]">
                    <dt className="text-ink-3">Producto</dt>
                    <dd className="text-right font-semibold">
                        {plan.quantity > 1 ? `${plan.quantity} × ` : ''}
                        {plan.productName}
                    </dd>
                    <dt className="text-ink-3">Total</dt>
                    <dd className="text-right font-bold">
                        <MoneyLabel money={plan.total} />
                    </dd>
                    <dt className="text-ink-3">Inicial</dt>
                    <dd className="text-right font-semibold">{plan.requiresDownPayment ? <MoneyLabel money={plan.downPayment} /> : 'Sin inicial'}</dd>
                    <dt className="text-ink-3">Cuotas</dt>
                    <dd className="text-right font-semibold">
                        {plan.installmentAmount === null ? (
                            'Sin cuotas'
                        ) : (
                            <>
                                {plan.installmentsCount} × <MoneyLabel money={plan.installmentAmount} />
                            </>
                        )}
                    </dd>
                    {plan.lastInstallmentAmount !== null ? (
                        <>
                            <dt className="text-ink-3">Última cuota</dt>
                            <dd className="text-right font-semibold">
                                <MoneyLabel money={plan.lastInstallmentAmount} />
                            </dd>
                        </>
                    ) : null}
                    <dt className="text-ink-3">Frecuencia</dt>
                    <dd className="text-right font-semibold">{frequencyLabel(plan.frequencyDays)}</dd>
                    <dt className="text-ink-3">Al crearlo</dt>
                    <dd className="text-right font-semibold">{plan.requiresDownPayment ? 'Esperando inicial' : 'Activo, sin inicial'}</dd>
                </dl>
            )}
        </SectionCard>
    );
}
