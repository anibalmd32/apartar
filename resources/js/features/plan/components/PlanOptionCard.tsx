import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { PlanChangeSummary } from '@/features/plan/components/PlanChangeSummary';
import { usePlanLimits } from '@/features/plan/hooks/usePlanLimits';
import { formatLimit } from '@/lib/plan';
import { CHANGE_PLAN_URL, type PlanOption, type UsageMetric } from '@/types/plan';
import { useForm } from '@inertiajs/react';

/** Las cifras que se comparan en la tarjeta: las que más pesan al elegir. */
const HEADLINE_METRICS: readonly UsageMetric[] = ['products', 'catalogs', 'active_layaways', 'users'];

/**
 * Un plan del escaparate como opción de cambio (`REQ-SUB-005`, `T-068`): sus
 * techos, lo que pasaría al elegirlo (`PlanChangeSummary`) y el botón.
 *
 * Una bajada que deja algo por encima de un techo pide **confirmación
 * explícita** (SP-2) con el interruptor; el servidor la vuelve a exigir, así
 * que esto solo evita el viaje. Quien no gobierna el plan ve las opciones pero
 * no el botón (RB-1): la policy diría `403`.
 */
export function PlanOptionCard({ option, canChange, timeZone }: { option: PlanOption; canChange: boolean; timeZone?: string }) {
    const plan = usePlanLimits();
    const { data, setData, post, processing, errors } = useForm<{ plan: string; confirmed: boolean }>({ plan: option.code, confirmed: false });
    const { preview } = option;
    const needsConfirmation = preview.direction === 'downgrade' && (preview.excesses.length > 0 || preview.productsPerCatalog !== null);
    const action = preview.direction === 'upgrade' ? `Subir a ${option.name}` : `Bajar a ${option.name}`;

    return (
        <article className="card bg-base-100 shadow-sm">
            <div className="card-body gap-3">
                <header className="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 className="card-title text-base">{option.name}</h3>
                    {option.isCurrent ? <span className="badge badge-primary">Tu plan</span> : null}
                    {option.isScheduled ? <span className="badge badge-outline">Programado</span> : null}
                </header>

                <ul className="flex flex-col gap-1 text-sm">
                    {HEADLINE_METRICS.map((metric) => (
                        <li key={metric}>
                            {formatLimit(option.limits[metric])} <span>{plan.metricLabel(metric)}</span>
                        </li>
                    ))}
                </ul>

                {canChange && !option.isCurrent && !option.isScheduled ? (
                    <form
                        className="flex flex-col gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            post(CHANGE_PLAN_URL, { preserveScroll: true });
                        }}
                    >
                        <PlanChangeSummary option={option} timeZone={timeZone} />

                        {needsConfirmation ? (
                            <CheckboxField
                                label="Entiendo lo que queda por encima del límite"
                                checked={data.confirmed}
                                onChange={(event) => setData('confirmed', event.target.checked)}
                                error={errors.confirmed}
                            />
                        ) : null}

                        {errors.plan ? <p className="text-error text-sm">{errors.plan}</p> : null}

                        <Button
                            type="submit"
                            variant={preview.direction === 'upgrade' ? 'primary' : 'secondary'}
                            loading={processing}
                            disabled={needsConfirmation && !data.confirmed}
                        >
                            {action}
                        </Button>
                    </form>
                ) : null}
            </div>
        </article>
    );
}
